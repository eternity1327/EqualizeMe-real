"""
import_to_db.py
-----------------
Ties catalog_parser.py + measurement_parser.py together and imports
IEMs into the EqualizeME `iems` MySQL table.

USAGE
  python backend/import_to_db.py phone_book.json measurements_dir/ [--dry-run]

  phone_book.json    the full squig.link catalog file
  measurements_dir/  a folder of REW .txt files, one per IEM, where
                      each filename (minus .txt) matches a catalog
                      entry's "file" field, e.g.:
                        "Moondrop Blessing 3.txt"  <-> file: "Moondrop Blessing 3"
  (default)           prints the generated SQL instead of touching
                      the database -- read it before doing anything else
  --live              actually connects and executes the inserts
                      (only use this after you've verified DB_CONFIG
                      and IEMS_TABLE_COLUMNS below match your real schema)

WHAT THIS DOES NOT DO
  - It does not guess filenames. Only catalog entries with a matching
    .txt file in measurements_dir get imported (everything else is
    skipped and listed at the end) -- you only gave me one sample
    measurement so far, so most rows will skip until you drop more
    REW files in that folder.
  - It only imports each IEM's PRIMARY file/variant (the first one
    listed), not every shallow/deep/switch-position variant. Ask if
    you want those stored too -- would need a separate table.

BEFORE RUNNING FOR REAL
  1. Add the shop_link column (iems currently only has retailer_id,
     not a direct URL column):
       ALTER TABLE iems ADD COLUMN shop_link VARCHAR(500) NULL;
  2. Double check the rest of IEMS_TABLE_COLUMNS below against your
     actual schema (SHOW COLUMNS FROM iems;) -- bass/presence/treble
     gain column names are going off what's in memory from earlier
     sessions, not a live check.
  3. Fill in DB_CONFIG below (or set the env vars).
  4. Run without --live first and read the generated SQL before
     letting it touch your real database.
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

# ---------------------------------------------------------------
# CONFIG -- adjust these to match your real setup
# ---------------------------------------------------------------
DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "localhost"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASS", ""),
    "database": os.environ.get("DB_NAME", "equalizeme"),
}

# Column names in your `iems` table. Adjust to match SHOW COLUMNS FROM iems;
#
# NOTE: the model name lives in a column called `name`, not `model` --
# confirmed against the recommendations query in ai_service.py, which
# does `SELECT i.id, i.name, i.brand, ...`. Writing to `model` would
# either error or leave `name` NULL, and every imported IEM would render
# with a blank title on recommendations.html.
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
# Note: retailer_id (the old FK-based retailer link) is intentionally
# not touched by this script -- shop_link replaces it as the source
# of the "buy" link for IEMs imported this way.
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
    All measurement files belonging to one catalog entry.

    Squiglink does NOT store measurements as "<file>.txt". Per the
    official docs, exports are named by channel:

        Dunu Zen L.txt          Dunu Zen R.txt
        Dunu Zen L1.txt ...     Dunu Zen R1.txt ...   (multi-sample sites)

    while the phone_book "file" field is just "Dunu Zen". Looking only
    for an exact "<file>.txt" therefore matches nothing on a real
    squiglink download — every IEM would be reported as skipped.

    Returns a list of paths (possibly several channels/seatings), which
    the caller averages into a single curve. Falls back to a plain
    "<file>.txt" for databases that do store it that way.
    """
    found = []

    # Channel/seating variants: "<name> L", "<name> R", "<name> L1"...
    for candidate in sorted(measurements_dir.glob("*.txt")):
        stem = candidate.stem
        if not stem.lower().startswith(filename_stem.lower()):
            continue
        suffix = stem[len(filename_stem):].strip()
        # Accept L / R optionally followed by a sample number. Anything
        # else is a different IEM whose name merely starts the same
        # (e.g. "Moondrop Aria" vs "Moondrop Aria 2"), so skip it.
        if re.fullmatch(r"[LR]\d*", suffix, flags=re.IGNORECASE):
            found.append(candidate)

    if found:
        return found

    plain = _find_exact(measurements_dir, filename_stem)
    return [plain] if plain else []


