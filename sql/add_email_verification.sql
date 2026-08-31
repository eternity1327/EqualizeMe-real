-- Email verification for new accounts.
--
-- Run once in phpMyAdmin: select the `equalizeme` database, SQL tab, paste,
-- Go. Safe to run more than once.
--
-- Why this exists: registration accepts any address without checking that
-- the person signing up controls it. On a private install that is merely
-- untidy. Once anyone can register it means somebody can sign up as
-- someone@theirschool.edu, and this server will send that stranger mail
-- they never asked for.
--
-- Same shape as password_resets, and for the same reason: only a hash of
-- each token is stored, so a copy of this table cannot be used to verify
-- anybody's address.


-- ───────────────────────────────────── 1. the flag on users

-- NULL means unverified. A timestamp rather than a boolean because knowing
-- *when* is useful and costs nothing.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email',
        'SELECT ''users.email_verified_at already exists'''
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND COLUMN_NAME  = 'email_verified_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ───────────────────────────────────── 2. the tokens

CREATE TABLE IF NOT EXISTS email_verifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT       NOT NULL,
    token_hash  CHAR(64)  NOT NULL,
    expires_at  DATETIME  NOT NULL,
    used_at     DATETIME  NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Looking a token up by its hash is the hot path on every click.
    INDEX idx_verify_token_hash (token_hash),
    INDEX idx_verify_user (user_id, used_at),

    CONSTRAINT fk_email_verifications_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ───────────────────────────────────── 3. existing accounts

-- Everyone who already has an account predates this check, and locking
-- them out to prove an address they have been using for weeks would be
-- pointless. They are marked verified as of now.
--
-- Only relevant if you intend to turn on 'require_email_verification'.
-- Leaving it off makes this line harmless either way.
UPDATE users
SET email_verified_at = NOW()
WHERE email_verified_at IS NULL;


-- ───────────────────────────────────── 4. checking afterwards

--   SELECT id, email, email_verified_at FROM users;
--   SELECT COUNT(*) FROM email_verifications WHERE used_at IS NULL;
--
-- To verify an account by hand, when mail is broken and someone is stuck:
--
--   UPDATE users SET email_verified_at = NOW() WHERE email = 'someone@example.com';
