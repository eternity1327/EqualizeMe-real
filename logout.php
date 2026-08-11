<?php
require_once __DIR__ . "/api/session.php";
start_secure_session();
end_secure_session();
header("Location: index.html");
exit;
