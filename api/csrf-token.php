<?php

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/csrf.php";
start_secure_session();

header("Content-Type: application/json");
header("Cache-Control: no-store");

echo json_encode(["token" => csrf_token()]);
