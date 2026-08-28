"""Responsible-listening guidance, chosen from the preference target.

This is the last section of the results page and the least important one to
the rest of the system. It reads the preference target; it never writes to
it. Nothing here can reach the target curve, the region analysis, or the
IEM ranking — `build()` takes a profile and returns text, and that is the
whole of its contract with the application.

Rules this module is written to, in order of importance:

  1. Never diagnose. A preference for less treble is a preference for less
     treble. It is not evidence of hearing loss, tinnitus, sensitivity, or
     anything else about the person's ears, and nothing here may imply
     otherwise.

  2. Never criticise the preference. There is no wrong answer to give in a
     listening test. The guidance is about playback level, which is a
     separate thing from the shape of the curve.

  3. Say what is actually known. The one numeric reference here is the
     WHO/ITU safe-listening standard, quoted as what it is.

The shape of every message is the same: here is what you like, and here is
how to enjoy it without raising your exposure more than you need to.
"""

import preference_profile as pp

# Bands are described by the level keys produced by preference_profile.
ELEVATED = ("slight_up", "up", "strong_up")
REDUCED = ("slight_down", "down", "strong_down")

# Only flag bass once the preference is beyond "slightly" — a small lift is
# not worth a paragraph of its own.
NOTABLE = ("up", "strong_up", "down", "strong_down")


def _find(regions, band):
    for region in regions:
        if region["band"] == band:
            return region
    return None


def _treble_note(region):
    """One paragraph about the top end, keyed to which way it leans."""
    level = region["level"]

    if level in ELEVATED:
        return {
            "band": "treble_gain",
            "title": "About your treble preference",
            "body": (
                "Your profile shows a preference for more energy in the treble. "
                "A brighter top end can make music feel more detailed and open, "
                "and it is simply an audio preference — it says nothing about "
                "your hearing. Worth knowing, though: when people want more "
                "detail, the volume knob is usually closer to hand than the EQ. "
                "You already have the EQ. Let it do that work, and keep the "
                "overall level where it is comfortable."
            ),
        }

    if level in REDUCED:
        return {
            "band": "treble_gain",
            "title": "About your treble preference",
            "body": (
                "Your profile leans towards a smoother top end. That is a common "
                "preference and a perfectly ordinary one to have. One practical "
                "note: a smoother presentation takes the edge off, which can make "
                "a loud playback level feel gentler than it measures. Since it is "
                "the level rather than the tuning that adds up over time, it is "
                "still worth keeping an eye on the volume and taking breaks."
            ),
        }

    return {
        "band": "treble_gain",
        "title": "About your treble preference",
        "body": (
            "Your treble preference sits close to the reference, so there is "
            "nothing specific to flag here. The general reminders below are "
            "the same ones that apply to any listener."
        ),
    }


def _bass_note(region):
    """Only returned when the low-end preference is pronounced."""
    if region["level"] not in NOTABLE or region["direction"] != "up":
        return None

    return {
        "band": "bass_gain",
        "title": "About your bass preference",
        "body": (
            "Your profile favours extra low-end energy. Bass carries a lot of "
            "acoustic energy without necessarily sounding loud, so a bass-forward "
            "setting can be running at a higher overall level than it feels like. "
            "Nothing wrong with the preference — just a reason to let comfortable "
            "volume and regular breaks do their job."
        ),
    }


# General guidance. Shown to everyone, identical for everyone, and written
# so that no item reads as a response to anything in the person's profile.
TIPS = [
    ("Reach for the EQ before the volume",
     "If you want more detail, shape it rather than turning everything up. "
     "That is what the profile above is for."),

    ("Take breaks on long sessions",
     "Exposure accumulates over a day, not just over a track. Stepping away "
     "for a few minutes now and then gives your ears a rest."),

    ("Quiet surroundings let you listen quieter",
     "Most people turn up to compete with background noise. Well-fitting IEMs "
     "block a lot of it, which means you can hear everything at a lower level."),

    ("Be careful around sudden loud sounds",
     "Sirens, power tools, air brakes and the like are loud and unannounced. "
     "Worth being ready for, headphones or not."),

    ("If it hurts, turn it down",
     "Discomfort or pain is a reason to stop or reduce, not something to push "
     "through or compensate for with more volume."),
]

# The one number in this section, quoted as what it is rather than turned
# into a personal target. WHO and ITU set this jointly in ITU-T H.870.
STANDARD_NOTE = (
    "As a general reference point, the WHO/ITU safe-listening standard "
    "(ITU-T H.870) suggests adults keep to about 80 dB over a 40-hour week, "
    "and 75 dB for children and more sensitive listeners. Most phones can "
    "show you your own listening level if you go looking for it."
)

DISCLAIMER = (
    "This section is general listening guidance, not medical advice. "
    "EqualizeME measures what you prefer, not how you hear — nothing in your "
    "profile is a hearing test or an indication of a hearing condition. "
    "If you have concerns about your hearing, a qualified audiologist or "
    "doctor is the right place to take them."
)


def build(profile):
    """Guidance for this profile. Reads the target; changes nothing."""
    if not profile:
        return None

    regions = profile.get("regions") or pp.analyse_regions(profile)

    notes = []
    bass = _find(regions, "bass_gain")
    if bass:
        note = _bass_note(bass)
        if note:
            notes.append(note)

    treble = _find(regions, "treble_gain")
    if treble:
        notes.append(_treble_note(treble))

    return {
        "title": "Hearing Preservation",
        "intro": (
            "Everything above is about what you like. This last part is about "
            "listening to it comfortably over the long run."
        ),
        "notes": notes,
        "tips": [{"title": t, "body": b} for t, b in TIPS],
        "standard_note": STANDARD_NOTE,
        "disclaimer": DISCLAIMER,
    }
