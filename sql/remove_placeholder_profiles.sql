-- EqualizeME — delete the placeholder rows in auditory_profiles
--
-- WHAT THEY ARE
-- Registration used to write one row per new account: zeros across all
-- three bands, no confidence score. It dated from when auditory_profiles
-- held exactly one row per person and the application assumed a row
-- existed. The table later became an append-only history of completed
-- listening tests, and the placeholder stopped meaning anything.
--
-- WHY THEY HAVE TO GO
-- pp_fetch_assessments() selects every row belonging to a user, and
-- pp_assessment_weight() treats a NULL confidence_score as full
-- confidence -- weight 1.0. So a row standing for no test at all was
-- folded into the aggregate at roughly the weight of a real result,
-- pulling every listener's target toward zero. On an account with eight
-- genuine tests it was about a ninth of the total weight.
--
-- It also made the "No profile yet — take the sound test" message
-- unreachable, because there was always at least one row to find.
--
-- api/auth/register.php no longer creates them. This clears the ones
-- already written.
--
-- HOW A PLACEHOLDER IS IDENTIFIED
-- All three gains exactly zero AND no confidence score. Both conditions
-- are required, and the second is what makes it safe: every row written
-- by a completed test carries a confidence score. A real test landing on
-- exactly 0.0 in all three bands is possible but would still have one,
-- so it is not matched here.
--
-- Run with:  mysql -u USER -p DBNAME < sql/remove_placeholder_profiles.sql
-- or paste into phpMyAdmin's SQL tab.


-- ── 1. look before deleting ──────────────────────────────────────────────
--
-- Read this first. It should list one row per account created before this
-- change, and nothing else. If a row here has a confidence score, stop --
-- the WHERE clause below is wrong for your data and I should look at it.

SELECT id, user_id, bass_gain, treble_gain, presence_gain,
       confidence_score, created_at
FROM auditory_profiles
WHERE bass_gain = 0
  AND treble_gain = 0
  AND presence_gain = 0
  AND confidence_score IS NULL
ORDER BY user_id;


-- ── 2. keep a copy ───────────────────────────────────────────────────────
--
-- Deleted rows are copied here first. Nothing is lost until you drop this
-- table yourself.

DROP TABLE IF EXISTS auditory_profiles_placeholder_backup;

CREATE TABLE auditory_profiles_placeholder_backup AS
SELECT * FROM auditory_profiles
WHERE bass_gain = 0
  AND treble_gain = 0
  AND presence_gain = 0
  AND confidence_score IS NULL;


-- ── 3. delete ────────────────────────────────────────────────────────────

DELETE FROM auditory_profiles
WHERE bass_gain = 0
  AND treble_gain = 0
  AND presence_gain = 0
  AND confidence_score IS NULL;


-- ── 4. check ─────────────────────────────────────────────────────────────
--
-- accounts_with_no_test is expected to be greater than zero: an account
-- that has registered but never finished a listening test now genuinely
-- has no rows, which is the correct state and what the "take the sound
-- test" message is for.

SELECT
    (SELECT COUNT(*) FROM auditory_profiles_placeholder_backup)
        AS placeholders_removed,
    (SELECT COUNT(*) FROM auditory_profiles)
        AS real_assessments_remaining,
    (SELECT COUNT(*) FROM users u
      WHERE NOT EXISTS (SELECT 1 FROM auditory_profiles p
                         WHERE p.user_id = u.id))
        AS accounts_with_no_test;

-- To undo, before dropping the backup:
--   INSERT INTO auditory_profiles SELECT * FROM auditory_profiles_placeholder_backup;
--
-- Once you are satisfied:
--   DROP TABLE auditory_profiles_placeholder_backup;
