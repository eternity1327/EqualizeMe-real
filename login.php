<?php
session_start();

$error = "";

// Only allow redirecting to known pages in this project — never to an
// arbitrary URL, to avoid this being abused as an open redirect.
$allowedRedirects = [
    "assessment.php", "index.html", "test.html",
    "recommendations.html", "profile.html", "settings.html"
];
$redirectTarget = $_REQUEST["redirect"] ?? "assessment.php";
if (!in_array($redirectTarget, $allowedRedirects, true)) {
    $redirectTarget = "assessment.php";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $error = "Please enter both email and password.";
    } else {
        // --- Database connection ---
        $host = "127.0.0.1";
        $db   = "equalizeme";
        $user = "root";
        $pass = ""; // XAMPP default: no password

        try {
            $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            $error = "Database connection failed: " . $e->getMessage();
        }

        if (!$error) {
            $stmt = $pdo->prepare("SELECT id, name, password_hash FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $userRow = $stmt->fetch();

            if (!$userRow) {
                $error = "No account found with that email.";
            } elseif (password_verify($password, $userRow["password_hash"])) {
                // Success
                $_SESSION["user_id"] = $userRow["id"];
                $_SESSION["user_name"] = $userRow["name"];
                header("Location: " . $redirectTarget);
                exit;
            } else {
                $error = "Incorrect password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>EqualizeME — Login</title>
</head>
<body>

<h1>Log In</h1>

<?php if ($error): ?>
    <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<form method="POST" action="login.php">
    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectTarget); ?>">

    <label>Email:</label><br>
    <input type="email" name="email" required><br><br>

    <label>Password:</label><br>
    <input type="password" name="password" required><br><br>

    <button type="submit">Log In</button>
</form>

</body>
</html>

