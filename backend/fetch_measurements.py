"""
Download REW measurement files from squig.link, ready for import_to_db.

Permission
    Mark Ryan Sallee (squig.link / Super* Review) granted this project
    permission by email on 2026-08-11 to use his measurement data for
    non-commercial purposes, provided it isn't redistributed in a way
    that duplicates Squiglink's own functionality. Credit the source.

    That permission covers only his own databases, which is why BASE_URL
    is fixed here rather than being a command-line option. Other
    squiglink sites host data owned by different measurers and would
    need their own permission.

Courtesy
    This hits someone else's server. The default delay between requests
    and the skip-if-already-downloaded check are there on purpose, and
    a couple of dozen IEMs demonstrates the feature — fetching every
    channel of all 588 is over a thousand requests for no benefit.

Usage:
    python backend/fetch_measurements.py phone_book.json measurements/ --preset demo
    python backend/fetch_measurements.py phone_book.json measurements/ --limit 10 --dry-run
    python backend/fetch_measurements.py phone_book.json measurements/ --name "Moondrop Aria"
    python backend/fetch_measurements.py phone_book.json measurements/ --brand Moondrop
"""

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

# Fixed deliberately — see the permission note above.
BASE_URL = "https://squig.link/data/"

# Channel suffixes, tried in tiers. Nearly every entry is a plain L/R
# pair, so probing all eight every time would mean six wasted 404s per
# IEM against someone else's server.
CHANNEL_TIERS = [
    ["L", "R"],
    ["L1", "R1", "L2", "R2", "L3", "R3"],
]

# A spread of popular, tonally different IEMs — enough variety that the
# matching has something to distinguish and the generated descriptions
# visibly differ from each other.
DEMO_PRESET = [
    "Truthear Zero",
    "Moondrop Aria",
    "7Hz Salnotes Zero",
    "Moondrop Chu",
    "Tangzu Wan'er S.G",
    "KZ ZSN Pro X",
    "Etymotic ER2XR",
    "Sennheiser IE 200",
    "Moondrop Blessing 3",
    "Truthear Hexa",
]

USER_AGENT = ("EqualizeME-student-project/1.0 "
              "(academic use, permission granted by squig.link)")

DEFAULT_DELAY_SECONDS = 1.0
REQUEST_TIMEOUT_SECONDS = 20
NOT_FOUND = 404

# A real REW export is hundreds of lines of numbers. Anything smaller is
# almost certainly an error page rather than measurement data.
MIN_MEASUREMENT_BYTES = 200


def load_entries(catalog_path):
    """Flatten phone_book.json into (brand, model, primary_file) tuples."""
    with open(catalog_path, "r", encoding="utf-8") as f:
        catalog = json.load(f)

    entries = []
    for brand_entry in catalog:
        brand = (brand_entry.get("name") or "").strip()
        for phone in brand_entry.get("phones", []):
            primary = _primary_file(phone)
            if primary:
                model = (phone.get("name") or "").strip()
                entries.append((brand, model, primary))
    return entries


def _primary_file(phone):
    """The main measurement filename — "file" may be a string or a list."""
    file_field = phone.get("file")
    if isinstance(file_field, list):
        return file_field[0] if file_field else None
    return file_field


def _matches_any(entry, needles):
    """True when a brand, model or filename contains one of the needles."""
    brand, model, primary = entry
    haystack = f"{brand} {model} {primary}".lower()
    return any(needle in haystack for needle in needles)


def select_entries(entries, args):
    """Apply whichever filter was asked for, in order of specificity."""
    if args.preset == "demo":
        return [e for e in entries if _matches_any(e, _lowered(DEMO_PRESET))]

    if args.name:
        return [e for e in entries if _matches_any(e, _lowered(args.name))]

    if args.brand:
        brands = set(_lowered(args.brand))
        return [e for e in entries if e[0].lower() in brands]

    if args.limit:
        return entries[:args.limit]

    return entries


def _lowered(values):
    return [value.lower() for value in values]


