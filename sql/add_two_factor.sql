-- Two-factor authentication (TOTP).
--
-- Run this once in phpMyAdmin: select the `equalizeme` database, open the
-- SQL tab, paste the whole file, Go. It is safe to run more than once —
-- every step checks the current state first and skips what already exists.
--
-- What gets added:
--
--   users.totp_secret         the shared secret, base32
--   users.totp_confirmed_at   NULL until the first correct code proves the
--                             phone and the server agree
--   users.totp_last_step      replay guard, see below
--   recovery_codes            hashed, single-use, one row per code
--
-- The secret is stored in the clear because it has to be: the server needs
-- it to compute the expected code, so there is no one-way form that would
-- still work. That is inherent to TOTP, not a shortcut. It is the reason
-- the app database account has no rights beyond SELECT/INSERT/UPDATE/DELETE
-- and why this table is worth protecting like the password column.


-- ─────────────────────────────────────────── 1. columns on users

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER password_hash',
        'SELECT ''users.totp_secret already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'totp_secret'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Enrolment is only complete once a code has actually verified. A secret
-- with no confirmation is someone who opened the setup page and wandered
-- off, and must not count as protected.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN totp_confirmed_at DATETIME NULL AFTER totp_secret',
        'SELECT ''users.totp_confirmed_at already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'totp_confirmed_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- The replay guard. A code stays valid for its whole 30-second step, so
-- without this a code read over someone's shoulder — or captured from a
-- phishing page — could be used again while it is still in date. Storing
-- the last step that succeeded lets the server refuse anything at or below
-- it. BIGINT because the step counter keeps climbing forever.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN totp_last_step BIGINT NULL AFTER totp_confirmed_at',
        'SELECT ''users.totp_last_step already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'totp_last_step'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ────────────────────────────────────── 2. recovery codes

-- Hashed like passwords, for the same reason: a recovery code logs someone
-- in without the phone, so it is a credential. One row per code rather than
-- a JSON blob, so marking a single code used is an ordinary UPDATE and two
-- codes cannot be spent at once by a lost race.
CREATE TABLE IF NOT EXISTS recovery_codes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT       NOT NULL,
    code_hash   VARCHAR(255) NOT NULL,
    used_at     DATETIME  NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Every lookup is "the unused codes for this user".
    INDEX idx_recovery_user_unused (user_id, used_at),

    CONSTRAINT fk_recovery_codes_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ────────────────────────────────────── 3. grants reminder

-- The app account already holds SELECT/INSERT/UPDATE/DELETE on the whole
-- schema, so it picks up the new table automatically. Confirm with:
--
--     SHOW GRANTS FOR 'equalizeme_app'@'localhost';
--
-- If the grants were ever narrowed to a per-table list, recovery_codes has
-- to be added to it.


-- ────────────────────────────────────── 4. what this looks like afterwards

-- Nobody is enrolled yet, which is correct: with two-factor required, every
-- existing account is sent through enrolment the next time it logs in.
--
--     SELECT id, name, email,
--            totp_secret IS NOT NULL      AS has_secret,
--            totp_confirmed_at IS NOT NULL AS enrolled
--     FROM users;
--
-- And to un-enrol an account that has lost both its phone and its recovery
-- codes — the manual unlock, run by whoever administers the database:
--
--     UPDATE users
--     SET totp_secret = NULL, totp_confirmed_at = NULL, totp_last_step = NULL
--     WHERE email = 'someone@example.com';
--     DELETE FROM recovery_codes WHERE user_id = (
--         SELECT id FROM users WHERE email = 'someone@example.com'
--     );
--
-- They will be walked through enrolment again on their next login.
