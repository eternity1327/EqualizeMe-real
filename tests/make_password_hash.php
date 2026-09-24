<?php

/**
 * Generate a bcrypt hash and the SQL statement that installs it.
 *
 *   C:\xampp\php\php.exe tests\make_password_hash.php you@example.com "the password"
 *
 * WHY A FILE AND NOT php -r
 * PowerShell expands $variables inside double-quoted arguments, and a
 * bcrypt hash is made almost entirely of dollar signs. A one-liner that
 * looks right in the terminal can produce a hash of something other than
 * the password you typed, or lose characters on the way to the clipboard —
 * which is exactly the failure this script exists to end.
 *
 * It verifies its own output before printing, so a hash that comes out of
 * here is guaranteed to match the password that went in.
 *
 * CLI only, denied by .htaccess, and never uploaded.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("CLI only.\n");
}

$email = $argv[1] ?? "";
$password = $argv[2] ?? "";

if ($email === "" || $password === "") {
    fwrite(STDERR,
        "Usage: php tests\\make_password_hash.php EMAIL PASSWORD\n\n"
        . "Wrap the password in double quotes if it contains spaces.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

// Proving it rather than trusting it. If this ever fails the PHP build is
// broken, and finding that out here beats finding it out at a login screen.
if (!password_verify($password, $hash)) {
    fwrite(STDERR, "password_verify() rejected the hash it just made. Stop.\n");
    exit(1);
}

echo "\n";
echo "password length : " . strlen($password) . " characters\n";
echo "hash length     : " . strlen($hash) . " characters (must be 60)\n";
echo "self-check      : OK — this hash matches that password\n";
echo "\n";
echo "Copy the whole line below, including the semicolon:\n\n";

// Single-quoted in SQL, and the email is escaped for the quote character
// because an address technically may contain one.
printf(
    "UPDATE users SET password_hash = '%s' WHERE email = '%s';\n\n",
    $hash,
    str_replace("'", "''", $email)
);

echo "Then check it landed intact:\n\n";
printf(
    "SELECT LEFT(password_hash,7) AS prefix, CHAR_LENGTH(password_hash) AS len "
    . "FROM users WHERE email = '%s';\n\n",
    str_replace("'", "''", $email)
);
echo "Expect: \$2y\$10\$  and  60\n\n";
