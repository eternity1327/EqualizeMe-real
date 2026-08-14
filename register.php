<?php
/**
 * Legacy registration page — now a redirect only.
 *
 * This page used to create accounts itself, with its own copy of the
 * signup logic. That made it a second, unprotected way into the system:
 * it had no CSRF token, no rate limiting, no password policy beyond a
 * length check, and it echoed raw PDO exception messages straight to the
 * browser, which leaks table and column names to anyone who can trigger
 * an error.
 *
 * Meanwhile api/auth/register.php — the path the real UI uses — had all
 * of those protections. A forgotten parallel route like this is exactly
 * what an attacker looks for, since hardening usually lands on the path
 * everyone remembers and not the one nobody links to.
 *
 * Rather than maintain the same logic twice, the page now forwards to the
 * combined login/signup UI. Any old bookmark or link still works, and
 * there is only one registration code path to keep secure.
 */
require_once __DIR__ . "/api/session.php";
start_secure_session();

header("Location: login.php?tab=register", true, 301);
exit;
