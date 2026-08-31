<?php

/**
 * Rounding that matches Python's.
 *
 * PHP and Python disagree about what to do with an exact half, and the
 * disagreement is not a bug in either — they picked different conventions:
 *
 *     round(-5.25, 1)     PHP     -> -5.3   (half away from zero)
 *                         Python  -> -5.2   (half to even, "banker's")
 *
 * That matters here because the listening test lands on exact halves
 * routinely. Bounds are repeatedly halved from whole numbers, so a
 * midpoint like -5.25 is not a floating-point artefact — it is the
 * arithmetically exact answer, and it sits precisely on the tie.
 *
 * The port has to reproduce the Python, not merely come close. Profiles
 * already in auditory_profiles were produced by it, and a band that
 * differs by 0.1 dB from the one the original would have given is a
 * different profile feeding a different ranking. Inaudible, and still
 * wrong.
 *
 * PHP has the mode built in; it simply is not the default.
 *
 * One caveat worth recording: this agrees with Python only where the value
 * is exactly representable in binary. For a number like 2.675 — actually
 * 2.67499... in floating point — neither language rounds what you typed.
 * Every value in this application is a sum or halving of small integers,
 * so all of them are exact and the caveat never bites.
 */

const PY_GAIN_DECIMALS = 1;

function py_round($value, $precision = 0) {
    return round((float)$value, $precision, PHP_ROUND_HALF_EVEN);
}
