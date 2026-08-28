"""Aggregation and analysis layers for the longitudinal preference profile.

The listening test produces one set of numbers per sitting. Those sittings
are evidence, not answers. This module turns the whole history into a single
current Preference Target, and then reads that target back out in words.

    assessments  ->  aggregate()  ->  preference target
                                          |
                                          +-> analyse_regions()  -> per-band verdicts
                                          +-> describe()         -> readable sentences

Two rules the rest of the system depends on:

  * There is exactly one Preference Target per user. A new assessment
    refines it; it never creates a second one. The individual rows stay in
    auditory_profiles as history and are never rewritten.

  * The target is the authoritative representation of what the user
    prefers. The region verdicts are an interpretation of it, and the
    recommendations are an application of it. Nothing downstream may edit
    the target.

Only the three bands the listening test actually measures are reported.
Midrange is the reference the other bands are expressed against, so it has
no measured value of its own, and the 8 kHz shelf covers the whole top end,
so "treble" and "air" are not separable from this data. Reporting them as
two findings would be inventing a distinction the measurement cannot make.
"""

import math

# Band keys as stored in auditory_profiles, in the order they are reported.
BANDS = ("bass_gain", "presence_gain", "treble_gain")

BAND_LABELS = {
    "bass_gain": "Bass",
    "presence_gain": "Upper midrange",
    "treble_gain": "Treble",
}

# What each band actually covers, for the interface. These follow from the
# filters the listening test uses: a 100 Hz low shelf, a 3 kHz peak at
# Q 1.4, and an 8 kHz high shelf.
BAND_RANGES = {
    "bass_gain": "below ~250 Hz",
    "presence_gain": "~2-5 kHz",
    "treble_gain": "above ~6 kHz",
}

# ─────────────────────────────────────────────────────────── weighting

# An assessment's weight is how much it should count towards the target:
#
#     weight = confidence  x  recency
#
# Confidence comes from the test itself — adaptive_test.confidence_score()
# reports how tightly each run actually narrowed the bands, so a run that
# ended up vague genuinely deserves less say than a decisive one.
#
# Recency is a half-life rather than a cutoff. Ears, gear and taste all
# drift; a test taken a year ago on laptop speakers should not carry the
# same authority as one taken yesterday on the headphones being matched.
# Fading is gentler than discarding — an old assessment keeps contributing,
# just less, and never disappears from the history.
RECENCY_HALF_LIFE_DAYS = 180.0

# Floor on the confidence part, so a single poor run cannot be rounded to
# zero influence and silently vanish from an otherwise short history.
MIN_CONFIDENCE_WEIGHT = 0.1

GAIN_DECIMALS = 2


def _confidence_weight(confidence):
    if confidence is None:
        return 1.0
    try:
        value = float(confidence) / 100.0
    except (TypeError, ValueError):
        return 1.0
    return max(MIN_CONFIDENCE_WEIGHT, min(1.0, value))


def _recency_weight(age_days):
    # MySQL hands back a Decimal here, which will not divide by a float.
    # Coerce rather than assume the caller normalised it.
    try:
        days = float(age_days)
    except (TypeError, ValueError):
        return 1.0
    if days <= 0:
        return 1.0
    return 0.5 ** (days / RECENCY_HALF_LIFE_DAYS)


def assessment_weight(confidence, age_days):
    """How much one sitting counts towards the current target."""
    return _confidence_weight(confidence) * _recency_weight(age_days)


def _weighted_mean(pairs):
    total_weight = sum(w for _, w in pairs)
    if total_weight <= 0:
        return 0.0
    return sum(v * w for v, w in pairs) / total_weight


def _weighted_std(pairs, mean):
    """Spread of the observations around the target, same weights.

    This is what tells the difference between "you consistently want more
    bass" and "your bass answers have been all over the place" — two
    histories that can produce an identical average.
    """
    total_weight = sum(w for _, w in pairs)
    if total_weight <= 0 or len(pairs) < 2:
        return 0.0
    variance = sum(w * (v - mean) ** 2 for v, w in pairs) / total_weight
    return math.sqrt(max(0.0, variance))


# ────────────────────────────────────────────────────── aggregation layer

def aggregate(assessments):
    """Fold every assessment into one Preference Target.

    `assessments` is a list of dicts with the band keys, plus optional
    `confidence_score` and `age_days`. Order does not matter.

    Returns the target, the spread behind each band, and enough bookkeeping
    for the interface to say how much evidence it rests on.
    """
    usable = [a for a in assessments if a is not None]
    if not usable:
        return None

    weights = [
        assessment_weight(a.get("confidence_score"), a.get("age_days"))
        for a in usable
    ]

    target, spread = {}, {}
    for band in BANDS:
        pairs = [
            (float(a.get(band) or 0.0), w)
            for a, w in zip(usable, weights)
            if a.get(band) is not None
        ]
        mean = _weighted_mean(pairs)
        target[band] = round(mean, GAIN_DECIMALS)
        spread[band] = round(_weighted_std(pairs, mean), GAIN_DECIMALS)

    total_weight = sum(weights)

    return {
        "target": target,
        "spread": spread,
        "assessment_count": len(usable),
        "total_weight": round(total_weight, 3),
        # How much of the target comes from the single heaviest assessment.
        # 1.0 means one sitting is doing all the work, which the interface
        # uses to avoid claiming a consistent preference from one data point.
        "dominant_share": round(max(weights) / total_weight, 3) if total_weight else 1.0,
    }


# ───────────────────────────────────────────────────────── analysis layer

