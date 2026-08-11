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

/**
 * Returns an array of human-readable problems. Empty array means the
 * password is acceptable.
 *
 * All failures are collected rather than returning on the first one, so
 * the user can fix everything in a single attempt instead of playing
 * whack-a-mole.
 */
function password_problems($password, $email = '', $name = '') {
    $problems = [];

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $problems[] = "be at least " . PASSWORD_MIN_LENGTH . " characters long";
    }

    if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)) {
        $problems[] = "include both uppercase and lowercase letters";
    }

    if (!preg_match('/\d/', $password)) {
        $problems[] = "include at least one number";
    }

    if (preg_match('/^\s|\s$/', $password)) {
        $problems[] = "not start or end with a space";
    }

    $lower = strtolower($password);

    if (in_array($lower, array_map('strtolower', PASSWORD_BLOCKLIST), true)) {
        $problems[] = "not be a commonly used password";
    }

    // Reusing your own name or email local-part makes a password trivially
    // guessable by anyone who knows who you are.
    $emailLocal = strtolower(trim(explode('@', $email)[0] ?? ''));
    if ($emailLocal !== '' && strlen($emailLocal) >= 4 && strpos($lower, $emailLocal) !== false) {
        $problems[] = "not contain your email address";
    }

    $nameTrimmed = strtolower(trim($name));
    if ($nameTrimmed !== '' && strlen($nameTrimmed) >= 4 && strpos($lower, $nameTrimmed) !== false) {
        $problems[] = "not contain your name";
    }

    // A single repeated character, e.g. "aaaaaaaa".
    if (preg_match('/^(.)\1+$/', $password)) {
        $problems[] = "not be the same character repeated";
    }

    return $problems;
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
