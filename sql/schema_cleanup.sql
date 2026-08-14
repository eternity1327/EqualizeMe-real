-- EqualizeME — schema cleanup
--
-- Run in phpMyAdmin: select the `equalizeme` database, open the SQL tab,
-- paste, Go. Read the notes; a couple of steps are worth checking before
-- running rather than pasting blind.
--
-- These add constraints the CODE ALREADY ASSUMES. Where a constraint is
-- missing, the code doesn't error — it silently does the wrong thing, which
-- is how the duplicate IEMs happened.


-- ---------------------------------------------------------------------
-- 1. Stop duplicate IEMs  (REQUIRED for the importer to work correctly)
-- ---------------------------------------------------------------------
-- import_to_db.py now uses ON DUPLICATE KEY UPDATE so re-running it
-- refreshes rows instead of duplicating them. That clause only works if
-- MySQL can tell which rows are "the same" — which needs this unique key.
-- Without it the clause never fires and every import doubles the table.
--
-- Check first, since the constraint fails to apply if duplicates exist:
--
--     SELECT brand, name, COUNT(*) c FROM iems
--     GROUP BY brand, name HAVING c > 1;
--
-- If that returns rows, delete the extras before running this.

ALTER TABLE iems
    ADD CONSTRAINT uq_iems_brand_name UNIQUE (brand, name);


-- ---------------------------------------------------------------------
-- 2. One auditory profile per user  (REQUIRED — silent data bug if absent)
-- ---------------------------------------------------------------------
-- ai_service.py's _save_dsp_profile() uses ON DUPLICATE KEY UPDATE to
-- replace a user's profile when they retake the test. If user_id isn't
-- unique that clause never fires, so every retake appends another row.
--
-- The recommendations query papers over it with ORDER BY updated_at DESC
-- LIMIT 1, so nothing looks broken — the table just grows a duplicate per
-- retake, and any query that forgets that ORDER BY silently reads a stale
-- profile.
--
-- Check for existing duplicates first:
--
--     SELECT user_id, COUNT(*) c FROM auditory_profiles
--     GROUP BY user_id HAVING c > 1;
--
-- To keep only the newest row per user before applying the constraint:
--
--     DELETE ap FROM auditory_profiles ap
--     JOIN auditory_profiles newer
--       ON newer.user_id = ap.user_id
--      AND newer.updated_at > ap.updated_at
--     WHERE ap.user_id IS NOT NULL;

ALTER TABLE auditory_profiles
    ADD CONSTRAINT uq_auditory_profiles_user UNIQUE (user_id);


-- ---------------------------------------------------------------------
-- 3. Index the lookups that run on every page
-- ---------------------------------------------------------------------
-- users.email is checked on every login, registration and password reset,
-- and is a natural unique key anyway — registration already treats it as
-- one in application code, so the database should enforce it. Without it,
-- two simultaneous registrations with the same address can both succeed.
--
-- Check for existing duplicate emails first:
--     SELECT email, COUNT(*) c FROM users GROUP BY email HAVING c > 1;

ALTER TABLE users
    ADD CONSTRAINT uq_users_email UNIQUE (email);

-- settings and auditory_profiles are always looked up by user_id.
CREATE INDEX idx_settings_user ON settings (user_id);

-- Recommendations reads every IEM's gains on each request. This index lets
-- the "does it have usable gains" filter run without a full table scan
-- once the catalogue grows past a few hundred rows.
CREATE INDEX idx_iems_gains ON iems (bass_gain, treble_gain, presence_gain);


-- ---------------------------------------------------------------------
-- 4. Drop the dead table
-- ---------------------------------------------------------------------
-- `test_history` is referenced by NOTHING — no PHP, no Python, no SQL in
-- this project. It's left over from an earlier design. Confirm it's empty
-- (or that you don't want its contents) before dropping:
--
--     SELECT COUNT(*) FROM test_history;

DROP TABLE IF EXISTS test_history;


-- ---------------------------------------------------------------------
-- 5. NOT dropped, deliberately: the legacy assessment tables
-- ---------------------------------------------------------------------
-- assessments, questions, question_rules, question_score_impact and
-- responses belong to the older branching-quiz flow. It's superseded by the
-- adaptive test but still functional, still reachable at assessment.php,
-- and its Flask routes still exist.
--
-- They're left in place because removing them would break that page, and
-- because a working older implementation is worth being able to point at
-- when explaining why the current design was chosen. Drop them only if you
-- also remove assessment.php and the /start-assessment and /next-question
-- routes from ai_service.py.
