<?php

/**
 * Error responses that say something useful in development and nothing
 * useful to an attacker in production.
 *
 * Every endpoint here catches PDOException and returns a fixed sentence —
 * "Something went wrong logging in" — while the real message goes to
 * error_log(). That is correct on a server whose logs you can read.
 *
 * On shared hosting you cannot read them. The result is a site that fails
 * with a message carrying no information, and no way to find out more:
 * you end up guessing between an empty database, a wrong password and a
 * wrong hostname, all of which look identical from outside.
 *
 * So in development the real message is included in the response. In
 * production it is not, because a database error can quote table names,
 * column names and fragments of the query — free reconnaissance.
 *
 * The environment check is the only thing keeping those apart, which is
 * why 'environment' => 'production' matters more than it looks.
 */

require_once __DIR__ . "/config.php";

/**
 * Send a JSON error and stop.
 *
 * $public is what everyone sees. $e is the real cause, attached only in
 * development, and always written to the log either way.
 */
function fail_json($status, $public, $e = null, $context = "") {
    if ($e !== null) {
        error_log(trim($context . ": " . $e->getMessage(), ": "));
    }

    http_response_code($status);
    header("Content-Type: application/json");

    $body = ["error" => $public];

    if ($e !== null && !is_production()) {
        // Present only when 'environment' is development. Deploy with it
        // set to production and this key disappears.
        $body["debug"] = [
            "message" => $e->getMessage(),
            "type" => get_class($e),
            "hint" => _debug_hint($e),
        ];
    }

    echo json_encode($body);
    exit;
}


/**
 * Turn the common database failures into the sentence you would otherwise
 * have to go and look up.
 */
function _debug_hint($e) {
    $message = $e->getMessage();

    if (stripos($message, "Base table or view not found") !== false
        || stripos($message, "doesn't exist") !== false) {
        return "The database is reachable but a table is missing. The schema "
            . "has not been imported, or a migration in sql/ has not been run.";
    }

    if (stripos($message, "Unknown column") !== false) {
        return "A column is missing, so one of the migrations in sql/ has not "
            . "been run. Compare the column named above against sql/.";
    }

    if (stripos($message, "Access denied") !== false) {
        return "The database rejected the username or password in "
            . "api/config.local.php.";
    }

    if (stripos($message, "Unknown database") !== false) {
        return "The database name in api/config.local.php does not exist on "
            . "this server. On shared hosting it is usually prefixed, e.g. "
            . "if0_12345678_equalizeme.";
    }

    if (stripos($message, "getaddrinfo") !== false
        || stripos($message, "No such host") !== false
        || stripos($message, "Connection refused") !== false
        || stripos($message, "php_network_getaddresses") !== false) {
        return "The database host in api/config.local.php could not be reached. "
            . "On shared hosting it is not 'localhost' — it is a specific "
            . "sql###.host.com given in the control panel.";
    }

    return "";
}
