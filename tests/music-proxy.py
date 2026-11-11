"""Exercise the real Nginx gateway: bridge isolation, BaseUrl and byte ranges."""
import json
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
    location / { return 404; }
  }
  server {
    listen 4533;
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
        media = request(url+"/music/media.bin", headers={"Range": "bytes=2-5"})
        assert media.status == 206
        assert media.read() == b"2345"
        assert media.headers["Content-Range"] == "bytes 2-5/10"
        print("Real gateway: static bridge, POST-only verification, credential isolation, BaseUrl and Range passed.")
    finally:
        for suffix in ["-gateway", "-upstream"]:
            subprocess.run(["docker", "rm", "-f", prefix+suffix], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(["docker", "network", "rm", prefix], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
