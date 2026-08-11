<?php
// NOTE: this page is currently unlinked from the nav — login.php now hosts
// a combined Log In / Sign Up UI at login.php?tab=register instead. Left
// here (and kept working) in case anything still links to it directly.
require __DIR__ . "/api/session.php";
require __DIR__ . "/api/db.php";
start_secure_session();

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($name === "" || $email === "" || $password === "") {
        $error = "Please fill in all fields.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } else {
        try {
            $pdo = get_pdo();

            // Check if email already exists
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = "An account with that email already exists.";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, NOW())"
                );
                $stmt->execute([$name, $email, $hashedPassword]);

                $success = "Account created! You can now log in.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>EqualizeME — Register</title>
</head>
<body>

<h1>Create Account</h1>

<?php if ($error): ?>
    <p style="color:red;"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p style="color:green;"><?php echo htmlspecialchars($success); ?></p>
    <p><a href="login.php">Go to Login</a></p>
<?php else: ?>
<form method="POST" action="register.php">
    <label>Name:</label><br>
    <input type="text" name="name" required><br><br>

    <label>Email:</label><br>
    <input type="email" name="email" required><br><br>

    <label>Password (min 8 chars):</label><br>
    <input type="password" name="password" required><br><br>

    <button type="submit">Register</button>
</form>
<?php endif; ?>

</body>
</html>