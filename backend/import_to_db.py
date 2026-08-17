"""
Import IEMs into the `iems` table.

Combines catalog_parser (names, prices, shop links) with
measurement_parser (three gain figures and the curve) and writes one row
per IEM. Entries without a usable measurement file are skipped and listed
at the end rather than guessed at.

Only each IEM's primary measurement variant is imported; alternate
seatings or switch positions would need a separate table.

Usage:
    python backend/import_to_db.py phone_book.json measurements/
    python backend/import_to_db.py phone_book.json measurements/ --live

Without --live the SQL is printed instead of executed, so it can be read
first. Re-running is safe: the statement upserts, provided the
UNIQUE (brand, name) key from sql/schema_cleanup.sql exists.
"""

import argparse
import json
import os
import re
import sys
from pathlib import Path

from catalog_parser import load_catalog
from measurement_parser import parse_rew_file, compute_gains, serialize_curve
from interpreter import describe_curve

DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "localhost"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASS", ""),
    "database": os.environ.get("DB_NAME", "equalizeme"),
}

# How many skipped entries to print before summarising the rest.
MAX_SKIPPED_SHOWN = 50

# Channel suffix on a squiglink export: "L", "R", or a numbered variant.
CHANNEL_SUFFIX = r"[LR]\d*"

# Positions within a measurement point, as parsed from a REW file.
FREQUENCY, SPL, PHASE = 0, 1, 2

# cur.execute() returns this when the row was new rather than updated.
ROWS_AFFECTED_BY_INSERT = 1

IEMS_TABLE = "iems"

# Script field -> database column. The model name lives in `name`, not
# `model`: writing to `model` would leave `name` NULL and every imported
# IEM would render with a blank title.
IEMS_TABLE_COLUMNS = {
    "brand": "brand",
    "model": "name",
    "price": "price",
    "shop_link": "shop_link",
    "bass_gain": "bass_gain",
    "presence_gain": "presence_gain",
    "treble_gain": "treble_gain",
    "fr_curve_json": "fr_curve_json",
    "description": "description",
}

# retailer_id is deliberately untouched — shop_link is the buy link for
# IEMs imported this way.
INSERT_KEYS = ["brand", "model", "price", "shop_link", "bass_gain",
               "presence_gain", "treble_gain", "fr_curve_json", "description"]


def _find_exact(measurements_dir, name):
    """One filename, case-sensitive first then case-insensitive."""
    exact = measurements_dir / f"{name}.txt"
    if exact.exists():
        return exact
    lower_target = f"{name}.txt".lower()
    for candidate in measurements_dir.glob("*.txt"):
        if candidate.name.lower() == lower_target:
            return candidate
    return None


def find_measurement_files(measurements_dir, filename_stem):
    """
    All measurement files belonging to one catalogue entry.

    Squiglink exports are named by channel — "Dunu Zen L.txt",
    "Dunu Zen R.txt" — while the catalogue's file field is just
    "Dunu Zen", so an exact "<file>.txt" lookup matches nothing on a real
    download. The plain name is still tried as a fallback.
    """
    channels = [
        path for path in sorted(measurements_dir.glob("*.txt"))
        if _is_channel_of(path.stem, filename_stem)
    ]
    if channels:
        return channels

    plain = _find_exact(measurements_dir, filename_stem)
    return [plain] if plain else []


def _is_channel_of(stem, filename_stem):
    """True when `stem` is `filename_stem` plus a channel suffix."""
    if not stem.lower().startswith(filename_stem.lower()):
        return False
    # L or R, optionally numbered for multi-sample databases. Anything
    # else is a different IEM whose name merely starts the same way
    # ("Moondrop Aria" vs "Moondrop Aria 2").
    suffix = stem[len(filename_stem):].strip()
    return bool(re.fullmatch(CHANNEL_SUFFIX, suffix, flags=re.IGNORECASE))


def average_curves(curves):
    """
    Average several channel measurements into one curve.

    Squiglink's own graphs show the L/R average, so matching that keeps
    these numbers comparable to the site. Points are matched by index,
    which is safe because squiglink's required export settings
    (20-20 kHz, 48 PPO) give identical frequency points.
    """
    if len(curves) == 1:
        return curves[0]

    reference = curves[0]
    shortest = min(len(curve) for curve in curves)
    return [
        (
            reference[i][FREQUENCY],
            sum(curve[i][SPL] for curve in curves) / len(curves),
            reference[i][PHASE],
        )
        for i in range(shortest)
    ]


def build_rows(catalog_path, measurements_dir):
    """
    Split the catalogue into importable rows and skipped entries.

    Returns (matched_rows, skipped) where skipped holds "Brand Model
    (reason)" strings so the operator can see what didn't make it.
    """
    matched, skipped = [], []

    for entry in load_catalog(catalog_path):
        row, reason = _build_row(entry, measurements_dir)
        if row:
            matched.append(row)
        else:
            skipped.append(f"{entry['brand']} {entry['model']} ({reason})")

    return matched, skipped


def _build_row(entry, measurements_dir):
    """
    One catalogue entry as an insertable row.

    Returns (row, None) on success or (None, reason) when the entry has
    no usable measurement.
    """
    stem = entry["primary_file"]
    if not stem:
        return None, "no file field"

    files = find_measurement_files(measurements_dir, stem)
    if not files:
        return None, f"expected '{stem} L.txt' / '{stem} R.txt'"

    curves = [c for c in (parse_rew_file(f) for f in files) if c]
    if not curves:
        return None, "measurement file(s) found but empty/unparseable"

    points = average_curves(curves)
    gains = compute_gains(points)
    if gains["bass_gain"] is None:
        return None, "measurement found but missing mid-band reference data"

    return _row_from(entry, points, gains), None


