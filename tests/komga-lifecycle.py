"""Exercise the local Komga pilot and reversible Kavita disablement."""
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
        result = subprocess.run(["make", target, "KOMGA_AUTO=0", "READING_AUTO="+("1" if (work / "reading.env").exists() else "0"), "HOST_PREPARE=true", "PREFLIGHT_SCRIPT=true", "STATUS_SCRIPT=true", "HTTP_CHECK=true", *options], cwd=work, env=env, capture_output=True, text=True)
        assert result.returncode == 0, result.stdout + result.stderr
        return log.read_text().splitlines()


    (work / "reading.env").touch()
    calls = run("up", "READING_AUTO=0", "KOMGA_AUTO=1")
    assert any("compose.komga.yaml up -d" in c for c in calls)
    assert any("compose.reading-accounts.example.yaml stop" in c for c in calls)
    assert not any("compose.reading.yaml" in c and "up -d" in c for c in calls)
    assert any("compose.portal-kavita-disabled.yaml" in c for c in calls)
    calls = run("up", "READING_AUTO=1", "KOMGA_AUTO=0")
    assert not any("komga" in c for c in calls)
    assert any("compose.reading.yaml" in c and "up -d" in c for c in calls)
    calls = run("up-wait", "READING_AUTO=0", "KOMGA_AUTO=1")
    assert any("compose.komga.yaml up -d --wait --wait-timeout 180" in c for c in calls)
    calls = run("stop", "READING_AUTO=0", "KOMGA_AUTO=1")
    assert any("compose.komga.yaml stop" in c for c in calls)
    calls = run("down", "READING_AUTO=0", "KOMGA_AUTO=1")
    assert not any("-v" in c.split() for c in calls)
print("Komga lifecycle and Kavita reactivation: ok")
