import argparse
import re
import sys
from pathlib import Path

from catalog_parser import load_catalog
from measurement_parser import compute_gains, parse_rew_file
from import_to_db import average_curves, find_measurement_files

BAND_KEYS = ("bass_gain", "presence_gain", "treble_gain")

THRESHOLD_NAMES = {
    "bass_gain": "BASS_THRESHOLDS",
    "presence_gain": "PRESENCE_THRESHOLDS",
    "treble_gain": "TREBLE_THRESHOLDS",
}

BASS_CUTS = [(80, "bass-boosted"), (60, "warm, full bass"),
             (40, "balanced bass"), (20, "light bass")]
BASS_FLOOR_LABEL = "bass-light"

PRESENCE_CUTS = [(70, "forward, present vocals/mids"), (30, "balanced presence")]
PRESENCE_FLOOR_LABEL = "recessed, distant vocals/mids"

TREBLE_CUTS = [(70, "bright, energetic treble"), (30, "balanced treble")]
TREBLE_FLOOR_LABEL = "smooth, rolled-off treble"

THRESHOLD_DECIMALS = 2

INTERPRETER_PATH = "interpreter.py"


def percentile(sorted_values, pct):
    if not sorted_values:
        return 0.0
    if len(sorted_values) == 1:
        return sorted_values[0]

    idx = (len(sorted_values) - 1) * (pct / 100.0)
    low = int(idx)
    high = min(low + 1, len(sorted_values) - 1)
    frac = idx - low
    return sorted_values[low] * (1 - frac) + sorted_values[high] * frac


def gains_for(entry, measurements_dir):
    if not entry["primary_file"]:
        return None

    files = find_measurement_files(measurements_dir, entry["primary_file"])
    if not files:
        return None

    curves = [c for c in (parse_rew_file(f) for f in files) if c]
    if not curves:
        return None

    gains = compute_gains(average_curves(curves))
    return gains if gains["bass_gain"] is not None else None


def collect_gains(catalog_path, measurements_dir):
    measurements_dir = Path(measurements_dir)
    bands = {band: [] for band in BAND_KEYS}
    matched = 0

    for entry in load_catalog(catalog_path):
        gains = gains_for(entry, measurements_dir)
        if gains is None:
            continue

        matched += 1
        for band in BAND_KEYS:
            if gains[band] is not None:
                bands[band].append(gains[band])

    for values in bands.values():
        values.sort()
    return matched, bands


def describe_distribution(name, values):
    if not values:
        return f"  {name:<15} no data"
    return (f"  {name:<15} n={len(values):<5} "
            f"min={values[0]:+6.2f}  p20={percentile(values, 20):+6.2f}  "
            f"p50={percentile(values, 50):+6.2f}  "
            f"p80={percentile(values, 80):+6.2f}  "
            f"max={values[-1]:+6.2f}")


def _thresholds_from(values, cuts, floor_label):
    rows = [
        (round(percentile(values, pct), THRESHOLD_DECIMALS), label)
        for pct, label in cuts
    ]
    rows.append((float("-inf"), floor_label))
    return rows


def build_thresholds(bands):
    return {
        "BASS_THRESHOLDS": _thresholds_from(
            bands["bass_gain"], BASS_CUTS, BASS_FLOOR_LABEL),
        "PRESENCE_THRESHOLDS": _thresholds_from(
            bands["presence_gain"], PRESENCE_CUTS, PRESENCE_FLOOR_LABEL),
        "TREBLE_THRESHOLDS": _thresholds_from(
            bands["treble_gain"], TREBLE_CUTS, TREBLE_FLOOR_LABEL),
    }


def render_block(name, rows):
    lines = [f"{name} = ["]
    for cutoff, label in rows:
        cut = 'float("-inf")' if cutoff == float("-inf") else f"{cutoff}"
        lines.append(f'    ({cut}, "{label}"),')
    lines.append("]")
    return "\n".join(lines)


def _classify(value, rows):
    for cutoff, label in rows:
        if value >= cutoff:
            return label
    return rows[-1][1]


def preview_labels(bands, thresholds):
    for band in BAND_KEYS:
        rows = thresholds[THRESHOLD_NAMES[band]]

        counts = {}
        for value in bands[band]:
            label = _classify(value, rows)
            counts[label] = counts.get(label, 0) + 1

        print(f"\n  {band}:")
        for _cutoff, label in rows:
            print(f"    {counts.get(label, 0):>4}  {label}")


def apply_to_interpreter(thresholds, path=INTERPRETER_PATH):
    with open(path, encoding="utf-8") as f:
        source = f.read()

    for name, rows in thresholds.items():
        pattern = re.compile(rf"^{name} = \[.*?^\]", re.S | re.M)
        if not pattern.search(source):
            print(f"  could not find {name} in {path} — left unchanged")
            continue
        source = pattern.sub(render_block(name, rows), source)

    with open(path, "w", encoding="utf-8") as f:
        f.write(source)

    print(f"\nWrote new thresholds into {path}.")
    print("Re-run import_to_db.py --live to regenerate descriptions with them.")


def parse_args():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("catalog")
    parser.add_argument("measurements_dir")
    parser.add_argument("--apply", action="store_true",
                        help="write the new thresholds into interpreter.py")
    return parser.parse_args()


def main():
    args = parse_args()

    matched, bands = collect_gains(args.catalog, args.measurements_dir)
    if matched == 0:
        print("No measurements found — nothing to calibrate against.")
        return 1

    print(f"Calibrating against {matched} measured IEMs.\n")
    print("Observed distribution:")
    for band in BAND_KEYS:
        print(describe_distribution(band, bands[band]))

    thresholds = build_thresholds(bands)

    print("\nProposed thresholds:")
    for name, rows in thresholds.items():
        print("\n" + render_block(name, rows))

    print("\nResulting label spread:")
    preview_labels(bands, thresholds)

    if args.apply:
        apply_to_interpreter(thresholds)
    else:
        print("\n(Nothing changed. Re-run with --apply to write these "
              "into interpreter.py.)")

    return 0


if __name__ == "__main__":
    sys.exit(main())
