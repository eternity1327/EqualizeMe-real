"""
measurement_parser.py
----------------------
Parses REW-format frequency response measurement files (the raw
Freq/SPL/Phase sweep exports squig.link graphs are built from) and
reduces each curve down to the bass/treble/presence "gain" numbers
that EqualizeME's auditory_profiles / iems tables use.

IMPORTANT ASSUMPTION (please sanity-check against your project):
Raw SPL depends on measurement volume, not just the IEM's tuning, so
absolute SPL isn't a usable "gain". Instead, each band is expressed
as a deviation from a flat mid-band reference (500Hz-2kHz), which is
the standard way to describe tonal balance from a raw FR curve:

    bass_gain     = avg_SPL(20-250 Hz)    - avg_SPL(500-2000 Hz)
    presence_gain = avg_SPL(2000-6000 Hz) - avg_SPL(500-2000 Hz)
    treble_gain   = avg_SPL(6000-16000 Hz)- avg_SPL(500-2000 Hz)

Adjust BASS_BAND / PRESENCE_BAND / TREBLE_BAND / MID_REFERENCE_BAND
below if your adaptive test / auditory_profiles model defines these
ranges differently.
"""

MID_REFERENCE_BAND = (500, 2000)
BASS_BAND = (20, 250)
PRESENCE_BAND = (2000, 6000)
TREBLE_BAND = (6000, 16000)


def parse_rew_file(path):
    """
    Parse a REW-exported .txt measurement file.
    Returns a list of (freq_hz, spl_db, phase_deg) tuples.
    Lines starting with '*' are header/comment lines and are skipped.
    """
    points = []
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("*"):
                continue
            parts = line.split()
            if len(parts) < 2:
                continue
            try:
                freq = float(parts[0])
                spl = float(parts[1])
                phase = float(parts[2]) if len(parts) > 2 else None
            except ValueError:
                continue
            points.append((freq, spl, phase))
    return points


def _band_average(points, low_hz, high_hz):
    vals = [spl for freq, spl, _phase in points if low_hz <= freq <= high_hz]
    if not vals:
        return None
    return sum(vals) / len(vals)


def serialize_curve(points):
    """
    Reduce a parsed measurement to [[freq, spl], ...] pairs for
    storage/graphing -- drops phase (not needed for a standard FR
    chart) to keep the stored JSON smaller.
    """
    return [[round(freq, 1), round(spl, 2)] for freq, spl, _phase in points]


def compute_gains(points):
    """
    Reduce a parsed FR curve to {bass_gain, presence_gain, treble_gain},
    each relative to the mid-band reference. Returns None for any band
    where the measurement doesn't cover that frequency range.
    """
    mid_ref = _band_average(points, *MID_REFERENCE_BAND)
    if mid_ref is None:
        return {"bass_gain": None, "presence_gain": None, "treble_gain": None}

    bass = _band_average(points, *BASS_BAND)
    presence = _band_average(points, *PRESENCE_BAND)
    treble = _band_average(points, *TREBLE_BAND)

    return {
        "bass_gain": round(bass - mid_ref, 2) if bass is not None else None,
        "presence_gain": round(presence - mid_ref, 2) if presence is not None else None,
        "treble_gain": round(treble - mid_ref, 2) if treble is not None else None,
    }


if __name__ == "__main__":
    import sys
    path = sys.argv[1] if len(sys.argv) > 1 else "sample_measurement.txt"
    points = parse_rew_file(path)
    print(f"Parsed {len(points)} data points from {path}")
    print(f"Frequency range: {points[0][0]:.1f} Hz - {points[-1][0]:.1f} Hz")
    gains = compute_gains(points)
    low, high = MID_REFERENCE_BAND
    print(f"\nComputed gains (relative to {low}-{high} Hz reference):")
    for band, val in gains.items():
        print(f"  {band}: {val:+.2f} dB" if val is not None else f"  {band}: N/A")
