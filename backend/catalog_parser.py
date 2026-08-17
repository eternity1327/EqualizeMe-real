import json
import re

PRICE_PATTERN = r"[\d,]+(?:\.\d+)?"


def _parse_price(raw):
    if not raw:
        return None, raw

    match = re.search(PRICE_PATTERN, raw)
    if not match:
        return None, raw

    try:
        return float(match.group(0).replace(",", "")), raw
    except ValueError:
        return None, raw


def _parse_review_score(raw):
    if raw is None or raw == "":
        return None, raw
    try:
        return float(raw), raw
    except (TypeError, ValueError):
        return None, raw


def _normalize_files(file_field, suffix_field):
    if not isinstance(file_field, list):
        return [{"file": file_field, "suffix": None}]

    suffixes = list(suffix_field) if isinstance(suffix_field, list) else []
    suffixes += [None] * (len(file_field) - len(suffixes))

    return [
        {"file": name, "suffix": suffix}
        for name, suffix in zip(file_field, suffixes)
    ]


def _parse_phone(brand, phone):
    price, price_raw = _parse_price(phone.get("price"))
    score, score_raw = _parse_review_score(phone.get("reviewScore"))
    variants = _normalize_files(phone.get("file"), phone.get("suffix"))

    return {
        "brand": brand,
        "model": phone.get("name", "").strip(),
        "price": price,
        "price_raw": price_raw,
        "review_score": score,
        "review_score_raw": score_raw,
        "review_link": phone.get("reviewLink") or None,
        "shop_link": phone.get("shopLink") or None,
        "primary_file": variants[0]["file"] if variants else None,
        "all_variants": variants,
    }


def load_catalog(path):
    with open(path, "r", encoding="utf-8") as f:
        catalog = json.load(f)

    return [
        _parse_phone(brand_entry.get("name", "").strip(), phone)
        for brand_entry in catalog
        for phone in brand_entry.get("phones", [])
    ]


if __name__ == "__main__":
    import sys

    path = sys.argv[1] if len(sys.argv) > 1 else "test_phone_book.json"
    rows = load_catalog(path)

    print(f"Parsed {len(rows)} IEMs from {path}\n")
    for row in rows:
        print(f"- {row['brand']} {row['model']}: "
              f"price={row['price']} (raw={row['price_raw']!r}), "
              f"score={row['review_score']} (raw={row['review_score_raw']!r}), "
              f"primary_file={row['primary_file']!r}, "
              f"variants={len(row['all_variants'])}")
