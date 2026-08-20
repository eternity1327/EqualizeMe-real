<?php
require_once __DIR__ . "/api/session.php";
start_secure_session();

header("Location: login.php?tab=register", true, 301);
exit;
