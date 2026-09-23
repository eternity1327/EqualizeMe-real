// Pulls the pure functions out of js/adaptiveTest.js and exercises them.
// Anything touching Web Audio is left alone; this is the arithmetic only.
const fs = require('fs');
const src = fs.readFileSync('js/adaptiveTest.js', 'utf8');

function grab(re, label) {
  const m = src.match(re);
  if (!m) throw new Error('could not find ' + label);
  return m[0];
}

const pieces = [
  grab(/const TARGET_RMS = [^\n]+/, 'TARGET_RMS'),
  grab(/const MIN_MAKEUP_GAIN = [^\n]+/, 'MIN'),
  grab(/const MAX_MAKEUP_GAIN = [^\n]+/, 'MAX'),
  grab(/const PEAK_CEILING = [^\n]+/, 'PEAK_CEILING'),
  grab(/function makeupGainFrom\(measurement\) \{[\s\S]*?\n\}/, 'makeupGainFrom'),
].join('\n');

const ctx = {};
new Function('exports', pieces + '\nexports.makeupGainFrom = makeupGainFrom;'
  + '\nexports.TARGET_RMS = TARGET_RMS;'
  + '\nexports.MIN = MIN_MAKEUP_GAIN;'
  + '\nexports.MAX = MAX_MAKEUP_GAIN;'
  + '\nexports.CEIL = PEAK_CEILING;')(ctx);

const { makeupGainFrom, TARGET_RMS, MIN, MAX, CEIL } = ctx;

let checks = 0, failures = 0;
function near(label, actual, expected, tol = 1e-9) {
  checks++;
  if (Math.abs(actual - expected) <= tol) return;
  failures++;
  console.log(`FAIL  ${label}\n      expected ${expected}\n      actual   ${actual}`);
}
function ok(label, cond) {
  checks++;
  if (cond) return;
  failures++;
  console.log(`FAIL  ${label}`);
}

// A signal already at target is left alone.
near('at target -> gain 1', makeupGainFrom({rms: TARGET_RMS, peak: 0.5}), 1);

// Quiet signal is brought up.
near('half target -> gain 2', makeupGainFrom({rms: TARGET_RMS/2, peak: 0.2}), 2);

// Loud signal is brought down.
near('double target -> gain 0.5', makeupGainFrom({rms: TARGET_RMS*2, peak: 0.5}), 0.5);

// The peak ceiling wins when raising would clip.
// rms 0.01 wants gain 10, but is clamped to MAX 4, then peak 0.5 caps at 0.99/0.5=1.98.
near('peak ceiling caps the boost', makeupGainFrom({rms: 0.01, peak: 0.5}), CEIL/0.5);

// A near-silent window cannot ask for an enormous gain.
ok('silence-ish is clamped', makeupGainFrom({rms: 1e-9, peak: 1e-9}) <= MAX);

// Digital silence is not divided by.
near('zero rms -> gain 1', makeupGainFrom({rms: 0, peak: 0}), 1);
near('missing measurement -> gain 1', makeupGainFrom(null), 1);

// A signal already peaking at full scale is never boosted past the ceiling.
ok('full-scale peak is not boosted', makeupGainFrom({rms: 0.02, peak: 1.0}) <= CEIL);

// THE POINT OF THE WHOLE EXERCISE:
// two versions of the same music at different measured loudness must come
// out at the same level after correction.
const flat    = {rms: 0.080, peak: 0.60};
const bassy   = {rms: 0.115, peak: 0.85};   // +6 dB shelf adds energy
const correctedFlat  = flat.rms  * makeupGainFrom(flat);
const correctedBassy = bassy.rms * makeupGainFrom(bassy);
near('A and B end at the same loudness', correctedFlat, correctedBassy, 1e-6);
ok('and that loudness is the target', Math.abs(correctedFlat - TARGET_RMS) < 1e-6);

console.log(`\n${checks} checks, ${failures} failures.`);
process.exit(failures ? 1 : 0);
