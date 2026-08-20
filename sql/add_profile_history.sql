-- EqualizeME — enable profile history tracking
--
-- Run in phpMyAdmin: select the `equalizeme` database, open the SQL tab,
-- paste the whole file, Go.
--
-- Removes the one-profile-per-user restriction so that each completed test
-- appends a row, which is what api/profile/history.php and
-- api/profile/compare.php need in order to show change over time.
--
--
-- WHY THIS FILE WAS REWRITTEN
--
-- The first version ran `DROP CONSTRAINT IF EXISTS uq_auditory_profiles_user`.
-- No constraint of that name has ever existed on this database — the real one
-- is `unique_user_profile`, created by hand when the table was made. Because
-- the statement used IF EXISTS, it succeeded while doing nothing at all, and
-- the rest of the migration ran normally. The result looked like a clean
-- migration but left the restriction in place, so history never accumulated.
--
-- Step 1 below now looks the index up by what it *does* rather than by what
-- it is called, so it cannot silently miss again.


-- ---------------------------------------------------------------------
-- 1. Drop whichever UNIQUE index restricts a user to one profile
-- ---------------------------------------------------------------------
-- Finds a non-primary UNIQUE index on auditory_profiles whose only column
-- is user_id, whatever its name, and drops it. Reports plainly if there is
-- nothing to drop.

SET @idx := (
    SELECT s.INDEX_NAME
    FROM information_schema.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE()
      AND s.TABLE_NAME   = 'auditory_profiles'
      AND s.NON_UNIQUE   = 0
      AND s.INDEX_NAME  <> 'PRIMARY'
    GROUP BY s.INDEX_NAME
    HAVING COUNT(*) = 1
       AND MAX(s.COLUMN_NAME) = 'user_id'
    LIMIT 1
);

SET @sql := IF(
    @idx IS NULL,
    'SELECT ''Nothing to drop - no single-column UNIQUE index on user_id'' AS result',
    CONCAT('ALTER TABLE auditory_profiles DROP INDEX `', @idx, '`')
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------
-- 2. Add created_at, if it isn't already there
-- ---------------------------------------------------------------------
-- History has to sort by when each profile was produced. updated_at moves
-- whenever a row is touched, so it cannot be used for this.

SET @has_created := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'auditory_profiles'
      AND COLUMN_NAME  = 'created_at'
);

SET @sql := IF(
    @has_created > 0,
    'SELECT ''created_at already present'' AS result',
    'ALTER TABLE auditory_profiles
        ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------
-- 3. Backfill any rows that predate the column
-- ---------------------------------------------------------------------
-- Without this, older profiles sort unpredictably in the history view.
-- updated_at is the closest available approximation of creation time.

UPDATE auditory_profiles
SET created_at = updated_at
WHERE created_at IS NULL
   OR created_at = '0000-00-00 00:00:00';


-- ---------------------------------------------------------------------
-- 4. Index the history query
-- ---------------------------------------------------------------------
-- Matches `WHERE user_id = ? ORDER BY created_at DESC` in
-- api/profile/history.php exactly, so MySQL can satisfy both the filter and
-- the sort from the index without a filesort.

SET @has_idx := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'auditory_profiles'
      AND INDEX_NAME   = 'idx_auditory_profiles_user_created'
);

SET @sql := IF(
    @has_idx > 0,
    'SELECT ''History index already present'' AS result',
    'CREATE INDEX idx_auditory_profiles_user_created
        ON auditory_profiles (user_id, created_at DESC)'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------
-- 5. Verify
-- ---------------------------------------------------------------------
-- Expected after a successful run: no UNIQUE index on user_id remains,
-- leaving only PRIMARY on id.

SELECT TABLE_NAME,
       INDEX_NAME,
       IF(NON_UNIQUE = 0, 'UNIQUE', 'index') AS kind,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'auditory_profiles'
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
ORDER BY INDEX_NAME;


-- Once this has run, backend/ai_service.py's _save_dsp_profile() no longer
-- needs its ON DUPLICATE KEY UPDATE clause — there is nothing left for a
-- retake to collide with. The comment there explains the dependency.
