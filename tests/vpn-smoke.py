"""Real private network test: Headscale, the NAS node, the entry and a person's device.

Uses compose.vpn.yaml and scripts/vpn.sh exactly as `make vpn-*` does, with disposable names:
the NAS joins with a tagged single-use key, a person's device joins with its own key, reaches the
portal by its private name (DNS from Headscale), cannot reach other ports of the NAS (policy) and
loses access when the operator removes it.
"""
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import uuid

root = Path(__file__).resolve().parents[1]
project = "vpn-ci-" + uuid.uuid4().hex[:8]
accounts = project + "-accounts"
portal = project + "-portal"
client = project + "-client"
host = "multisuite.multisuite.internal"


def run(*args, env=None, check=True):
    result = subprocess.run(list(args), text=True, capture_output=True, env=env, cwd=root)
    if check and result.returncode != 0:
        raise AssertionError(" ".join(args) + "\n" + result.stdout + result.stderr)
    return result


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def eventually(check, what, timeout=90):
    deadline = time.time() + timeout
    last = None
    while time.time() < deadline:
        try:
            value = check()
            if value:
                return value
        except AssertionError as error:
            last = error
        time.sleep(3)
    raise AssertionError(what + (": " + str(last) if last else ""))


with tempfile.TemporaryDirectory(prefix="vpn-ci-") as directory:
    env_file = Path(directory) / "vpn.env"
    settings = (root / "vpn.env.example").read_text()
    port = free_port()
    replacements = {
        "VPN_PROJECT_NAME": project,
        "VPN_CONTROL_PORT": str(port),
        "VPN_ACME_PORT": str(free_port()),
        "VPN_SERVER_URL": "http://127.0.0.1:" + str(port),
    }
    for key, value in replacements.items():
        settings = re.sub("^" + key + "=.*$", key + "=" + value, settings, flags=re.M)
    settings += "VPN_ACCOUNTS_NETWORK=" + accounts + "\n"
    env_file.write_text(settings)
    env = {**os.environ, "VPN_ENV_FILE": str(env_file)}
    compose = ["docker", "compose", "--env-file", str(env_file), "-f", "compose.vpn.yaml"]

    def vpn(*args, check=True):
        return run("sh", "scripts/vpn.sh", *args, env=env, check=check)

    def headscale(*args):
        return run(*compose, "exec", "-T", "headscale", "headscale", *args).stdout

    def from_client(*args, check=True):
        return run("docker", "exec", client, *args, check=check)

    passed = False
    cleanup = []
    try:
        run("docker", "network", "create", accounts)
        # Stand-in for the portal's web container (same alias as deploy/compose.portal-accounts).
        run("docker", "run", "-d", "--name", portal, "--network", accounts, "--network-alias", "multisuite-portal-web",
            "nginx:1.27-alpine", "sh", "-c",
            "printf 'server { listen 80; location / { return 200 \"portal $host\"; } }' > /etc/nginx/conf.d/default.conf && nginx -g 'daemon off;'")

        run(*compose, "up", "-d", "--wait", "--wait-timeout", "120", "headscale")
        run(*compose, "up", "-d")
        run("curl", "-fsS", "http://127.0.0.1:" + str(port) + "/health")

        joined = vpn("join").stdout
        node_ip = re.search(r"100\.\d+\.\d+\.\d+", joined)
        assert node_ip, joined
        node_ip = node_ip.group(0)
        assert "ya está" in vpn("join").stdout, "Joining twice must be harmless"
        nodes = json.loads(headscale("nodes", "list", "--output", "json"))
        assert any("tag:nas" in json.dumps(node) for node in nodes), "The NAS must be tagged tag:nas"

        assert "creada" in vpn("person", "ana").stdout
        assert "ya existe" in vpn("person", "ana").stdout
        assert vpn("person", "Ana López", check=False).returncode == 2, "Invalid names are refused"
        assert vpn("approve", "ana", "x; rm -rf /", check=False).returncode == 2, "Invalid codes are refused"
        key = vpn("key", "ana").stdout.strip().splitlines()[-1]
        assert re.fullmatch(r"[A-Za-z0-9_-]{16,}", key), key

        urls = vpn("urls").stdout
        assert "APP_URL=http://" + host in urls and "TRUSTED_PROXIES=" in urls, urls

        run("docker", "run", "-d", "--name", client, "--network", project + "_vpn",
            "--cap-add", "NET_ADMIN", "--device", "/dev/net/tun",
            "-e", "TS_USERSPACE=false", "-e", "TS_AUTHKEY=" + key, "-e", "TS_ACCEPT_DNS=true",
            "-e", "TS_STATE_DIR=/var/lib/tailscale", "-e", "TS_HOSTNAME=ana-movil",
            "-e", "TS_EXTRA_ARGS=--login-server=http://headscale:8080",
            os.environ.get("TAILSCALE_IMAGE", "tailscale/tailscale:v1.104.7"))
        eventually(lambda: from_client("tailscale", "ip", "-4", check=False).returncode == 0, "The device did not join")

        # The portal answers by its private name, resolved by the network's DNS.
        eventually(lambda: node_ip in from_client("nslookup", host, "100.100.100.100", check=False).stdout,
                   "The private name does not resolve to the NAS")
        body = eventually(lambda: from_client("wget", "-qO-", "-T", "5", "--header", "Host: " + host, "http://" + node_ip + "/", check=False).stdout,
                          "The portal is not reachable through the private network")
        assert "portal " + host in body, body

        # Only the web entry: Traefik's own port on the NAS stays closed to people.
        assert from_client("wget", "-qO-", "-T", "5", "http://" + node_ip + ":8082/ping", check=False).returncode != 0, \
            "The policy must only allow ports 80 and 443 of the NAS"

        # Removing the device ends its access.
        device = [node for node in json.loads(headscale("nodes", "list", "--output", "json"))
                  if "ana-movil" in json.dumps(node)]
        assert device, "Device not listed"
        vpn("remove", str(device[0]["id"]))
        eventually(lambda: from_client("wget", "-qO-", "-T", "4", "http://" + node_ip + "/", check=False).returncode != 0,
                   "A removed device must lose access", timeout=120)
        # HTTPS mode renders the same routes file: every private name on websecure with the resolver.
        tls_entry = project + "-tls"
        cleanup.append(tls_entry)
        run("docker", "run", "-d", "--name", tls_entry,
            "-e", "VPN_TLS=acme-dns", "-e", "VPN_PORTAL_HOST=" + host, "-e", "VPN_VIDEO_HOST=video.example.org",
            "-v", str(root / "docker/vpn-entry/routes.yml") + ":/etc/traefik/dynamic/routes.yml:ro",
            os.environ.get("TRAEFIK_IMAGE", "traefik:v3.8.0"),
            "--entrypoints.web.address=:80", "--entrypoints.websecure.address=:443",
            "--providers.file.directory=/etc/traefik/dynamic", "--api.insecure=true",
            "--certificatesresolvers.letsencrypt.acme.email=ci@example.org",
            "--certificatesresolvers.letsencrypt.acme.storage=/tmp/acme.json",
            "--certificatesresolvers.letsencrypt.acme.dnschallenge.provider=cloudflare")

        def router(name):
            out = run("docker", "exec", tls_entry, "wget", "-qO-", "http://127.0.0.1:8080/api/http/routers/" + name + "@file", check=False)
            return json.loads(out.stdout) if out.returncode == 0 else None

        for name in ("portal", "video"):
            loaded = eventually(lambda: router(name), "HTTPS route " + name + " not loaded", timeout=30)
            assert loaded["status"] == "enabled" and loaded["entryPoints"] == ["websecure"], loaded
            assert loaded["tls"]["certResolver"] == "letsencrypt", loaded
        assert router("music") is None, "An unset private name must not get a route"

        passed = True
        print("PASS private network: join, private names, policy, device removal and HTTPS routes")
    finally:
        if not passed:
            device_logs = run("docker", "logs", "--tail", "60", client, check=False)
            logs = run(*compose, "logs", "--tail", "80", check=False)
            print(device_logs.stdout[-3000:], device_logs.stderr[-3000:], logs.stdout[-6000:], logs.stderr[-2000:])
        run("docker", "rm", "-f", client, portal, *cleanup, check=False)
        run(*compose, "down", "-v", "--remove-orphans", check=False)
        run("docker", "network", "rm", accounts, check=False)
