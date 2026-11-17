"""nas-backup with a fake docker: order of operations, refusals, status file and recovery (no real backup)."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="multisuite-backup-") as directory:
    base = Path(directory)
    work = base / "repo"
    work.mkdir()
    shutil.copytree(root / "scripts", work / "scripts")
    (work / ".env").write_text('DB_USERNAME=portal\nDB_DATABASE="portal"\n')
    data, external = base / "srv" / "data", base / "srv" / "external"
    (data / "multisuite").mkdir(parents=True)
    external.mkdir(parents=True)
    (work / "local").mkdir()
    (work / "local" / "nas-targets").write_text(f"{data}\n")
    (work / "local" / "navidrome").mkdir()
    (work / "music.env").write_text("NAVIDROME_DATA_PATH=./local/navidrome\n")
    bin_dir = base / "bin"
    bin_dir.mkdir()
    log = base / "calls.log"
    (bin_dir / "docker").write_text(f"""#!/bin/sh
printf '%s\\n' "$*" >> "{log}"
case "$*" in
  *"cat config"*) exit "${{REPO_MISSING:-0}}" ;;
  *"compose cp "*) for last; do :; done; printf dump > "$last" ;;
  *"ps -q --status running navidrome"*) echo abc123 ;;
  *"ps -aq kavita"*) echo kavita-id ;;
  *".Mounts"*) echo custom_kavita-config ;;
  *".State.Status"*) echo "${{KAVITA_STATE:-running}}" ;;
  *"backup --tag kavita"*) exit "${{KAVITA_EXIT:-0}}" ;;
  *" check "*) exit "${{CHECK_EXIT:-0}}" ;;
esac
""")
    # Data and repository on different disks unless SAME_DISK=1.
    (bin_dir / "findmnt").write_text(f"""#!/bin/sh
for path; do :; done
case "$path" in {external}*) [ "${{SAME_DISK:-0}}" = 1 ] && echo /dev/sdb2 || echo /dev/sdc1 ;; {data}*) echo /dev/sdb1 ;; *) echo /dev/sda2 ;; esac
""")
    (bin_dir / "lsblk").write_text('#!/bin/sh\nfor dev; do :; done\nbasename "$dev" | tr -d 0-9\n')
    for tool in ("docker", "findmnt", "lsblk"):
        (bin_dir / tool).chmod(0o755)

    def run(command="run", **extra):
        log.write_text("")
        env = {**os.environ, "DOCKER": str(bin_dir / "docker"), "FINDMNT": str(bin_dir / "findmnt"), "LSBLK": str(bin_dir / "lsblk"), **extra}
        result = subprocess.run(["sh", "scripts/nas-backup.sh", command], cwd=work, env=env, capture_output=True, text=True)
        return result, log.read_text().splitlines()

    def status():
        return json.loads((work / "local" / "backup-status.json").read_text())

    result, calls = run()
    assert result.returncode == 1 and "falta backup.env" in result.stderr and not calls, result.stderr

    env_file = work / "backup.env"
    env_file.write_text(f"RESTIC_PASSWORD=secret\nBACKUP_REPOSITORY_PATH={external}\nBACKUP_KEEP_DAILY=3\n")
    result, calls = run(SAME_DISK="1")
    assert result.returncode == 1 and "mismo disco" in result.stderr and not calls, result.stderr
    assert not (work / "local" / "backup-status.json").exists()

    result, calls = run(REPO_MISSING="1")
    assert result.returncode == 0, result.stderr
    assert oct(env_file.stat().st_mode & 0o777) == "0o600"
    order = [next(i for i, call in enumerate(calls) if needle in call) for needle in (
        "cat config", " init", "pg_dump", "backup --tag portal", "stop navidrome", "backup --tag navidrome",
        "start navidrome", "forget --prune --group-by host,tags --keep-daily 3 --keep-weekly 4", "check --read-data-subset 10%")]
    assert order == sorted(order), calls
    portal = next(call for call in calls if "backup --tag portal" in call)
    assert f"-v {data}/multisuite:/source/nas{data}:ro" in portal and "/source/portal-db:ro" in portal, portal
    assert "--network none" in portal and f"-v {external}:/repository" in portal and "secret" not in "\n".join(calls)
    assert status()["ok"] is True and status()["step"] == "done" and status()["finished_at"]
    assert not (work / "local" / "backup-staging").exists() and not (work / "local" / "backup.lock").exists()

    # A failed check is recorded, Navidrome is running again and the next run is not blocked.
    result, calls = run(CHECK_EXIT="1")
    assert result.returncode == 1 and status()["ok"] is False and status()["step"] == "check", result.stderr
    assert any("start navidrome" in call for call in calls) and not any(" init" in call for call in calls)
    assert not (work / "local" / "backup.lock").exists()

    (work / "local" / "backup.lock").mkdir()
    result, calls = run()
    assert result.returncode == 1 and "en curso" in result.stderr and status()["step"] == "check"
    (work / "local" / "backup.lock").rmdir()

    target = base / "restore"
    target.mkdir()
    (target / "old").write_text("x")
    result, calls = run("restore", TARGET=str(target))
    assert result.returncode == 1 and "no está vacía" in result.stderr and not calls
    (target / "old").unlink()
    result, calls = run("restore", TARGET=str(target))
    assert result.returncode == 0 and sum("restore latest" in call for call in calls) == 2, result.stderr

    (work / "reading.env").touch()
    result, calls = run(KAVITA_EXIT="1")
    assert result.returncode and status()["step"] == "kavita"
    assert "stop kavita-id" in calls and "start kavita-id" in calls
    backup = next(i for i, call in enumerate(calls) if "backup --tag kavita" in call)
    assert "custom_kavita-config:/source/kavita:ro" in calls[backup]
    assert calls.index("stop kavita-id") < backup < calls.index("start kavita-id")
    result, calls = run(KAVITA_STATE="exited")
    assert result.returncode == 0 and "start kavita-id" not in calls and "stop kavita-id" not in calls
    result, calls = run("restore", TARGET=str(target))
    assert result.returncode == 0 and any("restore latest --tag kavita" in call for call in calls)

    result, calls = run("verify", FILES="0")
    assert result.returncode == 0, result.stderr
    assert any("--include /source/portal-db" in call for call in calls) and "volume rm -f multisuite-restore-rehearsal" in calls[-1]

    env_file.write_text("RESTIC_PASSWORD=secret\nRESTIC_REPOSITORY=sftp:nas@example.test:/backups\n")
    result, calls = run()
    assert result.returncode == 0 and not any("--network none" in call or "/repository" in call for call in calls), result.stderr
print("nas-backup: ok")
