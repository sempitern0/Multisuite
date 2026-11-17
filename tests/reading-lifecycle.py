"""Lectura (Kavita) follows the same Make dispatch as music, with a fake Docker CLI."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="multisuite-reading-") as directory:
    work = Path(directory)
    shutil.copy(root / "Makefile", work / "Makefile")
    docker = work / "docker"
    docker.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$CALL_LOG"\nexit 0\n')
    docker.chmod(0o755)
    shutil.copytree(root / "scripts", work / "scripts")
    log = work / "calls"
    env = dict(os.environ, PATH=str(work) + os.pathsep + os.environ["PATH"], CALL_LOG=str(log))
    env.pop("OS", None)
    env.pop("MAKEFLAGS", None)

    def run(target, *options):
        log.write_text("")
        result = subprocess.run(["make", target, "HOST_PREPARE=true", "PREFLIGHT_SCRIPT=true", "STATUS_SCRIPT=true", "HTTP_CHECK=true", *options], cwd=work, env=env, capture_output=True, text=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return log.read_text().splitlines()

    reading = "compose --env-file reading.env -f compose.reading.yaml -f deploy/compose.reading-accounts.example.yaml -f deploy/compose.reading-media.dev.yaml"
    assert all("compose.reading.yaml" not in c for c in run("up"))
    (work / "reading.env").write_text("# configured\n")
    for target, command in [("up", "up -d"), ("start", "up -d"), ("stop", "stop"), ("down", "down"), ("up-wait", "up -d --wait --wait-timeout 120"), ("up-logs", "up -d")]:
        calls = run(target)
        assert f"{reading} {command}" in calls, (target, calls)
    calls = run("up")
    assert calls[0] == "network inspect multisuite-accounts", calls
    portal = [c for c in calls if c.endswith(" up -d") and "compose.yaml" in c]
    assert portal and "compose.portal-accounts.example.yaml" in portal[0] and "compose.portal-reading.example.yaml" in portal[0], calls
    # Reading alone never starts the music stack, and READING_AUTO=0 opts out completely.
    assert all("compose.music.yaml" not in c for c in calls), calls
    calls = run("up", "READING_AUTO=0")
    assert all("reading" not in c and "network" not in c for c in calls), calls
    # Both services: one accounts overlay for the portal, both stacks started.
    (work / "music.env").write_text("# configured\n")
    calls = run("up")
    assert any("compose.music.yaml" in c for c in calls) and any("compose.reading.yaml" in c for c in calls), calls
    portal = [c for c in calls if c.endswith(" up -d") and "compose.yaml" in c]
    assert portal[0].count("compose.portal-accounts.example.yaml") == 1, calls
    assert run("reading-status") == [f"{reading} ps"]
    # Real disks (NAS_DEV=0): no development media mount.
    assert all("media.dev" not in c for c in run("up", "NAS_DEV=0"))
print("reading lifecycle: ok")

# Both browser scripts must be available without mounting inside a read-only bind.
overlay = (root / "deploy/compose.reading-accounts.example.yaml").read_text()
mounts = {}
for line in overlay.splitlines():
    if line.strip().startswith("- ./"):
        source, destination, mode = line.strip()[2:].split(":")
        mounts[Path(destination)] = root / source
        assert mode == "ro"
for destination in mounts:
    assert not any(parent in mounts for parent in destination.parents), destination
for script in ("enter.js", "return.js"):
    destination = Path("/usr/share/nginx/html") / script
    assert mounts[destination].is_file(), script
