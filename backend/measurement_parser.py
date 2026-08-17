MID_REFERENCE_BAND = (500, 2000)
BASS_BAND = (20, 250)
PRESENCE_BAND = (2000, 6000)
TREBLE_BAND = (6000, 16000)

FREQUENCY_DECIMALS = 1
SPL_DECIMALS = 2

COMMENT_PREFIX = "*"
MIN_COLUMNS = 2


def parse_rew_file(path):
    points = []
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        for line in f:
            point = _parse_point(line)
            if point:
                points.append(point)
    return points


def _parse_point(line):
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
    levels = [spl for freq, spl, _ in points if low_hz <= freq <= high_hz]
    if not levels:
        return None
    return sum(levels) / len(levels)


def serialize_curve(points):
    return [
        [round(freq, FREQUENCY_DECIMALS), round(spl, SPL_DECIMALS)]
        for freq, spl, _ in points
    ]


def compute_gains(points):
    reference = _band_average(points, *MID_REFERENCE_BAND)
    if reference is None:
        return {"bass_gain": None, "presence_gain": None, "treble_gain": None}

    return {
        "bass_gain": _relative_to(points, BASS_BAND, reference),
        "presence_gain": _relative_to(points, PRESENCE_BAND, reference),
        "treble_gain": _relative_to(points, TREBLE_BAND, reference),
    }


def _relative_to(points, band, reference):
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
