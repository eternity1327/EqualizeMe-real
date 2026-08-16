"""
catalog_parser.py
------------------
Parses squig.link-style phone_book.json catalogs into a flat list of
normalized IEM dicts, ready to hand to import_to_db.py.

Handles the messy real-world data:
  - "file" can be a single string OR a list of variant filenames
  - "reviewScore" can be a number-as-string, OR free text like
    "Tuned with Squiglink" (Juzear Harrier does this)
  - "price" can be "$350", "", "$??", "Free", "Priceless", etc.
"""

import json
import re


def _parse_price(raw):
    """Return (price_float_or_None, raw_string)."""
    if not raw:
        return None, raw
    match = re.search(r"[\d,]+(?:\.\d+)?", raw)
    if not match:
        # "Free", "Priceless", "$??" etc. -- no numeric price
        return None, raw
    try:
        return float(match.group(0).replace(",", "")), raw
    except ValueError:
        return None, raw


def _parse_review_score(raw):
    """Return (score_float_or_None, raw_string)."""
    if raw is None or raw == "":
        return None, raw
    try:
        return float(raw), raw
    except (TypeError, ValueError):
        # e.g. "Tuned with Squiglink" -- not a numeric score
        return None, raw


def _normalize_files(file_field, suffix_field):
    """
    "file" is either a single string or a list of variant filenames.
    Returns a list of {"file": ..., "suffix": ...} dicts. The first
    entry is treated as the primary/default variant.
    """
    if isinstance(file_field, list):
        suffixes = suffix_field if isinstance(suffix_field, list) else [None] * len(file_field)
        # pad suffixes if mismatched length (seen in a few catalog entries)
        if len(suffixes) < len(file_field):
            suffixes = suffixes + [None] * (len(file_field) - len(suffixes))
        return [{"file": f, "suffix": s} for f, s in zip(file_field, suffixes)]
    else:
        return [{"file": file_field, "suffix": None}]


def load_catalog(path):
    """
    Load a phone_book.json file and return a flat list of dicts:
      {
        brand, model, price, price_raw, review_score, review_score_raw,
        review_link, shop_link, primary_file, all_variants
      }
    One row per phone (not per variant) -- variants are kept in
    all_variants for later use (e.g. importing alternate FR curves).
    """
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)

    rows = []
    for brand_entry in data:
        brand = brand_entry.get("name", "").strip()
        for phone in brand_entry.get("phones", []):
            model = phone.get("name", "").strip()
            price, price_raw = _parse_price(phone.get("price"))
            score, score_raw = _parse_review_score(phone.get("reviewScore"))
            variants = _normalize_files(phone.get("file"), phone.get("suffix"))

            rows.append({
                "brand": brand,
                "model": model,
                "price": price,
                "price_raw": price_raw,
                "review_score": score,
                "review_score_raw": score_raw,
                "review_link": phone.get("reviewLink") or None,
                "shop_link": phone.get("shopLink") or None,
                "primary_file": variants[0]["file"] if variants else None,
                "all_variants": variants,
            })
    return rows


if __name__ == "__main__":
    import sys
    path = sys.argv[1] if len(sys.argv) > 1 else "test_phone_book.json"
    rows = load_catalog(path)
    print(f"Parsed {len(rows)} IEMs from {path}\n")
    for r in rows:
        print(f"- {r['brand']} {r['model']}: "
              f"price={r['price']} (raw={r['price_raw']!r}), "
              f"score={r['review_score']} (raw={r['review_score_raw']!r}), "
              f"primary_file={r['primary_file']!r}, "
              f"variants={len(r['all_variants'])}")
