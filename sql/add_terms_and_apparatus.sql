-- EqualizeME — record consent, and what the listener was wearing
--
-- Two independent additions, kept in one file because they are part of the
-- same change to the pre-test flow.
--
--   users.terms_accepted_at        when this account accepted the terms
--   auditory_profiles.apparatus    what they listened through, per test
--
-- WHY terms_accepted_at IS A DATETIME AND NOT A FLAG
-- A boolean answers "did they agree", which is the less useful question.
-- The one that gets asked later is "agreed to what, and when" -- terms get
-- revised, and a timestamp lets an old acceptance be told apart from one
-- made after a revision. A flag would have to be reset for everybody and
-- the previous state would be gone.
--
-- WHY apparatus IS PER ASSESSMENT AND NOT PER USER
-- People change headphones. A column on users would record only the most
-- recent choice and silently relabel every earlier test with it. On the
-- assessment it stays attached to the sitting it describes, which is what
-- makes it usable later: the profile aggregate can then say whether the
-- results being combined were gathered through the same kind of device.
--
-- NOTHING READS apparatus YET. It is recorded now so the data exists when
-- per-device calibration is built. Collecting it from this point is free;
-- collecting it retrospectively is impossible.
--
-- Safe to run twice. Each ALTER is guarded by a check against
-- information_schema, so a second run does nothing rather than failing.
--
-- Run with:  mysql -u USER -p DBNAME < sql/add_terms_and_apparatus.sql
-- or paste into phpMyAdmin's SQL tab.


-- ── users.terms_accepted_at ──────────────────────────────────────────────

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users
            ADD COLUMN terms_accepted_at DATETIME DEFAULT NULL
            AFTER email_verified_at',
        'SELECT ''users.terms_accepted_at already exists'' AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'terms_accepted_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ── auditory_profiles.apparatus ──────────────────────────────────────────
--
-- Deliberately a short free-text column rather than an ENUM. The list of
-- device kinds will grow -- open-back, speakers, bone conduction -- and
-- extending an ENUM means an ALTER on a table that will by then hold every
-- assessment ever taken. The values written are fixed by the application,
-- which is where that rule belongs.

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE auditory_profiles
            ADD COLUMN apparatus VARCHAR(32) DEFAULT NULL
            AFTER confidence_score',
        'SELECT ''auditory_profiles.apparatus already exists'' AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'auditory_profiles'
      AND COLUMN_NAME = 'apparatus'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ── check ────────────────────────────────────────────────────────────────
--
-- Existing accounts are left with terms_accepted_at NULL on purpose. They
-- have not seen the terms, so they will be asked once, before their next
-- test. Backfilling would record a consent that never happened.

SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'terms_accepted_at')          AS users_column_present,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditory_profiles'
        AND COLUMN_NAME = 'apparatus')                  AS profiles_column_present,
    (SELECT COUNT(*) FROM users WHERE terms_accepted_at IS NULL)
                                                        AS accounts_yet_to_accept;
