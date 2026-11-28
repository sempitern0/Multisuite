"""Descargas gateway with the real MeTube image (docs/DOWNLOADS.md).

A stand-in portal answers the gateway's questions; the test checks that nothing of MeTube is
reachable without the portal's session, that the launch sets that session on the gateway's
origin, and that MeTube's page and its live connection work with it.
"""
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid

root = Path(__file__).resolve().parents[1]
project = "downloads-ci-" + uuid.uuid4().hex[:8]
accounts = project + "-accounts"
volume = project + "-staging"
portal = project + "-portal"


def run(*args, check=True):
    result = subprocess.run(list(args), text=True, capture_output=True, cwd=root)
    if check and result.returncode != 0:
        raise AssertionError(" ".join(args) + "\n" + result.stdout + result.stderr)
    return result


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


opener = urllib.request.build_opener(NoRedirect)


def request(url, cookie=None, data=None):
    req = urllib.request.Request(url, data=data, method="POST" if data is not None else "GET")
    if cookie:
        req.add_header("Cookie", "multisuite_downloads=" + cookie)
    try:
        with opener.open(req, timeout=10) as response:
            return response.status, dict(response.headers), response.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as error:
        return error.code, dict(error.headers), error.read().decode("utf-8", "replace")


PORTAL_CONF = """server {
    listen 80;
    absolute_redirect off;
    location = /api/downloads/check { if ($http_x_multisuite_downloads = "valid") { return 204; } return 401; }
    location = /api/downloads/session { add_header Set-Cookie "multisuite_downloads=valid; Path=/; HttpOnly"; return 303 /; }
    location = /api/downloads/portal { return 302 http://portal.test/services/downloads; }
}
"""

with tempfile.TemporaryDirectory(prefix="downloads-ci-") as directory:
    port = free_port()
    env_file = Path(directory) / "downloads.env"
    settings = (root / "downloads.env.example").read_text()
    settings = re.sub(r"^DOWNLOADS_PROJECT_NAME=.*$", "DOWNLOADS_PROJECT_NAME=" + project, settings, flags=re.M)
    settings = re.sub(r"^DOWNLOADS_PORT=.*$", "DOWNLOADS_PORT=" + str(port), settings, flags=re.M)
    settings += "DOWNLOADS_ACCOUNTS_NETWORK=" + accounts + "\nDOWNLOADS_STAGING_VOLUME=" + volume + "\n"
    env_file.write_text(settings)
    conf = Path(directory) / "portal.conf"
    conf.write_text(PORTAL_CONF)
    os.chmod(directory, 0o755)
    os.chmod(conf, 0o644)
    compose = ["docker", "compose", "--env-file", str(env_file), "-f", "compose.downloads.yaml"]
    base = "http://127.0.0.1:" + str(port)
    passed = False
    try:
        run("docker", "network", "create", accounts)
        run("docker", "volume", "create", volume)
        run("docker", "run", "-d", "--name", portal, "--network", accounts, "--network-alias", "multisuite-portal-web",
            "-v", str(conf) + ":/etc/nginx/conf.d/default.conf:ro", "nginx:1.27-alpine")
        run(*compose, "up", "-d", "--wait", "--wait-timeout", "180")

        status, headers, _ = request(base + "/")
        assert status == 302 and headers.get("Location") == "/_multisuite/portal", (status, headers)
        status, headers, _ = request(base + "/_multisuite/portal")
        assert status == 302 and headers.get("Location") == "http://portal.test/services/downloads", (status, headers)
        assert request(base + "/", cookie="forged")[0] == 302, "A session the portal does not confirm is refused"
        assert request(base + "/download/anything", cookie="forged")[0] == 302, "Downloaded files need the session too"
        assert request(base + "/_multisuite/auth", cookie="valid")[0] == 404, "The internal check is not reachable"
        assert request(base + "/_multisuite/launch")[0] in (403, 405), "Launch only accepts the portal's form"

        status, headers, _ = request(base + "/_multisuite/launch", data=b"ticket=x")
        assert status == 303 and headers.get("Location") == "/" and "multisuite_downloads=valid" in headers.get("Set-Cookie", ""), (status, headers)

        deadline = time.time() + 120
        while True:
            status, _, body = request(base + "/", cookie="valid")
            if status == 200 and "metube" in body.lower():
                break
            if time.time() > deadline:
                raise AssertionError("MeTube did not answer through the gateway: " + str(status))
            time.sleep(3)
        status, _, body = request(base + "/socket.io/?EIO=4&transport=polling", cookie="valid")
        assert status == 200 and body.startswith("0"), (status, body[:80])
        passed = True
        print("PASS Descargas gateway: portal session required, launch cookie and MeTube reachable")
    finally:
        if not passed:
            logs = run(*compose, "logs", "--tail", "80", check=False)
            print(logs.stdout[-6000:], logs.stderr[-2000:])
        run(*compose, "down", "--remove-orphans", check=False)
        run("docker", "rm", "-f", portal, check=False)
        run("docker", "volume", "rm", volume, check=False)
        run("docker", "network", "rm", accounts, check=False)
