"""
fetch_measurements.py
----------------------
Downloads REW measurement .txt files from squig.link into a local
folder, ready for import_to_db.py.

PERMISSION
  Mark Ryan Sallee (squig.link / Super* Review) granted this project
  permission by email on 2026-08-11 to use his measurement data for
  NON-COMMERCIAL purposes, on the condition that it isn't redistributed
  in a way that duplicates Squiglink's own functionality.

  That permission covers ONLY his own databases:
      https://squig.link/
      https://squig.link/headphones/
      https://squig.link/earbuds/

  Other squiglink sites (e.g. achoreviews.squig.link) host data owned by
  different measurers. Do NOT point this script at them without asking
  those operators first — which is why BASE_URL is fixed here rather
  than being a command-line option.

  Credit the source in the paper.

BEING A GOOD CITIZEN
  This hits someone else's server, so by default it pauses between
  requests and skips files already downloaded. Please don't remove the
  delay, and don't pull the whole catalog when a couple of dozen IEMs
  is enough to demonstrate the feature. There are 588 IEMs in the
  catalog — fetching every channel of every one is over a thousand
  requests for no benefit.

USAGE
  # see what would be downloaded, without downloading anything
  python fetch_measurements.py phone_book.json measurements/ --limit 10 --dry-run

  # a spread of well-known models (recommended starting point)
  python fetch_measurements.py phone_book.json measurements/ --preset demo

  # specific models by name
  python fetch_measurements.py phone_book.json measurements/ --name "Truthear Zero" --name "Moondrop Aria"

  # everything from certain brands
  python fetch_measurements.py phone_book.json measurements/ --brand Moondrop --brand Truthear

  # first N in the catalog
  python fetch_measurements.py phone_book.json measurements/ --limit 20
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
# pair, so those go first and the numbered multi-sample names are only
# attempted if neither turned up. Probing all eight every time would mean
# six wasted 404s per IEM against someone else's server.
CHANNEL_TIERS = [
    ["L", "R"],
    ["L1", "R1", "L2", "R2", "L3", "R3"],
]

# A spread of popular, tonally different IEMs — enough variety that the
# recommendation matching has something to distinguish and the generated
# descriptions visibly differ from each other.
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

USER_AGENT = "EqualizeME-student-project/1.0 (academic use, permission granted by squig.link)"


def load_entries(catalog_path):
    """Flatten phone_book.json into (brand, model, primary_file) tuples."""
    with open(catalog_path, "r", encoding="utf-8") as f:
        data = json.load(f)

    entries = []
    for brand_entry in data:
        brand = (brand_entry.get("name") or "").strip()
        for phone in brand_entry.get("phones", []):
            model = (phone.get("name") or "").strip()
            file_field = phone.get("file")
            # "file" is either a string or a list of variants; the first
            # is the primary measurement.
            if isinstance(file_field, list):
                primary = file_field[0] if file_field else None
            else:
                primary = file_field
            if primary:
                entries.append((brand, model, primary))
    return entries


def select_entries(entries, args):
    """Apply whichever filter the user asked for."""
    if args.preset == "demo":
        wanted = [w.lower() for w in DEMO_PRESET]
        selected = [e for e in entries
                    if any(w in f"{e[0]} {e[1]}".lower() or w in e[2].lower() for w in wanted)]
        return selected

    if args.name:
        wanted = [n.lower() for n in args.name]
        return [e for e in entries
                if any(w in f"{e[0]} {e[1]}".lower() or w in e[2].lower() for w in wanted)]

    if args.brand:
        wanted = [b.lower() for b in args.brand]
        return [e for e in entries if e[0].lower() in wanted]

    if args.limit:
        return entries[:args.limit]

    return entries


def download_one(url, dest_path, timeout=20):
    """
    Returns "ok", "skipped" (already present) or "missing" (404).
    Anything else raises so the caller can decide whether to continue.
    """
    if os.path.exists(dest_path):
        return "skipped"

    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read()
    except urllib.error.HTTPError as e:
        if e.code == 404:
            return "missing"
        raise

    # A real REW export is many lines of numbers. Anything tiny is almost
    # certainly an error page rather than measurement data.
    if len(body) < 200:
        return "missing"

    with open(dest_path, "wb") as f:
        f.write(body)
    return "ok"


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("catalog", help="path to phone_book.json")
    ap.add_argument("out_dir", help="folder to save .txt files into")
    ap.add_argument("--preset", choices=["demo"],
                    help="download a curated spread of well-known IEMs")
    ap.add_argument("--name", action="append",
                    help="match IEMs by name (repeatable)")
    ap.add_argument("--brand", action="append",
                    help="download every IEM from this brand (repeatable)")
    ap.add_argument("--limit", type=int,
                    help="just take the first N catalog entries")
    ap.add_argument("--delay", type=float, default=1.0,
                    help="seconds between requests (default 1.0 — please be polite)")
    ap.add_argument("--dry-run", action="store_true",
                    help="print the URLs that would be fetched, download nothing")
    args = ap.parse_args()

    entries = load_entries(args.catalog)
    selected = select_entries(entries, args)

    if not selected:
        print("No catalog entries matched that filter.")
        print("Try --preset demo, or --brand Moondrop, or --limit 10.")
        return 1

    if not args.dry_run:
        os.makedirs(args.out_dir, exist_ok=True)

    print(f"Catalog holds {len(entries)} IEMs; {len(selected)} selected.")
    if args.dry_run:
        print("DRY RUN — nothing will be downloaded.\n")
    else:
        print(f"Saving into {args.out_dir}/ with a {args.delay}s pause between requests.\n")

    got, skipped, missing, failed = 0, 0, 0, 0

    for brand, model, primary in selected:
        found_any = False

        for tier in CHANNEL_TIERS:
            # Once a plain L/R pair has been found there's no reason to
            # probe the numbered-sample names as well.
            if found_any:
                break

            for suffix in tier:
                filename = f"{primary} {suffix}.txt"
                url = BASE_URL + urllib.parse.quote(filename)
                dest = os.path.join(args.out_dir, filename)

                if args.dry_run:
                    print(f"  would fetch  {url}")
                    found_any = True
                    continue

                try:
                    result = download_one(url, dest)
                except Exception as e:
                    print(f"  FAILED   {filename}: {e}")
                    failed += 1
                    time.sleep(args.delay)
                    continue

                if result == "ok":
                    print(f"  saved    {filename}")
                    got += 1
                    found_any = True
                elif result == "skipped":
                    print(f"  have it  {filename}")
                    skipped += 1
                    found_any = True
                # "missing" is normal — an IEM measured on one channel
                # only, or a database using the numbered convention.

                time.sleep(args.delay)

        if not found_any and not args.dry_run:
            print(f"  none     {brand} {model} (no channel files found for '{primary}')")
            missing += 1

    if not args.dry_run:
        print(f"\nDownloaded {got}, already had {skipped}, "
              f"{missing} IEMs with nothing found, {failed} errors.")
        print(f"\nNext: python import_to_db.py {args.catalog} {args.out_dir}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
