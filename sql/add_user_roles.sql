-- EqualizeME — separate ordinary users from administrators
--
-- The glossary in the paper defines an Administrator as "the authorized
-- user responsible for managing the EqualizeME system, including the
-- management of user accounts, system data, auditory assessment content,
-- and the IEM database". Until now there was no such thing in the schema:
-- every account was identical, and the only administration happened in
-- phpMyAdmin.
--
-- WHY A COLUMN ON users AND NOT A SEPARATE TABLE
-- An administrator is a person with an account, not a different kind of
-- entity. They log in the same way, have the same password rules, the same
-- two-factor options, and may well take the listening test themselves. A
-- separate admins table would duplicate all of that and introduce the
-- question of what happens when the same person exists in both.
--
-- WHY A ROLE AND NOT is_admin
-- A boolean answers one question and cannot answer another. If a third
-- level is ever wanted -- a reviewer who can edit the catalogue but not
-- touch accounts -- a role column takes a new value while a boolean takes
-- a second column and a rule about how the two combine.
--
-- Run in phpMyAdmin: select the database, SQL tab, paste, Go.


-- ── 1. the column ────────────────────────────────────────────────────────
--
-- Wrapped so re-running is harmless. MySQL has no ADD COLUMN IF NOT EXISTS
-- before 8.0.29 and MariaDB's spelling differs, so this checks the
-- information schema and builds the statement only when it is needed --
-- the same pattern as sql/add_two_factor.sql.
--
-- DEFAULT 'user' is the important part. Every existing account, and every
-- account created from now on, is an ordinary user unless someone
-- deliberately says otherwise. A default of 'admin' -- or a nullable
-- column treated as admin when empty -- would mean a forgotten row grants
-- privilege, which is the wrong direction for a mistake to fall.

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role'
);

SET @sql := IF(@exists = 0,
    "ALTER TABLE users
        ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'user' AFTER email",
    'SELECT ''users.role already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ── 2. an index, because every page load reads it ────────────────────────
--
-- Not for selectivity -- there will be one or two admins among all the
-- accounts -- but because "list the administrators" should not scan the
-- users table once the account list grows.

SET @idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'idx_users_role'
);

SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_users_role ON users (role)',
    'SELECT ''idx_users_role already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ── 3. make yourself an administrator ────────────────────────────────────
--
-- DELIBERATELY NOT AUTOMATIC. A migration that promotes "the first
-- account" or "every account created before today" would grant privilege
-- to whoever happens to match, which on a shared test database is not
-- necessarily you.
--
-- Edit the address below to your own, then run it. Nothing else in this
-- file grants anyone anything.

-- UPDATE users SET role = 'admin' WHERE email = 'you@example.com';


-- ── 4. check ─────────────────────────────────────────────────────────────
--
-- Expect every account to read 'user' until you run the line above. If any
-- account says 'admin' that you did not promote yourself, find out why
-- before going further.

SELECT id, name, email, role FROM users ORDER BY id;


-- ── to demote someone ────────────────────────────────────────────────────
--
--   UPDATE users SET role = 'user' WHERE email = 'them@example.com';
--
-- Check you are not removing the last administrator first:
--
--   SELECT COUNT(*) FROM users WHERE role = 'admin';
