<?php

function csrf_token() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_submitted_token() {
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    if (!empty($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }

    return null;
}

function csrf_is_valid($submitted = null) {
    $expected = $_SESSION['_csrf_token'] ?? null;
    if (!$expected) {
        return false;
    }

    $submitted = $submitted ?? csrf_submitted_token();
    if (!is_string($submitted) || $submitted === '') {
        return false;
    }

    return hash_equals($expected, $submitted);
}

function csrf_verify_or_fail($submitted = null) {
    if (csrf_is_valid($submitted)) {
        return;
    }

    http_response_code(403);
    header("Content-Type: application/json");
    echo json_encode([
        "error" => "Your session token was missing or expired. Please refresh the page and try again.",
    ]);
    exit;
}
