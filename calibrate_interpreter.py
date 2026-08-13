"""
calibrate_interpreter.py
-------------------------
Retunes interpreter.py's thresholds against the measurements actually
imported, instead of the hand-guessed starting values.

WHY THIS IS NEEDED
  The shipped thresholds assumed the three gains scatter around 0. They
  don't. Because each band is measured as a deviation from a 500-2000 Hz
  reference, the numbers carry the ear-canal resonance that EVERY in-ear
  measurement has -- a broad lift centred near 3 kHz that comes from the
  shape of ears, not from how the IEM is tuned.

  The practical result, on a real 123-IEM sample:

      presence  ranged +2.9 to +8.3   -> every IEM cleared the "+3 = forward"
                                         threshold, so all of them were
                                         described as "forward, present"
      treble    ranged -4.3 to +2.4   -> almost none reached +-3, so nearly
                                         all were "balanced treble"

  Twelve of the first fifteen imports produced one of just two sentences.
  A description that never varies tells the user nothing.

WHAT THIS DOES
  Reads every measurement in the folder, computes the same gains
  import_to_db.py would, and places the thresholds at PERCENTILES of the
  real distribution. Each label then describes where an IEM sits relative
  to the rest of the catalogue, which is what someone comparing IEMs
  actually wants to know.

  Note this makes the labels RELATIVE, not absolute: "bass-boosted" means
  "bassier than ~80% of the catalogue", not "above some fixed dB figure".
  That's a defensible choice for a recommendation UI, but it is a choice,
  and it's worth stating plainly in the paper rather than implying the
  labels are objective acoustic categories.

USAGE
  python calibrate_interpreter.py phone_book.json measurements/
  python calibrate_interpreter.py phone_book.json measurements/ --apply
"""

import argparse
import sys

from catalog_parser import load_catalog
from measurement_parser import compute_gains, parse_rew_file
from import_to_db import average_curves, find_measurement_files


def percentile(sorted_values, pct):
    """Linear-interpolated percentile. Avoids a numpy dependency."""
    if not sorted_values:
        return 0.0
    if len(sorted_values) == 1:
        return sorted_values[0]

    idx = (len(sorted_values) - 1) * (pct / 100.0)
    low = int(idx)
    high = min(low + 1, len(sorted_values) - 1)
    frac = idx - low
    return sorted_values[low] * (1 - frac) + sorted_values[high] * frac


def collect_gains(catalog_path, measurements_dir):
    from pathlib import Path
    measurements_dir = Path(measurements_dir)

    bands = {"bass_gain": [], "presence_gain": [], "treble_gain": []}
    matched = 0

    for entry in load_catalog(catalog_path):
        if not entry["primary_file"]:
            continue
        files = find_measurement_files(measurements_dir, entry["primary_file"])
        if not files:
            continue

        curves = [c for c in (parse_rew_file(f) for f in files) if c]
        if not curves:
            continue

        gains = compute_gains(average_curves(curves))
        if gains["bass_gain"] is None:
            continue

        matched += 1
        for band in bands:
            if gains[band] is not None:
                bands[band].append(gains[band])

    for band in bands:
        bands[band].sort()
    return matched, bands


def describe_distribution(name, values):
    if not values:
        return f"  {name:<15} no data"
    return (f"  {name:<15} n={len(values):<5} "
            f"min={values[0]:+6.2f}  p20={percentile(values,20):+6.2f}  "
            f"p50={percentile(values,50):+6.2f}  p80={percentile(values,80):+6.2f}  "
            f"max={values[-1]:+6.2f}")


def build_thresholds(bands):
    """
    Bass gets five labels (it's the band people care most about and the
    one with the widest spread); presence and treble get three each.
    Cut points are percentiles, so each label lands on a real slice of
    the catalogue instead of being unreachable.
    """
    b, p, t = bands["bass_gain"], bands["presence_gain"], bands["treble_gain"]

    return {
        "BASS_THRESHOLDS": [
            (round(percentile(b, 80), 2), "bass-boosted"),
            (round(percentile(b, 60), 2), "warm, full bass"),
            (round(percentile(b, 40), 2), "balanced bass"),
            (round(percentile(b, 20), 2), "light bass"),
            (float("-inf"), "bass-light"),
        ],
        "PRESENCE_THRESHOLDS": [
            (round(percentile(p, 70), 2), "forward, present vocals/mids"),
            (round(percentile(p, 30), 2), "balanced presence"),
            (float("-inf"), "recessed, distant vocals/mids"),
        ],
        "TREBLE_THRESHOLDS": [
            (round(percentile(t, 70), 2), "bright, energetic treble"),
            (round(percentile(t, 30), 2), "balanced treble"),
            (float("-inf"), "smooth, rolled-off treble"),
        ],
    }


def render_block(name, rows):
    lines = [f"{name} = ["]
    for cutoff, label in rows:
        cut = 'float("-inf")' if cutoff == float("-inf") else f"{cutoff}"
        lines.append(f'    ({cut}, "{label}"),')
    lines.append("]")
    return "\n".join(lines)


def preview_labels(bands, thresholds):
    """Show how many IEMs land in each label, to confirm the spread."""
    def classify(v, rows):
        for cutoff, label in rows:
            if v >= cutoff:
                return label
        return rows[-1][1]

    pairs = [("bass_gain", "BASS_THRESHOLDS"),
             ("presence_gain", "PRESENCE_THRESHOLDS"),
             ("treble_gain", "TREBLE_THRESHOLDS")]

    for band, key in pairs:
        counts = {}
        for v in bands[band]:
            lbl = classify(v, thresholds[key])
            counts[lbl] = counts.get(lbl, 0) + 1
        print(f"\n  {band}:")
        for _cut, lbl in thresholds[key]:
            print(f"    {counts.get(lbl, 0):>4}  {lbl}")


def apply_to_interpreter(thresholds, path="interpreter.py"):
    import re
    src = open(path, encoding="utf-8").read()

    for name, rows in thresholds.items():
        pattern = re.compile(rf"^{name} = \[.*?^\]", re.S | re.M)
        if not pattern.search(src):
            print(f"  could not find {name} in {path} — left unchanged")
            continue
        src = pattern.sub(render_block(name, rows), src)

    open(path, "w", encoding="utf-8").write(src)
    print(f"\nWrote new thresholds into {path}.")
    print("Re-run import_to_db.py --live to regenerate descriptions with them.")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("catalog")
    ap.add_argument("measurements_dir")
    ap.add_argument("--apply", action="store_true",
                    help="write the new thresholds into interpreter.py")
    args = ap.parse_args()

    matched, bands = collect_gains(args.catalog, args.measurements_dir)
    if matched == 0:
        print("No measurements found — nothing to calibrate against.")
        return 1

    print(f"Calibrating against {matched} measured IEMs.\n")
    print("Observed distribution:")
    for band in ("bass_gain", "presence_gain", "treble_gain"):
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
        print("\n(Nothing changed. Re-run with --apply to write these into interpreter.py.)")

    return 0


if __name__ == "__main__":
    sys.exit(main())
