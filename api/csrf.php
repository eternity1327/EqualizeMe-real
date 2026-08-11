<?php
/**
 * CSRF protection for the PHP endpoints.
 *
 * SameSite=Lax on the session cookie already blocks the common case
 * (another site silently POSTing to ours using the visitor's logged-in
 * session), but it isn't airtight — it doesn't cover same-site
 * subdomains, and older browsers ignore it. A per-session token that the
 * attacker's page has no way to read closes the remaining gap.
 *
 * Flow: the frontend fetches the token from api/csrf-token.php, then
 * sends it back on every state-changing request in the X-CSRF-Token
 * header. Read-only endpoints don't need it.
 *
 * NOTE: this covers the PHP API only. The Flask service on port 5001 is
 * a separate origin and is protected by its ALLOWED_ORIGIN CORS setting
 * instead.
 */

function csrf_token() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Reads the token the client sent, from either the header (fetch/AJAX)
 * or a form field (regular form posts).
 */
function csrf_submitted_token() {
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    if (!empty($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }

    // JSON bodies: the request body has already been read by the caller in
    // most cases, so this only works when the caller passes it explicitly
    // via csrf_verify_or_fail($tokenFromBody).
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

    // Constant-time comparison — a plain === can leak how much of the
    // token matched via timing differences.
    return hash_equals($expected, $submitted);
}

/**
 * Verifies and halts with 403 if the token is missing or wrong.
 * Pass the token explicitly when it arrived in a JSON body.
 */
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
