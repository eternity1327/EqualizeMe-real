<?php
/**
 * Authenticated proxy to the Python DSP service.
 *
 * WHY THIS EXISTS
 *
 * ai_service.py takes the user id straight from the URL or request body and
 * does no session check of its own. Reached directly, anyone could request
 * /recommendations/3 and read someone else's profile, or start a test as
 * another user and overwrite theirs.
 *
 * Rather than build a second authentication system inside Flask, every call
 * now goes through here. This file already has everything it needs: the
 * session layer that the rest of the API uses, the CSRF token, and the
 * user id that PHP trusts because it came from the session cookie rather
 * than from the request.
 *
 * The user id is INJECTED here and any client-supplied one is discarded, so
 * a caller cannot name whose data they want. Flask is then bound to
 * 127.0.0.1, which means this file is the only way in.
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/csrf.php";
start_secure_session();

header("Content-Type: application/json");

const DSP_HOST = "127.0.0.1";
const DSP_PORT = 5001;
const DSP_TIMEOUT_SECONDS = 20;

/**
 * Whitelist of upstream routes.
 *
 * Anything not listed here cannot be reached, so adding a route to Flask
 * does not silently expose it to the browser.
 *
 *   method      HTTP verb this route accepts
 *   path        upstream path; {user_id} is replaced from the session
 *   needsAuth   require a logged-in session
 *   needsCsrf   require a valid CSRF token (state-changing routes)
 *   injectUser  overwrite user_id in the JSON body with the session's
 */
const ROUTES = [
    "quiz-questions" => [
        "method" => "GET",  "path" => "/api/quiz/questions",
        "needsAuth" => true, "needsCsrf" => false, "injectUser" => false,
    ],
    "adaptive-samples" => [
        "method" => "GET",  "path" => "/api/dsp/adaptive/samples",
        "needsAuth" => true, "needsCsrf" => false, "injectUser" => false,
    ],
    "adaptive-start" => [
        "method" => "POST", "path" => "/api/dsp/adaptive/start",
        "needsAuth" => true, "needsCsrf" => true,  "injectUser" => true,
    ],
    "adaptive-play" => [
        "method" => "POST", "path" => "/api/dsp/adaptive/play",
        "needsAuth" => true, "needsCsrf" => true,  "injectUser" => true,
    ],
    "adaptive-answer" => [
        "method" => "POST", "path" => "/api/dsp/adaptive/answer",
        "needsAuth" => true, "needsCsrf" => true,  "injectUser" => true,
    ],
    "recommendations" => [
        "method" => "GET",  "path" => "/recommendations/{user_id}",
        "needsAuth" => true, "needsCsrf" => false, "injectUser" => false,
    ],
    "iem-curve" => [
        "method" => "GET",  "path" => "/api/iems/{id}/curve",
        "needsAuth" => true, "needsCsrf" => false, "injectUser" => false,
    ],
];

function fail($code, $message) {
    http_response_code($code);
    echo json_encode(["error" => $message]);
    exit;
}

$routeName = $_GET["route"] ?? "";
if (!isset(ROUTES[$routeName])) {
    fail(404, "Unknown route");
}
$route = ROUTES[$routeName];

if ($_SERVER["REQUEST_METHOD"] !== $route["method"]) {
    fail(405, "Method not allowed for this route");
}

if ($route["needsAuth"] && !isset($_SESSION["user_id"])) {
    fail(401, "Not logged in");
}

$body = null;
if ($route["method"] === "POST") {
    $body = json_decode(file_get_contents("php://input"), true);
    if (!is_array($body)) {
        $body = [];
    }

    if ($route["needsCsrf"]) {
        csrf_verify_or_fail($body["csrf_token"] ?? null);
    }
    unset($body["csrf_token"]);

    if ($route["injectUser"]) {
        // Whatever the client sent is discarded. This is the line that makes
        // the whole proxy worthwhile.
        $body["user_id"] = $_SESSION["user_id"];
    }
}

$path = build_upstream_path($route["path"]);
[$status, $responseBody] = call_dsp($route["method"], $path, $body);

http_response_code($status);
echo $responseBody;

/**
 * Fill placeholders in an upstream path from the session, or from request
 * parameters that have been validated as integers.
 */
function build_upstream_path($template) {
    $path = str_replace("{user_id}", (string)($_SESSION["user_id"] ?? ""), $template);

    if (strpos($path, "{id}") !== false) {
        $id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            fail(400, "A numeric id is required for this route");
        }
        $path = str_replace("{id}", (string)$id, $path);
    }

    return $path;
}

/**
 * Perform the upstream request. Uses cURL when the extension is available
 * and falls back to a stream context otherwise, so this works on a default
 * XAMPP install either way.
 */
function call_dsp($method, $path, $body) {
    $url = "http://" . DSP_HOST . ":" . DSP_PORT . $path;
    $payload = $body === null ? null : json_encode($body);

    if (function_exists("curl_init")) {
        return call_dsp_curl($method, $url, $payload);
    }

    return call_dsp_stream($method, $url, $payload);
}

function call_dsp_curl($method, $url, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => DSP_TIMEOUT_SECONDS,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Content-Length: " . strlen($payload),
        ]);
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status === 0) {
        return [503, json_encode([
            "error" => "Could not reach the audio service. Is ai_service.py running?",
            "detail" => $error ?: null,
        ])];
    }

    return [$status, $response];
}

function call_dsp_stream($method, $url, $payload) {
    $headers = ["Content-Type: application/json"];
    $options = [
        "http" => [
            "method" => $method,
            "header" => implode("\r\n", $headers),
            "timeout" => DSP_TIMEOUT_SECONDS,
            "ignore_errors" => true,
        ],
    ];
    if ($payload !== null) {
        $options["http"]["content"] = $payload;
    }

    $response = @file_get_contents($url, false, stream_context_create($options));

    if ($response === false) {
        return [503, json_encode([
            "error" => "Could not reach the audio service. Is ai_service.py running?",
        ])];
    }

    return [status_from_headers($http_response_header ?? []), $response];
}

function status_from_headers($headers) {
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
            return (int)$matches[1];
        }
    }
    return 200;
}
