<?php
require __DIR__ . "/../session.php";
start_secure_session();
header("Content-Type: application/json");

$_SESSION = [];
session_destroy();

echo json_encode(["status" => "logged out"]);
