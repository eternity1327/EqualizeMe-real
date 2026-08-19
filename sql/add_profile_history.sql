-- EqualizeME — enable profile history tracking
--
-- Run this AFTER schema_cleanup.sql (or instead of the auditory_profiles
-- UNIQUE constraint if you haven't run cleanup yet).
--
-- This removes the one-profile-per-user constraint and adds timestamps,
-- allowing the system to track how preferences evolve over time.

-- If the UNIQUE constraint exists, drop it
ALTER TABLE auditory_profiles
    DROP CONSTRAINT IF EXISTS uq_auditory_profiles_user;

-- Add created_at if it doesn't exist (tracks when each profile was created)
ALTER TABLE auditory_profiles
    ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Update existing rows to have a created_at based on updated_at
UPDATE auditory_profiles
SET created_at = updated_at
WHERE created_at IS NULL;

-- Create an index for fast history lookups by user_id
CREATE INDEX IF NOT EXISTS idx_auditory_profiles_user_created
    ON auditory_profiles (user_id, created_at DESC);

-- Verify: see all profiles for a user with most recent first
-- SELECT user_id, bass_gain, presence_gain, treble_gain, confidence_score, created_at
-- FROM auditory_profiles
-- ORDER BY user_id, created_at DESC;
