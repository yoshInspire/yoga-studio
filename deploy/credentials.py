"""Load VPS credentials from deploy/SERVER.local.txt (never commit that file)."""

from __future__ import annotations

import os
import re
from pathlib import Path

SECRETS_PATH = Path(__file__).with_name("SERVER.local.txt")


def load_local_secrets() -> dict[str, str]:
    secrets: dict[str, str] = {}

    if not SECRETS_PATH.exists():
        return secrets

    with SECRETS_PATH.open(encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            match = re.match(r"^([A-Z0-9_]+)=(.+)$", line)
            if match:
                secrets[match.group(1)] = match.group(2).strip()
                continue
            password_match = re.match(r"^\s*Пароль:\s+(.+)$", line)
            if password_match:
                secrets["SSH_PASSWORD"] = password_match.group(1).strip()

    if "SSH_HOST" not in secrets:
        ip_match = re.search(r"^IP:\s+(\S+)", SECRETS_PATH.read_text(encoding="utf-8"), re.MULTILINE)
        if ip_match:
            secrets["SSH_HOST"] = ip_match.group(1)

    return secrets


def ssh_credentials() -> tuple[str, str, str]:
    local = load_local_secrets()
    host = os.environ.get("SSH_HOST") or local.get("SSH_HOST", "77.91.93.110")
    user = os.environ.get("SSH_USER") or local.get("SSH_USER", "root")
    password = os.environ.get("SSH_PASSWORD") or local.get("SSH_PASSWORD", "")

    if not password:
        raise RuntimeError(
            "SSH password not found. Set SSH_PASSWORD in deploy/SERVER.local.txt "
            "or in the SSH_PASSWORD environment variable."
        )

    return host, user, password
