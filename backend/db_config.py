"""Database credentials for the Python side of EqualizeME.

Mirrors how PHP does it. api/config.local.php holds the credentials for the
web layer and is gitignored; backend/config.local.json does the same job here,
so a password lives in exactly one file per language and neither is committed.

Resolution order, highest priority first:

  1. Environment variables    DB_HOST, DB_USER, DB_PASSWORD, DB_NAME
  2. backend/config.local.json
  3. Built-in defaults (root with no password — XAMPP's out-of-the-box state)

Environment variables win so a one-off run can override the file without
editing it:

    DB_USER=someone DB_PASSWORD=secret python ai_service.py

The defaults are deliberately the insecure-but-working XAMPP values. A fresh
clone runs without configuration; a real deployment is expected to supply
credentials by one of the two routes above. See sql/create_app_user.sql for
how to create a limited account.
"""

import json
import os

_CONFIG_FILENAME = "config.local.json"

DEFAULTS = {
    "host": "127.0.0.1",
    "user": "root",
    "password": "",
    "database": "equalizeme",
}

# Environment variable names, keyed by the config key they populate.
_ENV_KEYS = {
    "host": "DB_HOST",
    "user": "DB_USER",
    "password": "DB_PASSWORD",
    "database": "DB_NAME",
}


def _config_path():
    return os.path.join(os.path.dirname(os.path.abspath(__file__)),
                        _CONFIG_FILENAME)


def _from_file():
    path = _config_path()
    if not os.path.exists(path):
        return {}

    try:
        with open(path, "r", encoding="utf-8") as handle:
            loaded = json.load(handle)
    except (OSError, ValueError) as error:
        # A malformed config should be loud rather than silently ignored —
        # otherwise the app quietly falls back to root and appears to work.
        raise RuntimeError(
            f"Could not read {path}: {error}. Fix the file or delete it to "
            f"fall back to environment variables."
        ) from error

    database = loaded.get("database", loaded)
    return {k: database[k] for k in DEFAULTS if k in database}


def db_config():
    """Return a connection dict suitable for mysql.connector or pymysql."""
    config = dict(DEFAULTS)
    config.update(_from_file())

    for key, env_name in _ENV_KEYS.items():
        value = os.environ.get(env_name)
        if value is not None:
            config[key] = value

    return config


def describe_source():
    """Human-readable note about where the credentials came from.

    Printed at startup so it is obvious when the service is still running as
    root, which is easy to leave in place by accident.
    """
    parts = []
    if os.path.exists(_config_path()):
        parts.append(_CONFIG_FILENAME)
    if any(os.environ.get(name) for name in _ENV_KEYS.values()):
        parts.append("environment")

    origin = " + ".join(parts) if parts else "built-in defaults"
    user = db_config()["user"]

    warning = "  <-- consider a limited account, see sql/create_app_user.sql" \
        if user == "root" else ""

    return f"database: connecting as '{user}' (from {origin}){warning}"
