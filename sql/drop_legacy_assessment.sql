-- EqualizeME — remove the superseded branching-questionnaire tables
--
-- Run in phpMyAdmin: select the `equalizeme` database, open the SQL tab,
-- paste the whole file, Go.
--
--
-- WHAT THIS REMOVES AND WHY
--
-- These five tables belonged to the original rule-based questionnaire:
-- questions held the items, question_rules the path between them,
-- question_score_impact each answer's effect on the three bands, responses
-- the answers given, and assessments the sitting itself.
--
-- That design was replaced by the adaptive binary search in
-- backend/adaptive_test.py. The reasoning is recorded in SRS.md Appendix A,
-- which is why the tables no longer need to exist as documentation.
--
-- Removed alongside this migration:
--   assessment.php
--   recommendations.php
--   /start-assessment and /next-question routes in backend/ai_service.py
--   the nine helper functions those routes called
--
-- test_history is dropped too. It is referenced by no PHP, Python or SQL
-- anywhere in the project — left over from an earlier design.
--
-- Order matters: the foreign keys must go before the tables they point at.


-- ---------------------------------------------------------------------
-- 1. Check before you commit
-- ---------------------------------------------------------------------
-- Nothing below is reversible without a backup, so look first.
--
--     SELECT COUNT(*) FROM auditory_profiles WHERE assessment_id IS NOT NULL;
--     SELECT COUNT(*) FROM responses;
--     SELECT COUNT(*) FROM test_history;
--
-- Zero in all three means nothing of value is being discarded. If the first
-- returns rows, those profiles were produced by the old flow — the gain
-- values are already stored on the row itself, so only the link to the
-- sitting is lost.


-- ---------------------------------------------------------------------
-- 2. Detach auditory_profiles from assessments
-- ---------------------------------------------------------------------
-- auditory_profiles.assessment_id was only ever populated by the old flow.
-- The foreign key has to be dropped before the parent table can be.
-- Looked up by shape rather than by name, because the constraint name
-- varies depending on how the table was created.

SET @fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'auditory_profiles'
      AND COLUMN_NAME = 'assessment_id'
      AND REFERENCED_TABLE_NAME = 'assessments'
    LIMIT 1
);

SET @sql := IF(
    @fk IS NULL,
    'SELECT ''No foreign key on assessment_id'' AS result',
    CONCAT('ALTER TABLE auditory_profiles DROP FOREIGN KEY `', @fk, '`')
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET @col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'auditory_profiles'
      AND COLUMN_NAME = 'assessment_id'
);

SET @sql := IF(
    @col = 0,
    'SELECT ''assessment_id already gone'' AS result',
    'ALTER TABLE auditory_profiles DROP COLUMN assessment_id'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------
-- 3. Drop the tables, children before parents
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS responses;
DROP TABLE IF EXISTS question_score_impact;
DROP TABLE IF EXISTS question_rules;
DROP TABLE IF EXISTS questions;
DROP TABLE IF EXISTS assessments;


-- ---------------------------------------------------------------------
-- 4. Drop the unreferenced leftover table
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS test_history;


-- ---------------------------------------------------------------------
-- 5. Verify
-- ---------------------------------------------------------------------
-- Expected remaining tables:
--   auditory_profiles, iems, password_resets, retailers, settings, users

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_NAME;
