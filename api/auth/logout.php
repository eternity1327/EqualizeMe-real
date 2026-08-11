<?php
require_once __DIR__ . "/../session.php";
start_secure_session();
header("Content-Type: application/json");

end_secure_session();

echo json_encode(["status" => "logged out"]);
