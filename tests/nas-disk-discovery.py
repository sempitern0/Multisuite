"""nas-discover / nas-apply with fake lsblk/findmnt on temporary directories (no real disks)."""
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="multisuite-nas-") as directory:
    work = Path(directory) / "repo"
    work.mkdir()
    shutil.copytree(root / "scripts", work / "scripts")
    shutil.copy(root / "Makefile", work / "Makefile")
    (work / ".env").write_text(f'DEV_UID="{os.getuid()}"\nDEV_GID="{os.getgid()}"\n')
    disks = Path(directory) / "srv"
    for name in ("Disk_1", "backup", "readonly", "notmounted"):
        (disks / name).mkdir(parents=True)
    mounts = [
        f"{disks}/Disk_1 /dev/sdb1 ext4 1111-AAAA rw,relatime",
        f"{disks}/backup /dev/sdc1 xfs 2222-BBBB rw",
        f"{disks}/readonly /dev/sdd1 ext4 3333-CCCC ro",
        "/ /dev/sda2 ext4 0000-ROOT rw",
    ]
    bin_dir = Path(directory) / "bin"
    bin_dir.mkdir()
    (bin_dir / "mounts").write_text("\n".join(mounts) + "\n")
    (bin_dir / "findmnt").write_text(f"""#!/bin/sh
table={bin_dir}/mounts
case "$*" in
  *" -M "*) target=$(printf '%s\\n' "$@" | sed -n '/^-M$/{{n;p;}}')
            awk -v t="$target" '$1==t {{print $2, $3, $4, $5}}' "$table" ;;
  *) awk '{{print $1, $2, $3, $5}}' "$table" ;;
esac
""")
    (bin_dir / "lsblk").write_text("""#!/bin/sh
case "$*" in
  *-J*) printf '{"blockdevices":[{"name":"sdb","type":"disk","size":512110190592,"model":"Test SSD","children":[{"name":"sdb1","type":"part","size":512109142016,"fstype":"ext4","mountpoint":"/srv/Disk_1","fssize":503000000000,"fsavail":400000000000}]}]}\\n' ;;
  *) echo "NAME TYPE SIZE"; echo "sdb disk 477G" ;;
esac
""")
    for tool in ("findmnt", "lsblk"):
        (bin_dir / tool).chmod(0o755)
    env = dict(os.environ, PATH=f"{bin_dir}{os.pathsep}{os.environ['PATH']}")
    env.pop("MAKEFLAGS", None)

    def apply(*targets, upload=None, ok=True):
        run_env = dict(env, TARGETS=" ".join(str(t) for t in targets), YES="1")
        if upload:
            run_env["UPLOAD"] = upload
        result = subprocess.run(["sh", "scripts/nas-apply.sh"], cwd=work, env=run_env, capture_output=True, text=True)
        assert (result.returncode == 0) == ok, result.stdout + result.stderr
        return result.stdout + result.stderr

    # Refusals happen before touching anything.
    assert "no es un punto de montaje" in apply(disks / "notmounted", ok=False)
    assert "solo lectura" in apply(disks / "readonly", ok=False)
    assert "del sistema" in apply("/", ok=False)
    assert "rutas absolutas" in apply("srv/Disk_1", ok=False)
    assert not (work / "compose.nas.yaml").exists()
    assert not (disks / "Disk_1" / "multisuite").exists()

    apply(disks / "Disk_1", disks / "backup", upload="backup")
    for name, volume_id in (("Disk_1", "disk-1"), ("backup", "backup")):
        base = disks / name / "multisuite"
        assert (base / ".multisuite-volume-id").read_text().strip() == volume_id
        assert stat.S_IMODE((base / ".multisuite-private").stat().st_mode) == 0o700
    compose = (work / "compose.nas.yaml").read_text()
    assert f'"{disks}/Disk_1/multisuite:/nas/disk-1"' in compose and f'"{disks}/backup/multisuite:/nas/backup"' in compose
    volumes = json.loads(compose.split("NAS_STORAGE_VOLUMES: '")[1].split("'")[0])
    assert [v["id"] for v in volumes] == ["disk-1", "backup"]
    assert {v["group"] for v in volumes} == {"fs-1111aaaa", "fs-2222bbbb"}
    assert 'NAS_UPLOAD_LOCATION: "backup"' in compose
    assert (work / "local" / "nas-targets").read_text().split() == [f"{disks}/Disk_1", f"{disks}/backup"]

    # Idempotent re-run keeps existing data; a foreign marker is never overwritten.
    (disks / "Disk_1" / "multisuite" / ".multisuite-private" / "keep.blob").write_text("data")
    apply(disks / "Disk_1", disks / "backup")
    assert (disks / "Disk_1" / "multisuite" / ".multisuite-private" / "keep.blob").read_text() == "data"
    (disks / "backup" / "multisuite" / ".multisuite-volume-id").write_text("other\n")
    assert "otra marca" in apply(disks / "backup", ok=False)
    assert "no está entre los destinos" in apply(disks / "Disk_1", upload="nope", ok=False)

    # Discovery is read-only and writes the snapshot shown by the Storage page.
    result = subprocess.run(["sh", "scripts/nas-discover.sh"], cwd=work, env=env, capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    snapshot = json.loads((work / "local" / "nas-hardware.json").read_text())
    assert snapshot["lsblk"]["blockdevices"][0]["model"] == "Test SSD"
    assert snapshot["portal_targets"] == [f"{disks}/Disk_1", f"{disks}/backup"]
    assert f"{disks}/Disk_1" in result.stdout and "readonly" not in result.stdout.split("Candidatos")[1]

    # Make prefers the real disks over the dev NAS once compose.nas.yaml exists.
    out = subprocess.run(["make", "-n", "up", "HOST_PREPARE=true", "PREFLIGHT_SCRIPT=true", "STATUS_SCRIPT=true"], cwd=work, env=env, capture_output=True, text=True).stdout
    assert "-f compose.nas.yaml" in out and "compose.nas-dev.yaml" not in out, out
print("Disk discovery: refusals, apply, idempotence, foreign marker, snapshot and Make selection passed.")
