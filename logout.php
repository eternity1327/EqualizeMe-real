<?php
require __DIR__ . "/api/session.php";
start_secure_session();
$_SESSION = [];
session_destroy();
header("Location: index.html");
exit;
