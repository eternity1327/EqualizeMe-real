-- Password reset tokens.
--
-- Run this once in phpMyAdmin (select the `equalizeme` database first,
-- then the SQL tab, paste, Go).
--
-- Only a HASH of each token is stored, never the token itself. If someone
-- ever gets a copy of this table they still can't reset anyone's password,
-- exactly like the users table storing password hashes rather than
-- passwords.

CREATE TABLE IF NOT EXISTS password_resets (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    token_hash      CHAR(64)     NOT NULL,
    expires_at      DATETIME     NOT NULL,
    used_at         DATETIME     NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Looking a token up by its hash is the hot path on every reset click.
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_id (user_id),

    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
