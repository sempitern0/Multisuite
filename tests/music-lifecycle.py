"""Exercise real Make dispatch with a fake Docker CLI, without touching user data."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="multisuite-music-") as directory:
    work = Path(directory)
    shutil.copy(root / "Makefile", work / "Makefile")
    docker = work / "docker"
    docker.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$CALL_LOG"\ncase "$*" in "network inspect multisuite-accounts") exit "${NETWORK_MISSING:-0}";; "network create multisuite-accounts") exit "${NETWORK_CREATE_EXIT:-0}";; *compose.music.yaml*) exit "${MUSIC_EXIT:-0}";; esac\n')
    docker.chmod(0o755)
    shutil.copytree(root / "scripts", work / "scripts")
    log = work / "calls"
    env = dict(os.environ, PATH=str(work) + os.pathsep + os.environ["PATH"], CALL_LOG=str(log))
    env.pop("OS", None)
    env.pop("MAKEFLAGS", None)
    def run(target, *options, fail=False):
        log.write_text("")
        result = subprocess.run(["make", target, "HOST_PREPARE=true", "PREFLIGHT_SCRIPT=true", "STATUS_SCRIPT=true", "HTTP_CHECK=true", *options], cwd=work, env=env, capture_output=True, text=True)
        assert (result.returncode != 0) == fail, result.stdout + result.stderr
        return log.read_text().splitlines()
    assert all("compose.music.yaml" not in call for call in run("up"))
    (work / "music.env").write_text("# configured\n")
    for target, command in [("up", "up -d"), ("up-detached", "up -d"), ("start", "up -d"), ("stop", "stop"), ("down", "down"), ("up-wait", "up -d --wait --wait-timeout 120"), ("up-logs", "up -d")]:
        calls = run(target)
        assert f"compose --env-file music.env -f compose.music.yaml -f deploy/compose.music-accounts.example.yaml {command}" in calls, calls
        assert all("-v" not in call.split() for call in calls), calls
    calls = run("up")
    assert calls[0] == "network inspect multisuite-accounts", calls
    portal = "compose.portal-accounts.example.yaml"
    assert any(portal in c and c.endswith(" up -d") for c in calls), calls
    (work / "compose.override.yaml").write_text("# host override")
    assert any("-f compose.override.yaml -f deploy/compose.portal-accounts.example.yaml" in c for c in run("up"))
    assert any(portal in c and c.endswith(" up -d") for c in run("start"))
    assert all("--profile" not in c for c in run("up"))
    assert all("network" not in c for c in run("up", "MUSIC_AUTO=0"))
    calls = run("restart")
    assert sum("compose.music.yaml" in call for call in calls) == 2, calls
    assert all("compose.music.yaml" not in call for call in run("up", "MUSIC_AUTO=0"))
    calls = run("up")
    assert any("-f deploy/compose.nas-dev.yaml" in c and c.endswith(" up -d") for c in calls), calls
    assert any(c.endswith("exec -T app php artisan portal:dev-bootstrap") for c in calls), calls
    assert any(c.endswith("exec -T app php artisan portal:dev-bootstrap") for c in run("up-wait")), "up-wait bootstrap"
    # Storage overlay and bootstrap are independent switches.
    calls = run("up", "NAS_DEV=0")
    assert all("nas-dev" not in c for c in calls), calls
    assert any(c.endswith("exec -T app php artisan portal:dev-bootstrap") for c in calls), calls
    calls = run("up", "DEV_BOOTSTRAP=0")
    assert any("nas-dev" in c for c in calls) and all("dev-bootstrap" not in c for c in calls), calls
    calls = run("up", "NAS_DEV=0", "MUSIC_AUTO=0", "DEV_BOOTSTRAP=0")
    assert "compose up -d" in calls and all("dev-bootstrap" not in c for c in calls), calls
    assert any(c.endswith("exec -T app php artisan portal:dev-bootstrap") for c in run("rebuild", "HOST_PREPARE=true", "SETUP_SCRIPT=true")), "rebuild bootstrap"
    env["NETWORK_MISSING"] = "1"
    calls = run("up")
    assert calls[:2] == ["network inspect multisuite-accounts", "network create multisuite-accounts"], calls
    env["NETWORK_CREATE_EXIT"] = "1"
    calls = run("up", fail=True)
    assert all(not c.startswith("compose ") for c in calls), calls
    env.pop("NETWORK_MISSING")
    env.pop("NETWORK_CREATE_EXIT")
    env["MUSIC_EXIT"] = "7"
    run("up", fail=True)
print("Make lifecycle: music configured/unconfigured, dev NAS overlay/bootstrap, opt-outs, aliases, restart and failure propagation passed.")
