"""Validate actual Compose profile selection, without starting containers."""
import os
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
env = dict(os.environ)
env.pop("COMPOSE_PROFILES", None)
env.pop("COMPOSE_FILE", None)
with tempfile.TemporaryDirectory() as directory:
    config = Path(directory) / "profiles.env"
    def services(profiles, override=None):
        config.write_text("COMPOSE_PROFILES=" + profiles + "\n")
        current = dict(env)
        if override is not None:
            current["COMPOSE_PROFILES"] = override
        return set(subprocess.check_output([
            "docker", "compose", "--env-file", str(config),
            "-f", "compose.yaml", "-f", "deploy/compose.portal-accounts.example.yaml",
            "config", "--services",
        ], cwd=root, env=current, text=True).splitlines())

    configured = services("postgres,redis,mailpit,pgweb,https,scheduler,workers")
    assert {"app", "web", "scheduler", "postgres", "redis", "mailpit",
            "pgweb", "caddy-local", "worker"} <= configured, configured
    assert not {"mysql", "mariadb", "node", "composer"} & configured, configured
    basic = services("")
    assert basic == {"app", "web", "scheduler"}, basic
    overridden = services("postgres,https", "redis")
    assert overridden == {"app", "web", "scheduler", "redis"}, overridden
print("Compose preserves dotenv/environment profiles and always starts integrated scheduler.")
