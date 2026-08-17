<?php

const PASSWORD_MIN_LENGTH = 8;

const PASSWORD_MAX_BYTES = 72;

const PASSWORD_BLOCKLIST = [
    'password', 'password1', 'password123', 'passw0rd',
    'qwerty', 'qwerty123', 'qwertyuiop',
    '12345678', '123456789', '1234567890',
    'iloveyou', 'admin123', 'letmein', 'welcome1',
    'abc12345', 'football', 'monkey123', 'sunshine',
    'equalizeme', 'equalize123',
];

const MIN_PERSONAL_TERM_LENGTH = 4;

function _contains_personal_term($lowerPassword, $term) {
    $term = strtolower(trim($term));

    return $term !== ''
        && strlen($term) >= MIN_PERSONAL_TERM_LENGTH
        && strpos($lowerPassword, $term) !== false;
}

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

function _guessability_problems($password, $email, $name) {
    $problems = [];
    $lower = strtolower($password);

    if (in_array($lower, array_map('strtolower', PASSWORD_BLOCKLIST), true)) {
        $problems[] = "not be a commonly used password";
    }

    if (_contains_personal_term($lower, explode('@', $email)[0] ?? '')) {
        $problems[] = "not contain your email address";
    }

    if (_contains_personal_term($lower, $name)) {
        $problems[] = "not contain your name";
    }

    if (preg_match('/^(.)\1+$/', $password)) {
        $problems[] = "not be the same character repeated";
    }

    return $problems;
}

function password_problems($password, $email = '', $name = '') {
    return array_merge(
        _length_problems($password),
        _composition_problems($password),
        _guessability_problems($password, $email, $name)
    );
}

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
