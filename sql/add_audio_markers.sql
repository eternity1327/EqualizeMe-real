-- EqualizeME — named points inside a track
--
-- Run sql/add_audio_samples.sql first.
--
-- WHY A TABLE AND NOT ANOTHER COLUMN
-- audio_samples.section already holds a label like "Chorus", and that was
-- enough while every file WAS a chorus -- a ten-second excerpt cut from
-- one. The files are whole songs now, so the label has to say where the
-- chorus is, not that the file is one. A song has several such points and
-- each needs its own time, which is a list, and a list does not fit in a
-- column without inventing a format to pack it into and a parser to get it
-- back out.
--
-- The listener sees these as flags along the waveform. Clicking one jumps
-- the playhead there, so "compare the chorus" is one click rather than
-- dragging around hunting for it.
--
-- section is left in place and still shown as the pill beside the title.
-- The two answer different questions: section describes the file, markers
-- describe positions within it.


CREATE TABLE IF NOT EXISTS audio_markers (
    id              INT AUTO_INCREMENT PRIMARY KEY,

    -- The slot this marker belongs to, not the song. Two questions can use
    -- the same track and point at different parts of it.
    audio_sample_id INT          NOT NULL,

    label           VARCHAR(40)  NOT NULL,

    -- Seconds from the start. DECIMAL rather than INT so a marker can land
    -- on a downbeat at 71.4s instead of being dragged to a whole second.
    start_seconds   DECIMAL(7,2) NOT NULL,

    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Every read is "the markers for this slot, in time order".
    INDEX idx_markers_sample (audio_sample_id, start_seconds),

    -- ON DELETE CASCADE because a marker into a track that no longer
    -- exists is not a record of anything.
    CONSTRAINT fk_audio_markers_sample
        FOREIGN KEY (audio_sample_id) REFERENCES audio_samples(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ── carry the existing labels across ─────────────────────────────────────
--
-- Every slot that already names a section gets that name as a marker at
-- 0:00. The time is a placeholder and is meant to be corrected from the
-- admin page -- but seeding it means the feature has something to show the
-- moment it goes live, rather than an empty waveform that looks broken.
--
-- Skips slots that already have markers, so this is safe to re-run.

INSERT INTO audio_markers (audio_sample_id, label, start_seconds)
SELECT s.id, s.section, 0
FROM audio_samples s
WHERE s.section IS NOT NULL
  AND s.section <> ''
  AND NOT EXISTS (
      SELECT 1 FROM audio_markers m WHERE m.audio_sample_id = s.id
  );


-- ── check ────────────────────────────────────────────────────────────────

SELECT s.sample_key, s.title, m.label, m.start_seconds
FROM audio_samples s
LEFT JOIN audio_markers m ON m.audio_sample_id = s.id
ORDER BY
    CAST(REPLACE(REPLACE(s.sample_key, 'sample', ''), '.wav', '') AS UNSIGNED),
    m.start_seconds;


-- ── adding markers by hand ───────────────────────────────────────────────
--
-- The admin page is the intended route, but this works too:
--
--   INSERT INTO audio_markers (audio_sample_id, label, start_seconds)
--   SELECT id, 'Chorus', 72.5 FROM audio_samples
--   WHERE sample_key = 'sample1.wav';
--
-- And to clear a slot's markers before re-entering them:
--
--   DELETE m FROM audio_markers m
--   JOIN audio_samples s ON s.id = m.audio_sample_id
--   WHERE s.sample_key = 'sample1.wav';