def download_one(url, dest_path, timeout=REQUEST_TIMEOUT_SECONDS):
    """
    Fetch one measurement file.

    Returns "ok", "skipped" (already on disk) or "missing" (no such file).
    Anything else raises so the caller can decide whether to continue.
    """
    if os.path.exists(dest_path):
        return "skipped"

    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read()
    except urllib.error.HTTPError as error:
        if error.code == NOT_FOUND:
            return "missing"
        raise

    if len(body) < MIN_MEASUREMENT_BYTES:
        return "missing"

    with open(dest_path, "wb") as f:
        f.write(body)
    return "ok"


def parse_args():
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument("catalog", help="path to phone_book.json")
    parser.add_argument("out_dir", help="folder to save .txt files into")
    parser.add_argument("--preset", choices=["demo"],
                        help="download a curated spread of well-known IEMs")
    parser.add_argument("--name", action="append",
                        help="match IEMs by name (repeatable)")
    parser.add_argument("--brand", action="append",
                        help="download every IEM from this brand (repeatable)")
    parser.add_argument("--limit", type=int,
                        help="just take the first N catalog entries")
    parser.add_argument("--delay", type=float, default=DEFAULT_DELAY_SECONDS,
                        help="seconds between requests (please be polite)")
    parser.add_argument("--dry-run", action="store_true",
                        help="print the URLs that would be fetched, download nothing")
    return parser.parse_args()


def channel_url(primary, suffix):
    """Where one channel's measurement file lives."""
    return BASE_URL + urllib.parse.quote(f"{primary} {suffix}.txt")


def fetch_entry(primary, out_dir, delay, tally):
    """
    Download every channel file for one IEM.

    Returns True if anything was found. Tiers are tried in order and the
    second is skipped once the first succeeds, so a normal L/R pair costs
    two requests instead of eight.
    """
    for tier in CHANNEL_TIERS:
        found = _fetch_tier(primary, tier, out_dir, delay, tally)
        if found:
            return True
    return False


def _fetch_tier(primary, tier, out_dir, delay, tally):
    """Try one tier of channel suffixes; True if any file was obtained."""
    found = False
    for suffix in tier:
        filename = f"{primary} {suffix}.txt"
        dest = os.path.join(out_dir, filename)

        try:
            result = download_one(channel_url(primary, suffix), dest)
        except Exception as error:
            print(f"  FAILED   {filename}: {error}")
            tally["failed"] += 1
            time.sleep(delay)
            continue

        if result == "ok":
            print(f"  saved    {filename}")
            tally["got"] += 1
            found = True
        elif result == "skipped":
            print(f"  have it  {filename}")
            tally["skipped"] += 1
            found = True
        # "missing" is normal — an IEM measured on one channel only, or a
        # database using the numbered convention.

        time.sleep(delay)
    return found


def preview(selected):
    """Print what a real run would request, without touching the server."""
    for _, _, primary in selected:
        print(f"  would fetch  {channel_url(primary, CHANNEL_TIERS[0][0])}")


def main():
    args = parse_args()

    entries = load_entries(args.catalog)
    selected = select_entries(entries, args)

    if not selected:
        print("No catalog entries matched that filter.")
        print("Try --preset demo, or --brand Moondrop, or --limit 10.")
        return 1

    print(f"Catalog holds {len(entries)} IEMs; {len(selected)} selected.")

    if args.dry_run:
        print("DRY RUN — nothing will be downloaded.\n")
        preview(selected)
        return 0

    os.makedirs(args.out_dir, exist_ok=True)
    print(f"Saving into {args.out_dir}/ with a {args.delay}s pause "
          f"between requests.\n")

    tally = {"got": 0, "skipped": 0, "missing": 0, "failed": 0}
    for brand, model, primary in selected:
        if not fetch_entry(primary, args.out_dir, args.delay, tally):
            print(f"  none     {brand} {model} "
                  f"(no channel files found for '{primary}')")
            tally["missing"] += 1

    print(f"\nDownloaded {tally['got']}, already had {tally['skipped']}, "
          f"{tally['missing']} IEMs with nothing found, "
          f"{tally['failed']} errors.")
    print(f"\nNext: python backend/import_to_db.py "
          f"{args.catalog} {args.out_dir}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
