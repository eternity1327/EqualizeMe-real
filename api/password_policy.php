<?php
/**
 * Shared password rules, used by both registration and password reset so
 * the two can't drift apart and let a weak password in through the back
 * door.
 *
 * The rules aim for "meaningfully hard to guess" without being so fussy
 * that people write their password on a sticky note. Length does the
 * heaviest lifting — a long passphrase beats a short cryptic string.
 */

const PASSWORD_MIN_LENGTH = 8;

// bcrypt reads only the first 72 BYTES and silently ignores the rest, so
// a longer passphrase would look accepted while everything past byte 72
// counted for nothing. Bytes, not characters — accented letters and emoji
// take several each. Rejecting explicitly beats truncating quietly.
const PASSWORD_MAX_BYTES = 72;

// Passwords that technically satisfy the character rules but are among
// the first things any attacker tries. Compared case-insensitively.
const PASSWORD_BLOCKLIST = [
    'password', 'password1', 'password123', 'passw0rd',
    'qwerty', 'qwerty123', 'qwertyuiop',
    '12345678', '123456789', '1234567890',
    'iloveyou', 'admin123', 'letmein', 'welcome1',
    'abc12345', 'football', 'monkey123', 'sunshine',
    'equalizeme', 'equalize123',
];

// Personal details shorter than this appear inside ordinary words too
// often to treat as a match.
const MIN_PERSONAL_TERM_LENGTH = 4;

/**
 * True when the password contains a personal detail long enough to be a
 * real match rather than a coincidence.
 */
function _contains_personal_term($lowerPassword, $term) {
    $term = strtolower(trim($term));

    return $term !== ''
        && strlen($term) >= MIN_PERSONAL_TERM_LENGTH
        && strpos($lowerPassword, $term) !== false;
}

/**
 * The problems with a password's length. Byte-based on purpose: strlen()
 * counts bytes, which is what bcrypt's limit is measured in, whereas
 * mb_strlen() would undercount multi-byte characters and let an
 * over-long password through.
 */
function _length_problems($password) {
    $problems = [];

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $problems[] = "be at least " . PASSWORD_MIN_LENGTH . " characters long";
    }

    if (strlen($password) > PASSWORD_MAX_BYTES) {
        $problems[] = "be no longer than " . PASSWORD_MAX_BYTES
            . " characters (longer passwords aren't fully used by the encryption)";
    }

    return $problems;
}

/**
 * The problems with a password's mix of characters.
 */
function _composition_problems($password) {
    $problems = [];

    if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)) {
        $problems[] = "include both uppercase and lowercase letters";
    }

    if (!preg_match('/\d/', $password)) {
        $problems[] = "include at least one number";
    }

    if (preg_match('/^\s|\s$/', $password)) {
        $problems[] = "not start or end with a space";
    }

    return $problems;
}

/**
 * The problems that make a password easy to guess even when it satisfies
 * the character rules.
 */
function _guessability_problems($password, $email, $name) {
    $problems = [];
    $lower = strtolower($password);

    if (in_array($lower, array_map('strtolower', PASSWORD_BLOCKLIST), true)) {
        $problems[] = "not be a commonly used password";
    }

    // A name or email anyone who knows the user could try first.
    if (_contains_personal_term($lower, explode('@', $email)[0] ?? '')) {
        $problems[] = "not contain your email address";
    }

    if (_contains_personal_term($lower, $name)) {
        $problems[] = "not contain your name";
    }

    // One character repeated, e.g. "aaaaaaaa".
    if (preg_match('/^(.)\1+$/', $password)) {
        $problems[] = "not be the same character repeated";
    }

    return $problems;
}

/**
 * Every human-readable problem with a password; empty means acceptable.
 *
 * All failures are collected rather than returning on the first, so the
 * user can fix everything in one attempt instead of playing whack-a-mole.
 */
function password_problems($password, $email = '', $name = '') {
    return array_merge(
        _length_problems($password),
        _composition_problems($password),
        _guessability_problems($password, $email, $name)
    );
}

/**
 * Turns the problem list into one sentence suitable for showing directly
 * to the user, e.g. "Password must be at least 8 characters long and
 * include at least one number."
 */
function password_error_message(array $problems) {
    if (!$problems) {
        return '';
    }

    if (count($problems) === 1) {
        return "Password must " . $problems[0] . ".";
    }

    $last = array_pop($problems);
    return "Password must " . implode(", ", $problems) . " and " . $last . ".";
}
