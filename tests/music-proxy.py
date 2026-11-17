"""Exercise the real Nginx gateway: bridge isolation, BaseUrl and byte ranges."""
import json
import re
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.request
import urllib.error
import uuid

root = Path(__file__).resolve().parents[1]
prefix = "multisuite-bridge-" + uuid.uuid4().hex[:8]
image = "nginx:1.27-alpine"
def docker(*args):
    return subprocess.check_output(["docker", *args], text=True).strip()
def request(url, data=None, headers=None):
    req = urllib.request.Request(url, data=data, headers=headers or {})
    try:
        return urllib.request.urlopen(req, timeout=5)
    except urllib.error.HTTPError as error:
        return error

with tempfile.TemporaryDirectory() as directory:
    fixture = Path(directory)
    fixture.chmod(0o755)
    socket_dir = fixture / "socket"
    socket_dir.mkdir()
    socket_dir.chmod(0o777)
    (fixture / "media.bin").write_bytes(b"0123456789")
    (fixture / "nginx.conf").write_text("""
events {}
http {
  server {
    listen 80;
    location = /api/music/session/verify {
      default_type application/json;
      return 200 '{"method":"$request_method","cookie":"$http_cookie","authorization":"$http_authorization"}';
    }
    location = /api/music/session/authorize {
      if ($http_x_music_grant != fixture) { return 403; }
      add_header X-Music-User "test-listener";
      return 204;
    }
    location / { return 404; }
  }
  server {
    listen 4533;
    location = /who { return 200 "identity=$http_x_multisuite_music_user;cookie=$http_cookie;jwt=$http_x_nd_authorization"; }
    location = /music/media.bin { alias /fixtures/media.bin; }
    location / { return 200 "$request_uri"; }
  }
}
""")
    try:
        docker("network", "create", prefix)
        docker("run", "-d", "--name", prefix+"-upstream", "--network", prefix,
               "--network-alias", "navidrome", "--network-alias", "multisuite-portal-web",
               "-v", str(fixture / "nginx.conf")+":/etc/nginx/nginx.conf:ro",
               "-v", str(fixture)+":/fixtures:ro", image)
        docker("run", "-d", "--name", prefix+"-gateway", "--network", prefix,
               "--user", "101:101", "--read-only", "--tmpfs", "/tmp", "--cap-drop", "ALL",
               "--security-opt", "no-new-privileges:true", "-p", "127.0.0.1::8080",
               "-v", str(root / "docker/music-proxy/nginx.conf")+":/etc/nginx/nginx.conf:ro",
               "-v", str(socket_dir)+":/run/music-identity",
               "-v", str(root / "docker/music-proxy/identity.conf")+":/etc/nginx/music-private/identity.conf:ro",
               "-v", str(root / "docker/music-proxy/public")+":/usr/share/nginx/html:ro",
               "--entrypoint", "nginx", image, "-g", "daemon off;")
        port = docker("port", prefix+"-gateway", "8080/tcp").split(":")[-1]
        url = "http://127.0.0.1:"+port
        for attempt in range(30):
            try:
                if request(url+"/_multisuite/health").status == 204:
                    break
            except OSError:
                pass
            time.sleep(1)
        else:
            raise AssertionError("Gateway did not become ready")
        page = request(url+"/_multisuite/session")
        assert page.status == 200
        assert "no-store" in page.headers["Cache-Control"]
        assert "frame-ancestors 'none'" in page.headers["Content-Security-Policy"]
        assert page.headers["Referrer-Policy"] == "no-referrer"
        assert request(url+"/_multisuite/session.mjs").headers["Content-Type"].startswith("application/javascript")
        assert request(url+"/_multisuite/unknown").status == 404
        assert request(url+"/_multisuite/verify").status == 403
        response = request(url+"/_multisuite/verify", b'{"ticket":"opaque"}',
                           {"Content-Type": "application/json", "Cookie": "secret=1", "Authorization": "Bearer secret"})
        assert json.load(response) == {"method": "POST", "cookie": "", "authorization": ""}
        assert response.headers.get("Set-Cookie") is None
        assert response.headers.get("Access-Control-Allow-Origin") is None
        proxied = request(url+"/music/app/?test=1")
        assert proxied.read() == b"/music/app/?test=1"
        assert proxied.headers.get("Content-Security-Policy") is None
        assert request(url+"/who", headers={"X-Multisuite-Music-User": "ignored"}).read() == b"identity=;cookie=;jwt="
        managed = {"Cookie": "ms_music=fixture", "X-ND-Authorization": "unused", "X-Multisuite-Music-User": "ignored"}
        rendered = json.loads(docker("compose", "--env-file", str(root / "music.env.example"),
                                     "-f", str(root / "compose.music.yaml"), "-f",
                                     str(root / "deploy/compose.music-accounts.example.yaml"), "config", "--format", "json"))
        logout_url = rendered["services"]["navidrome"]["environment"]["ND_EXTAUTH_LOGOUTURL"]
        gateway_ip = docker("inspect", "--format", "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}", prefix+"-gateway")
        docker("run", "-d", "--name", prefix+"-nav", "--network", prefix, "--network-alias", "navidrome-sso",
               "-e", "ND_EXTAUTH_LOGOUTURL="+logout_url,
               "-e", "ND_EXTAUTH_USERHEADER=X-Multisuite-Music-User",
               "-e", "ND_EXTAUTH_TRUSTEDSOURCES="+gateway_ip+"/32",
               "-p", "127.0.0.1::4533", "navidrome/navidrome:0.64.2")
        nav = "http://127.0.0.1:"+docker("port", prefix+"-nav", "4533/tcp").split(":")[-1]
        for attempt in range(30):
            try:
                if request(nav+"/ping").status == 200:
                    break
            except OSError:
                pass
            time.sleep(1)
        creds = {"username": "ci-operator", "password": "disposable-operator-password"}
        post = {"Content-Type": "application/json"}
        assert request(nav+"/auth/createAdmin", json.dumps(creds).encode(), post).status < 300
        operator = json.load(request(nav+"/auth/login", json.dumps(creds).encode(), post))
        listener_id = str(uuid.uuid4())
        listener = {"id": listener_id, "userName": "test-listener", "name": "Listener",
                    "password": "disposable-listener-password", "isAdmin": False}
        assert request(nav+"/api/user", json.dumps(listener).encode(),
                       {**post, "X-ND-Authorization": "Bearer "+operator["token"]}).status < 300
        lookup = ["curl", "--silent", "--show-error", "--unix-socket", str(socket_dir/"lookup.sock"),
                  "-H", "X-Music-Listener: test-listener"]
        remote = json.loads(subprocess.check_output([*lookup, "--fail", "http://localhost/api/user/"+listener_id]))
        assert (remote["id"], remote["userName"], remote["isAdmin"]) == (listener_id, "test-listener", False)
        assert subprocess.check_output([*lookup, "-o", "/dev/null", "-w", "%{http_code}", "http://localhost/auth/login"]) == b"404"
        assert request(url+"/_multisuite/native-ready", headers=managed).status == 409
        assert request(url+"/_multisuite/native-ready").status == 204
        reset = request(url+"/_multisuite/manual", b"", headers=managed)
        assert reset.status == 204 and any("ms_music=;" in c for c in reset.headers.get_all("Set-Cookie"))
        page = request(url+"/app/", headers=managed).read().decode()
        config_text = re.split(r"window\.__APP_CONFIG__\s*=\s*", page, maxsplit=1)[1]
        app_config = json.loads(json.JSONDecoder().raw_decode(config_text)[0])
        assert app_config["auth"]["id"] == listener_id
        assert app_config["auth"]["username"] == "test-listener" and not app_config["auth"]["isAdmin"]
        assert request(url+"/api/album", headers=managed).status == 200
        assert request(url+"/who", headers={"Cookie": "ms_music=expired"}).status == 403
        assert request(url+"/who", headers={**managed, "Sec-Fetch-Site": "cross-site"}).status == 403
        media = request(url+"/music/media.bin", headers={"Range": "bytes=2-5"})
        assert media.status == 206
        assert media.read() == b"2345"
        assert media.headers["Content-Range"] == "bytes 2-5/10"
        print("Real gateway: static bridge, POST-only verification, credential isolation, BaseUrl and Range passed.")
    finally:
        for suffix in ["-nav", "-gateway", "-upstream"]:
            subprocess.run(["docker", "rm", "-f", prefix+suffix], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(["docker", "network", "rm", prefix], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