def average_curves(curve_list):
    """
    Average several channel/seating measurements into one curve.

    Squiglink graphs show the average of the L and R channels rather
    than either alone, so matching that keeps our numbers comparable to
    what the site displays. Frequencies are taken from the first file;
    the others are matched by index, which is safe because the export
    settings squiglink requires (20-20kHz, 48 PPO) produce identical
    frequency points across files from the same database.
    """
    if len(curve_list) == 1:
        return curve_list[0]

    shortest = min(len(c) for c in curve_list)
    averaged = []
    for i in range(shortest):
        freq = curve_list[0][i][0]
        spl = sum(c[i][1] for c in curve_list) / len(curve_list)
        phase = curve_list[0][i][2]
        averaged.append((freq, spl, phase))
    return averaged


def build_rows(catalog_path, measurements_dir):
    """
    Returns (matched_rows, unmatched_models) where matched_rows is a
    list of dicts ready for insertion and unmatched_models is a list
    of "Brand Model" strings that had no measurement file found.
    """
    catalog = load_catalog(catalog_path)
    matched, unmatched = [], []

    for entry in catalog:
        if not entry["primary_file"]:
            unmatched.append(f"{entry['brand']} {entry['model']} (no file field)")
            continue

        mfiles = find_measurement_files(measurements_dir, entry["primary_file"])
        if not mfiles:
            unmatched.append(f"{entry['brand']} {entry['model']} "
                              f"(expected '{entry['primary_file']} L.txt' / "
                              f"'{entry['primary_file']} R.txt')")
            continue

        curves = [parse_rew_file(f) for f in mfiles]
        curves = [c for c in curves if c]
        if not curves:
            unmatched.append(f"{entry['brand']} {entry['model']} "
                              f"(measurement file(s) found but empty/unparseable)")
            continue

        points = average_curves(curves)
        gains = compute_gains(points)
        if gains["bass_gain"] is None:
            unmatched.append(f"{entry['brand']} {entry['model']} "
                              f"(measurement found but missing mid-band reference data)")
            continue

        matched.append({
            "brand": entry["brand"],
            "model": entry["model"],
            "price": entry["price"],
            "shop_link": entry["shop_link"],
            "bass_gain": gains["bass_gain"],
            "presence_gain": gains["presence_gain"],
            "treble_gain": gains["treble_gain"],
            "fr_curve_json": json.dumps(serialize_curve(points)),
            "description": describe_curve(gains["bass_gain"],
                                           gains["presence_gain"],
                                           gains["treble_gain"]),
        })

    return matched, unmatched


# Columns refreshed when a row already exists. brand and name identify the
# row, so they're excluded — everything else is re-derived from the
# measurement and should reflect the latest import.
UPDATE_KEYS = [k for k in INSERT_KEYS if k not in ("brand", "model")]


def _upsert_clause():
    """
    The ON DUPLICATE KEY UPDATE tail that makes importing idempotent.

    Without it a second import inserted a whole second copy of every IEM,
    because nothing in the statement told MySQL these rows were the same
    ones. Re-running after adding measurements or recalibrating the
    description thresholds is a normal thing to want to do, and it
    shouldn't silently double the catalogue.

    Requires a UNIQUE key on (brand, name) — see sql/schema_cleanup.sql.
    Without that constraint MySQL has no way to detect the collision and
    this clause simply never fires.
    """
    cols = IEMS_TABLE_COLUMNS
    assignments = ", ".join(f"{cols[k]} = VALUES({cols[k]})" for k in UPDATE_KEYS)
    return f" ON DUPLICATE KEY UPDATE {assignments}"


