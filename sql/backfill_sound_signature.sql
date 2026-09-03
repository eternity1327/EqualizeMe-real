-- Fill in iems.sound_signature for rows that were imported without it.
--
-- WHY THIS EXISTS
-- The card in the results grid renders sound_signature above the description
-- sentence. import_to_db.py did not write that column, so every imported row
-- had it NULL and the card showed an empty line where the label belongs.
--
-- The importer sets it now (backend/interpreter.py signature_label). This
-- script is for rows already in a database, and for production in particular:
-- the live MySQL cannot be reached from outside the host, so re-running the
-- Python importer against it is not possible. The label has to be derivable
-- in SQL as well, from the three numbers already stored.
--
-- THE CUTOFFS ARE NOT ARBITRARY
-- They are the same ones in backend/interpreter.py, which came out of
-- calibrate_interpreter.py running over the measurement set. Both must move
-- together: if the thresholds are ever retuned, change them there and re-run
-- this, or a card will contradict its own description -- "V-Shaped" sitting
-- over a sentence reading "bass-light".
--
--   bass      boosted >= 5.57      reduced < 3.3
--   presence  forward >= 5.22      recessed < 3.5
--   treble    bright  >= 0.68      smooth   < -1.5
--
-- Only the outer tiers count. The middle of each band is neither, which is
-- why a row can come out "Balanced" with none of its three numbers at zero.
--
-- ORDER MATTERS. "V-Shaped" means both ends lifted, so it is tested before
-- either end alone. CASE stops at its first match, which gives that for free.
--
-- SAFE TO RE-RUN. It rewrites every row from the numbers, so running it twice
-- changes nothing the second time. One statement, so it survives shared hosts
-- that drop long batches.
--
-- Run with:  mysql -u USER -p DBNAME < sql/backfill_sound_signature.sql
-- or paste into phpMyAdmin's SQL tab.

UPDATE iems
SET sound_signature = CASE
        WHEN bass_gain IS NULL
          OR presence_gain IS NULL
          OR treble_gain IS NULL          THEN NULL

        WHEN bass_gain   >= 5.57
         AND treble_gain >= 0.68          THEN 'V-Shaped'
        WHEN bass_gain   >= 5.57
         AND treble_gain <  -1.5          THEN 'Dark'
        WHEN bass_gain   >= 5.57          THEN 'Warm'

        WHEN treble_gain >= 0.68          THEN 'Bright'
        WHEN treble_gain <  -1.5          THEN 'Smooth'

        WHEN presence_gain >= 5.22        THEN 'Mid-Forward'
        WHEN bass_gain     <  3.3
         AND presence_gain <  3.5         THEN 'Neutral'

        ELSE 'Balanced'
    END;

-- Check the spread afterwards. Every row should have a label, and no single
-- label should swallow the catalogue -- if one does, the thresholds are off
-- rather than the earphones being genuinely alike.
--
--   SELECT COALESCE(sound_signature, '(none)') AS signature,
--          COUNT(*) AS n
--   FROM iems
--   GROUP BY signature
--   ORDER BY n DESC;