def _row_from(entry, points, gains):
    """Assemble the column values for one IEM."""
    return {
        "brand": entry["brand"],
        "model": entry["model"],
        "price": entry["price"],
        "shop_link": entry["shop_link"],
        "bass_gain": gains["bass_gain"],
        "presence_gain": gains["presence_gain"],
        "treble_gain": gains["treble_gain"],
        "fr_curve_json": json.dumps(serialize_curve(points)),
        "description": describe_curve(
            gains["bass_gain"],
            gains["presence_gain"],
            gains["treble_gain"],
        ),
    }


# Columns refreshed when a row already exists. brand and name identify the
# row, so they're excluded — everything else is re-derived from the
# measurement and should reflect the latest import.
UPDATE_KEYS = [k for k in INSERT_KEYS if k not in ("brand", "model")]


def _upsert_clause():
    """
    The ON DUPLICATE KEY UPDATE tail that makes importing idempotent.

    Requires a UNIQUE key on (brand, name) — see sql/schema_cleanup.sql.
    Without that constraint MySQL can't detect the collision, this clause
    never fires, and a second import duplicates the whole catalogue.
    """
    columns = IEMS_TABLE_COLUMNS
    assignments = ", ".join(
        f"{columns[key]} = VALUES({columns[key]})" for key in UPDATE_KEYS
    )
    return f" ON DUPLICATE KEY UPDATE {assignments}"


# MySQL characters that must be escaped inside a quoted string literal,
# backslash first so later replacements aren't double-escaped.
SQL_ESCAPES = [
    ("\\", "\\\\"),
    ("'", "\\'"),
    ('"', '\\"'),
    ("\n", "\\n"),
    ("\r", "\\r"),
    ("\x1a", "\\Z"),
    ("\0", "\\0"),
]


def _sql_literal(value):
    """
    Render a value as a SQL literal for the preview output.

    Only the preview needs this — --live sends values as real query
    parameters. But the preview is printed to be read and pasted into
    phpMyAdmin, so it has to be correct. Doubling apostrophes alone is
    not: MySQL treats a backslash as an escape character, so a value
    like  Foo\\' ; DROP TABLE iems;--  would close the string early and
    leave the rest executable.
    """
    if value is None:
        return "NULL"
    if isinstance(value, (int, float)):
        return repr(value)
    return "'" + _escape_sql_string(str(value)) + "'"


def _escape_sql_string(text):
    """Escape a string for use inside single quotes in MySQL."""
    try:
        from pymysql.converters import escape_string
        return escape_string(text)
    except ImportError:
        # The preview shouldn't need a database driver installed.
        for char, replacement in SQL_ESCAPES:
            text = text.replace(char, replacement)
        return text


def _insert_prefix():
    """The shared INSERT INTO ... (columns) opening."""
    columns = ", ".join(IEMS_TABLE_COLUMNS[key] for key in INSERT_KEYS)
    return f"INSERT INTO {IEMS_TABLE} ({columns})"


def render_sql(rows):
    """The import as readable SQL, for review before running it."""
    prefix = _insert_prefix()
    upsert = _upsert_clause()
    return [
        f"{prefix} VALUES ("
        + ", ".join(_sql_literal(row[key]) for key in INSERT_KEYS)
        + f"){upsert};"
        for row in rows
    ]


def run_import(rows):
    """Execute the import, reporting how many rows were new."""
    import pymysql

    placeholders = ", ".join(["%s"] * len(INSERT_KEYS))
    sql = f"{_insert_prefix()} VALUES ({placeholders}){_upsert_clause()}"

    conn = pymysql.connect(**DB_CONFIG)
    try:
        inserted, updated = _execute_rows(conn, sql, rows)
        conn.commit()
        print(f"{inserted} new IEMs inserted, {updated} existing rows updated "
              f"in {IEMS_TABLE}.")
    finally:
        conn.close()


def _execute_rows(conn, sql, rows):
    """Run the statement for every row, counting inserts against updates."""
    inserted = updated = 0
    with conn.cursor() as cur:
        for row in rows:
            affected = cur.execute(sql, tuple(row[key] for key in INSERT_KEYS))
            # MySQL reports 1 affected row for an insert, 2 for an update
            # via ON DUPLICATE KEY, 0 when the values were already equal.
            if affected == ROWS_AFFECTED_BY_INSERT:
                inserted += 1
            else:
                updated += 1
    return inserted, updated


def _parse_args():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("catalog_path")
    parser.add_argument("measurements_dir")
    parser.add_argument(
        "--live",
        action="store_true",
        help="execute the inserts (default is to print the SQL only)",
    )
    return parser.parse_args()


def _report_skipped(skipped):
    """List what didn't import, on stderr so it stays out of the SQL."""
    if not skipped:
        return

    print(f"\n--- Skipped ({len(skipped)}) ---", file=sys.stderr)
    for entry in skipped[:MAX_SKIPPED_SHOWN]:
        print(f"  {entry}", file=sys.stderr)

    remaining = len(skipped) - MAX_SKIPPED_SHOWN
    if remaining > 0:
        print(f"  ... and {remaining} more", file=sys.stderr)


def main():
    args = _parse_args()

    measurements_dir = Path(args.measurements_dir)
    if not measurements_dir.is_dir():
        sys.exit(f"Not a directory: {measurements_dir}")

    matched, skipped = build_rows(args.catalog_path, measurements_dir)
    print(f"Matched {len(matched)} IEMs to a measurement file.")
    print(f"Skipped {len(skipped)} (no matching .txt found).\n")

    if args.live:
        run_import(matched)
    else:
        for statement in render_sql(matched):
            print(statement)

    _report_skipped(skipped)


if __name__ == "__main__":
    main()
