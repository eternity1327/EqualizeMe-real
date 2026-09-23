-- EqualizeME — move the listening-test audio catalogue into the database
--
-- WHAT CHANGES
-- The ten clips used by the listening test were named by a formula in PHP
-- ("sample" . N . ".wav") and titled by a hardcoded array. Swapping a track
-- meant editing api/adaptive_test.php and uploading it. This table holds the
-- path and the title instead, so changing the music is a row edit.
--
-- WHAT DOES NOT CHANGE
-- The audio itself stays on disk. Only the path is stored here. Putting
-- multi-megabyte MP3s in MySQL would make every query that touches this
-- table slow, make backups enormous, and gain nothing — the web server
-- serves a file from disk far faster than PHP can stream a BLOB.
--
-- sample_key is still "sampleN.wav" and is still what at_sample_for_question()
-- produces. That string is written into each test's history and is compared
-- against the original Python implementation by tests/compare_with_python.php,
-- so it is an identifier rather than a filename now. The real filename lives
-- in file_path and may be anything.
--
-- Run in phpMyAdmin: select the database, SQL tab, paste, Go.


CREATE TABLE IF NOT EXISTS audio_samples (
    id              INT AUTO_INCREMENT PRIMARY KEY,

    -- The stable identifier the algorithm and the stored history use.
    -- Never renamed, even when the track behind it is replaced.
    sample_key      VARCHAR(64)  NOT NULL,

    -- Web path, relative to the site root. Read by the browser, so it is
    -- validated in PHP before it is sent -- see api/audio_samples.php.
    file_path       VARCHAR(255) NOT NULL,

    title           VARCHAR(120) NOT NULL,

    -- Which part of the track the listener is being pointed at, or NULL
    -- when the whole thing is the point. Shown as a pill beside the title.
    section         VARCHAR(60)  DEFAULT NULL,

    -- A track can be retired without deleting the history that references
    -- it. Inactive rows fall back to the built-in path.
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,

    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- One row per identifier. Without this a duplicate would make which
    -- track plays depend on row order.
    CONSTRAINT uq_audio_samples_key UNIQUE (sample_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── seed the ten the test uses ───────────────────────────────────────────
--
-- These reproduce exactly what was hardcoded before, so running this
-- migration alone changes nothing that a listener would notice. Edit the
-- rows afterwards to point at the full tracks.
--
-- INSERT IGNORE so re-running is harmless.

INSERT IGNORE INTO audio_samples (sample_key, file_path, title, section) VALUES
('sample1.wav',  'data/audio/samples/sample1.mp3',  'Show', 'Chorus'),
('sample2.wav',  'data/audio/samples/sample2.mp3',  'Show', 'Intro'),
('sample3.wav',  'data/audio/samples/sample3.mp3',  'Everlasting Summer', 'Ref'),
('sample4.wav',  'data/audio/samples/sample4.mp3',  'Everlasting Summer', 'Chorus'),
('sample5.wav',  'data/audio/samples/sample5.mp3',  'Summertime Lime', 'Ambient'),
('sample6.wav',  'data/audio/samples/sample6.mp3',  'Summertime Lime', 'Intro'),
('sample7.wav',  'data/audio/samples/sample7.mp3',  'I Think They Call This Love', 'Chorus'),
('sample8.wav',  'data/audio/samples/sample8.mp3',  'I Think They Call This Love', 'Bridge'),
('sample9.wav',  'data/audio/samples/sample9.mp3',  'Original Me', 'Chorus'),
('sample10.wav', 'data/audio/samples/sample10.mp3', 'Original Me', 'Solo');


-- ── check ────────────────────────────────────────────────────────────────

SELECT sample_key, file_path, title, section, is_active
FROM audio_samples
ORDER BY id;


-- ── how to swap a track ──────────────────────────────────────────────────
--
-- Upload the new file, then point the row at it. The identifier stays the
-- same, so existing history keeps meaning what it meant.
--
--   UPDATE audio_samples
--      SET file_path = 'data/audio/samples/full-track-1.mp3',
--          title     = 'Song Title',
--          section   = NULL
--    WHERE sample_key = 'sample1.wav';
--
-- section = NULL hides the pill, which is what you want once the file is a
-- whole song rather than one part of one.
