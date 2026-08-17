"""
Reduce a REW frequency-response measurement to three gain figures.

Absolute SPL depends on how loud the measurement rig was driven, not on
the IEM's tuning, so each band is expressed as a deviation from a flat
mid-band reference instead:

    bass_gain     = avg(20-250 Hz)    - avg(500-2000 Hz)
    presence_gain = avg(2000-6000 Hz) - avg(500-2000 Hz)
    treble_gain   = avg(6000-16000 Hz)- avg(500-2000 Hz)

Two measurements of the same IEM at different volumes then produce the
same numbers, which is what makes them comparable across the catalogue.
"""

MID_REFERENCE_BAND = (500, 2000)
BASS_BAND = (20, 250)
PRESENCE_BAND = (2000, 6000)
TREBLE_BAND = (6000, 16000)

FREQUENCY_DECIMALS = 1
SPL_DECIMALS = 2

# REW header lines start with this and carry no measurement data.
COMMENT_PREFIX = "*"
MIN_COLUMNS = 2


def parse_rew_file(path):
    """Every (frequency, SPL, phase) point in a REW export."""
    points = []
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        for line in f:
            point = _parse_point(line)
            if point:
                points.append(point)
    return points


def _parse_point(line):
    """One measurement point, or None if the line doesn't hold one."""
    line = line.strip()
    if not line or line.startswith(COMMENT_PREFIX):
        return None

    columns = line.split()
    if len(columns) < MIN_COLUMNS:
        return None

    try:
        phase = float(columns[2]) if len(columns) > MIN_COLUMNS else None
        return float(columns[0]), float(columns[1]), phase
    except ValueError:
        return None


def _band_average(points, low_hz, high_hz):
    """Mean SPL across a frequency range, or None if it isn't covered."""
    levels = [spl for freq, spl, _ in points if low_hz <= freq <= high_hz]
    if not levels:
        return None
    return sum(levels) / len(levels)


def serialize_curve(points):
    """
    The curve as [[freq, spl], ...] for storage and graphing.

    Phase is dropped — a standard FR chart doesn't use it, and leaving it
    out keeps the stored JSON smaller.
    """
    return [
        [round(freq, FREQUENCY_DECIMALS), round(spl, SPL_DECIMALS)]
        for freq, spl, _ in points
    ]


def compute_gains(points):
    """
    The three band gains, each relative to the mid-band reference.

    A band the measurement doesn't cover comes back as None rather than
    a misleading zero.
    """
    reference = _band_average(points, *MID_REFERENCE_BAND)
    if reference is None:
        return {"bass_gain": None, "presence_gain": None, "treble_gain": None}

    return {
        "bass_gain": _relative_to(points, BASS_BAND, reference),
        "presence_gain": _relative_to(points, PRESENCE_BAND, reference),
        "treble_gain": _relative_to(points, TREBLE_BAND, reference),
    }


def _relative_to(points, band, reference):
    """How much louder this band is than the reference, in dB."""
    level = _band_average(points, *band)
    if level is None:
        return None
    return round(level - reference, SPL_DECIMALS)


if __name__ == "__main__":
    import sys

    path = sys.argv[1] if len(sys.argv) > 1 else "sample_measurement.txt"
    points = parse_rew_file(path)

    print(f"Parsed {len(points)} data points from {path}")
    print(f"Frequency range: {points[0][0]:.1f} Hz - {points[-1][0]:.1f} Hz")

    low, high = MID_REFERENCE_BAND
    print(f"\nComputed gains (relative to {low}-{high} Hz reference):")
    for band, value in compute_gains(points).items():
        print(f"  {band}: {value:+.2f} dB" if value is not None
              else f"  {band}: N/A")
