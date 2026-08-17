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

MAX_SKIPPED_SHOWN = 50

CHANNEL_SUFFIX = r"[LR]\d*"

FREQUENCY, SPL, PHASE = 0, 1, 2

ROWS_AFFECTED_BY_INSERT = 1

IEMS_TABLE = "iems"

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

INSERT_KEYS = ["brand", "model", "price", "shop_link", "bass_gain",
               "presence_gain", "treble_gain", "fr_curve_json", "description"]


def _find_exact(measurements_dir, name):
    exact = measurements_dir / f"{name}.txt"
    if exact.exists():
        return exact
    lower_target = f"{name}.txt".lower()
    for candidate in measurements_dir.glob("*.txt"):
        if candidate.name.lower() == lower_target:
            return candidate
    return None


def find_measurement_files(measurements_dir, filename_stem):
    channels = [
        path for path in sorted(measurements_dir.glob("*.txt"))
        if _is_channel_of(path.stem, filename_stem)
    ]
    if channels:
        return channels

    plain = _find_exact(measurements_dir, filename_stem)
    return [plain] if plain else []


def _is_channel_of(stem, filename_stem):
    if not stem.lower().startswith(filename_stem.lower()):
        return False
    suffix = stem[len(filename_stem):].strip()
    return bool(re.fullmatch(CHANNEL_SUFFIX, suffix, flags=re.IGNORECASE))


def average_curves(curves):
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
    matched, skipped = [], []

    for entry in load_catalog(catalog_path):
        row, reason = _build_row(entry, measurements_dir)
        if row:
            matched.append(row)
        else:
            skipped.append(f"{entry['brand']} {entry['model']} ({reason})")

    return matched, skipped


def _build_row(entry, measurements_dir):
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


UPDATE_KEYS = [k for k in INSERT_KEYS if k not in ("brand", "model")]


def _upsert_clause():
    columns = IEMS_TABLE_COLUMNS
    assignments = ", ".join(
        f"{columns[key]} = VALUES({columns[key]})" for key in UPDATE_KEYS
    )
    return f" ON DUPLICATE KEY UPDATE {assignments}"


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
    if value is None:
        return "NULL"
    if isinstance(value, (int, float)):
        return repr(value)
    return "'" + _escape_sql_string(str(value)) + "'"


def _escape_sql_string(text):
    try:
        from pymysql.converters import escape_string
        return escape_string(text)
    except ImportError:
        for char, replacement in SQL_ESCAPES:
            text = text.replace(char, replacement)
        return text


def _insert_prefix():
    columns = ", ".join(IEMS_TABLE_COLUMNS[key] for key in INSERT_KEYS)
    return f"INSERT INTO {IEMS_TABLE} ({columns})"


def render_sql(rows):
    prefix = _insert_prefix()
    upsert = _upsert_clause()
    return [
        f"{prefix} VALUES ("
        + ", ".join(_sql_literal(row[key]) for key in INSERT_KEYS)
        + f"){upsert};"
        for row in rows
    ]


def run_import(rows):
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
    inserted = updated = 0
    with conn.cursor() as cur:
        for row in rows:
            affected = cur.execute(sql, tuple(row[key] for key in INSERT_KEYS))
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
