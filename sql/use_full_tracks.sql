-- EqualizeME — switch the listening test from clips to full tracks
--
-- Run sql/add_audio_samples.sql first; this edits the rows it created.
--
-- FIVE FILES, TEN SLOTS
-- The test asks ten questions and there are five songs, so each song is
-- used twice. That was already true — sample1 and sample2 were both taken
-- from "Show" — the difference is that the two slots now point at the same
-- whole track rather than at two excerpts of it.
--
-- The section column is cleared for the same reason. "Chorus" described
-- which excerpt the file was; a file that is the entire song is not a
-- chorus, and leaving the label would put a pill on screen that lies about
-- what is playing.
--
-- The sample_key values are untouched. They identify the slot, they are
-- written into each test's stored history, and tests/compare_with_python.php
-- checks them against the original implementation.

UPDATE audio_samples SET
    file_path = 'data/audio/samples/full-show.mp3',
    title     = 'Show',
    section   = NULL
WHERE sample_key IN ('sample1.wav', 'sample2.wav');

UPDATE audio_samples SET
    file_path = 'data/audio/samples/full-everlasting-summer.mp3',
    title     = 'Everlasting Summer',
    section   = NULL
WHERE sample_key IN ('sample3.wav', 'sample4.wav');

UPDATE audio_samples SET
    file_path = 'data/audio/samples/full-summertime-lime.mp3',
    title     = 'Summertime (Bossa Nova) — Lime & LilyPichu',
    section   = NULL
WHERE sample_key IN ('sample5.wav', 'sample6.wav');

UPDATE audio_samples SET
    file_path = 'data/audio/samples/full-i-think-they-call-this-love.mp3',
    title     = 'I Think They Call This Love',
    section   = NULL
WHERE sample_key IN ('sample7.wav', 'sample8.wav');

UPDATE audio_samples SET
    file_path = 'data/audio/samples/full-original-me.mp3',
    title     = 'Original Me',
    section   = NULL
WHERE sample_key IN ('sample9.wav', 'sample10.wav');


-- ── check ────────────────────────────────────────────────────────────────
--
-- Expect ten rows, five distinct paths, every section NULL.

SELECT sample_key, title, section, file_path
FROM audio_samples
ORDER BY CAST(REPLACE(REPLACE(sample_key, 'sample', ''), '.wav', '') AS UNSIGNED);


-- ── to go back to the clips ──────────────────────────────────────────────
--
-- The old files are still on disk unless they were deleted. Restoring is
-- the reverse edit:
--
--   UPDATE audio_samples
--      SET file_path = CONCAT('data/audio/samples/',
--                             REPLACE(sample_key, '.wav', '.mp3'))
--    WHERE sample_key LIKE 'sample%';
--
-- and then setting the section labels back by hand.