def _sql_literal(value):
    """
    Render a value as a SQL literal for the PREVIEW output.

    Only the preview needs this — the --live path sends values as real
    query parameters and never builds SQL from them. But the preview is
    printed so it can be read and, quite reasonably, pasted into
    phpMyAdmin, so the SQL it produces has to be correct.

    The previous version escaped by doubling apostrophes and nothing else,
    which breaks on backslashes. MySQL treats a backslash as an escape
    character by default, so:

        Foo\\' ; DROP TABLE iems;--

    became  'Foo\\'' ; DROP TABLE iems;--'  — the \\' is read as an escaped
    apostrophe, the next ' closes the string early, and the rest is
    executable SQL. A value ending in a backslash escaped the closing
    quote instead, breaking the statement.

    This data comes from a third-party catalogue rather than from users,
    so it isn't an active attack path — but it is untrusted input being
    rendered into SQL a person may run, and a brand name containing a
    backslash would corrupt the import without anyone acting maliciously.

    pymysql's escaper handles backslashes, control characters and quotes
    correctly; the fallback covers the same cases if it isn't installed
    (the preview shouldn't require a database driver to run).
    """
    if value is None:
        return "NULL"

    if isinstance(value, (int, float)):
        return repr(value)

    text = str(value)

    try:
        from pymysql.converters import escape_string
        return "'" + escape_string(text) + "'"
    except ImportError:
        escaped = (
            text.replace("\\", "\\\\")   # backslashes first, or the rest double-escape
                .replace("'", "\\'")
                .replace('"', '\\"')
                .replace("\n", "\\n")
                .replace("\r", "\\r")
                .replace("\x1a", "\\Z")
                .replace("\0", "\\0")
        )
        return "'" + escaped + "'"


def render_sql(rows):
    cols = IEMS_TABLE_COLUMNS
    col_list = ", ".join(cols[k] for k in INSERT_KEYS)
    upsert = _upsert_clause()
    statements = []
    for r in rows:
        values = ", ".join(_sql_literal(r[k]) for k in INSERT_KEYS)
        statements.append(
            f"INSERT INTO {IEMS_TABLE} ({col_list}) VALUES ({values}){upsert};"
        )
    return statements


def run_import(rows):
    import pymysql
    conn = pymysql.connect(**DB_CONFIG)
    cols = IEMS_TABLE_COLUMNS
    col_list = ", ".join(cols[k] for k in INSERT_KEYS)
    placeholders = ", ".join(["%s"] * len(INSERT_KEYS))
    sql = (f"INSERT INTO {IEMS_TABLE} ({col_list}) VALUES ({placeholders})"
           + _upsert_clause())

    inserted = updated = 0
    try:
        with conn.cursor() as cur:
            for r in rows:
                affected = cur.execute(sql, tuple(r[k] for k in INSERT_KEYS))
                # MySQL reports 1 row affected for an insert and 2 for an
                # update performed by ON DUPLICATE KEY (0 if the values were
                # already identical).
                if affected == 1:
                    inserted += 1
                else:
                    updated += 1
        conn.commit()
        print(f"{inserted} new IEMs inserted, {updated} existing rows updated "
              f"in {IEMS_TABLE}.")
    finally:
        conn.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("catalog_path")
    parser.add_argument("measurements_dir")
    parser.add_argument("--live", action="store_true",
                         help="actually execute inserts against the database "
                              "(default is to print SQL only)")
    args = parser.parse_args()

    measurements_dir = Path(args.measurements_dir)
    if not measurements_dir.is_dir():
        sys.exit(f"Not a directory: {measurements_dir}")

    matched, unmatched = build_rows(args.catalog_path, measurements_dir)

    print(f"Matched {len(matched)} IEMs to a measurement file.")
    print(f"Skipped {len(unmatched)} (no matching .txt found).\n")

    if args.live:
        run_import(matched)
    else:
        for stmt in render_sql(matched):
            print(stmt)

    if unmatched:
        print(f"\n--- Skipped ({len(unmatched)}) ---", file=sys.stderr)
        for m in unmatched[:50]:
            print(f"  {m}", file=sys.stderr)
        if len(unmatched) > 50:
            print(f"  ... and {len(unmatched) - 50} more", file=sys.stderr)


if __name__ == "__main__":
    main()