# Below this, a deviation is not distinguishable from the test's own
# precision and is reported as sitting at the reference. This is not an
# arbitrary round number: a full run locates each band to roughly +/- 0.4
# to +/- 0.8 dB (adaptive_test.confidence_score is derived from exactly
# that), so anything under about three quarters of a decibel is noise
# wearing the costume of a preference.
NEUTRAL_DB = 0.75
SLIGHT_DB = 2.0
STRONG_DB = 4.0

# Spread across a history, in dB, below which the answers count as
# agreeing with each other rather than merely averaging to something.
CONSISTENT_DB = 0.75
FAIRLY_CONSISTENT_DB = 1.5

# Above this share, one assessment is effectively the whole profile, and
# no claim about consistency over time can honestly be made.
SINGLE_SOURCE_SHARE = 0.8

LEVELS = {
    "strong_up": "Strongly elevated",
    "up": "Elevated",
    "slight_up": "Slightly elevated",
    "neutral": "Near reference",
    "slight_down": "Slightly relaxed",
    "down": "Reduced",
    "strong_down": "Strongly reduced",
}


def _level_key(value):
    magnitude = abs(value)
    if magnitude < NEUTRAL_DB:
        return "neutral"
    if magnitude < SLIGHT_DB:
        tier = "slight_"
    elif magnitude < STRONG_DB:
        tier = ""
    else:
        tier = "strong_"
    return f"{tier}up" if value > 0 else f"{tier}down"


def _consistency(spread, assessment_count, dominant_share):
    if assessment_count < 2:
        return "single"
    # More than one sitting on record, but the weighting has landed almost
    # entirely on one of them — usually an old test that has faded next to
    # a fresh one. There are several assessments, so the wording has to
    # stay plural, but no claim of consistency over time is available.
    if dominant_share > SINGLE_SOURCE_SHARE:
        return "dominated"
    if spread <= CONSISTENT_DB:
        return "consistent"
    if spread <= FAIRLY_CONSISTENT_DB:
        return "fairly_consistent"
    return "varied"


def analyse_regions(profile):
    """Read the target back out as one verdict per measured band.

    Everything here is derived from the numbers — no band has a
    pre-written conclusion attached to it.
    """
    if not profile:
        return []

    target, spread = profile["target"], profile["spread"]
    count = profile["assessment_count"]
    dominant = profile["dominant_share"]

    regions = []
    for band in BANDS:
        value = target[band]
        level = _level_key(value)
        regions.append({
            "band": band,
            "label": BAND_LABELS[band],
            "range": BAND_RANGES[band],
            "value": value,
            "spread": spread[band],
            "level": level,
            "level_label": LEVELS[level],
            "direction": (
                "neutral" if level == "neutral"
                else ("up" if value > 0 else "down")
            ),
            "meaningful": level != "neutral",
            "consistency": _consistency(spread[band], count, dominant),
        })
    return regions


# ────────────────────────────────────────────────────── wording, from data

# Only ever describes preference. Never health, never ability, never a
# suggestion that any of these answers is the wrong answer to have given.
_OPENERS = {
    "consistent": "Your assessments consistently",
    "fairly_consistent": "Your assessments generally",
    "varied": "Averaged across your assessments, your answers",
    "dominated": "Weighted towards your most recent test, your answers",
    "single": "Your assessment so far",
}

_VERBS = {
    "up": {
        "strong_up": "favour clearly more energy",
        "up": "favour more energy",
        "slight_up": "lean towards a little more energy",
    },
    "down": {
        "strong_down": "favour clearly less energy",
        "down": "favour less energy",
        "slight_down": "lean towards a little less energy",
    },
}


def _singular(verb):
    """'favour more energy' -> 'favours more energy'.

    Only needed for the one-assessment opener, which is grammatically
    singular where the others are plural.
    """
    head, _, rest = verb.partition(" ")
    return f"{head}s {rest}".rstrip()


def _sentence(region):
    single = region["consistency"] == "single"
    opener = _OPENERS[region["consistency"]]

    if not region["meaningful"]:
        verb = "sits" if single else "sit"
        return (f"{opener} {verb} close to the reference {region['range']}, "
                f"with no clear lean either way.")

    verb = _VERBS[region["direction"]][region["level"]]
    if single:
        return f"{opener} {_singular(verb)} {region['range']}."

    tail = ""
    if region["consistency"] == "varied":
        tail = (" Your answers here have moved around between sittings, "
                "so this one is worth re-checking.")
    return f"{opener} {verb} {region['range']}.{tail}"


def describe(profile, regions=None):
    """The human-readable analysis. Generated entirely from the numbers."""
    if not profile:
        return {"summary": "", "regions": []}

    regions = regions if regions is not None else analyse_regions(profile)
    count = profile["assessment_count"]

    if count == 1:
        summary = ("This profile is based on one listening test. It will get "
                   "sharper each time you take another.")
    else:
        moving = [r["label"].lower() for r in regions if r["consistency"] == "varied"]
        summary = f"This profile combines {count} listening tests, "
        summary += "weighted towards your more recent and more decisive ones."
        if moving:
            summary += (" Your answers for " + _join(moving) +
                        " have varied between sittings.")

    return {
        "summary": summary,
        "regions": [
            {**r, "sentence": _sentence(r)}
            for r in regions
        ],
    }


def _join(items):
    if len(items) == 1:
        return items[0]
    if len(items) == 2:
        return f"{items[0]} and {items[1]}"
    return ", ".join(items[:-1]) + f" and {items[-1]}"


def build(assessments):
    """Everything the results page needs, from raw assessment rows."""
    profile = aggregate(assessments)
    if not profile:
        return None
    regions = analyse_regions(profile)
    return {
        **profile,
        "analysis": describe(profile, regions),
        "regions": regions,
    }
