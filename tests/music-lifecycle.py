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
    docker.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$CALL_LOG"\ncase "$*" in *compose.music.yaml*) exit "${MUSIC_EXIT:-0}";; esac\n')
    docker.chmod(0o755)
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
        assert f"compose --env-file music.env -f compose.music.yaml {command}" in calls, calls
        assert all("-v" not in call.split() for call in calls), calls
    calls = run("restart")
    assert sum("compose.music.yaml" in call for call in calls) == 2, calls
    assert all("compose.music.yaml" not in call for call in run("up", "MUSIC_AUTO=0"))
    env["MUSIC_EXIT"] = "7"
    run("up", fail=True)
print("Music lifecycle: configured/unconfigured, opt-out, aliases, restart and failure propagation passed.")
