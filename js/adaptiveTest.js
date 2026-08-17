// DSP_SERVICE_URL comes from script.js, which test.html loads first.
// Redeclaring it here as `const` throws and silently aborts this whole
// file, taking the test with it.

let hasPlayedA = false;
let hasPlayedB = false;
let currentUserId = null;
let autoPlayEnabled = false;

// The fixed length of the trimmed clips. Keeps the "playing" indicator
// on for the real duration, and tells Auto Play when it can hand off
// from A to B without cutting the first clip short.
const SAMPLE_PLAYBACK_MS = 10000;

const paramLabels = {
  bassGain: 'Bass',
  trebleGain: 'Treble',
  presenceGain: 'Presence (3kHz)',
};

// The pair currently on screen. Kept because the A/B gain values arrive
// with the pair from the server, and browser playback needs them at the
// moment Play is pressed.
let currentPair = null;

// Browser audio.
//
// Asking the server to equalise meant CamillaDSP played through the sound
// card of the machine running ai_service.py — fine for one person sitting
// at it, silence for everyone else, while the test still recorded their
// answers about audio they never heard. Equalising here means each
// listener hears it on their own headphones, which is also what a
// listening-preference test should be measuring.
//
// The filters come from EQ_BANDS in script.js, so playback here, the
// preference curve on the recommendations page, and camilla_dsp.py can't
// drift apart into three slightly different equalisers.
const EQ_FILTERS = EQ_BANDS;

const SAMPLES_BASE_URL = 'data/audio/samples/';

let audioCtx = null;
let activeSource = null;
const bufferCache = new Map();   // filename -> decoded AudioBuffer

function browserAudioSupported() {
  return typeof (window.AudioContext || window.webkitAudioContext) !== 'undefined';
}

// Browsers refuse to start audio until the user has interacted with the
// page, so the context is created lazily from inside the Play handler and
// resumed if the browser suspended it.
async function getAudioContext() {
  if (!audioCtx) {
    const Ctor = window.AudioContext || window.webkitAudioContext;
    audioCtx = new Ctor();
  }
  if (audioCtx.state === 'suspended') {
    await audioCtx.resume();
  }
  return audioCtx;
}

// The server refers to clips by their .wav filename, but the browser is
// served the .mp3 alongside it.
//
// Two reasons. The source WAVs are 24-bit WAVE_FORMAT_EXTENSIBLE, which
// decodeAudioData refuses — browsers handle 16-bit and 32-bit float PCM,
// not 24-bit — so playing them here fails outright. And they're 2.8 MB
// each, 28 MB for the set, which every listener would download; the MP3s
// total 3.1 MB.
//
// The WAVs stay on disk untouched because CamillaDSP reads them for the
// server-side fallback, and it can't read MP3.
function browserSampleName(filename) {
  return filename.replace(/\.wav$/i, '.mp3');
}

// Clips are played at least twice (side A, then side B), so decoded
// buffers are cached. Without this the same file would be downloaded and
// decoded again for each side.
async function loadSample(filename) {
  const webName = browserSampleName(filename);

  if (bufferCache.has(webName)) {
    return bufferCache.get(webName);
  }

  const ctx = await getAudioContext();
  const res = await fetch(SAMPLES_BASE_URL + encodeURIComponent(webName));
  if (!res.ok) {
    throw new Error(`Could not fetch ${webName} (${res.status})`);
  }

  const encoded = await res.arrayBuffer();
  const buffer = await ctx.decodeAudioData(encoded);
  bufferCache.set(webName, buffer);
  return buffer;
}

function stopActiveSource() {
  if (activeSource) {
    try {
      activeSource.stop();
    } catch (err) {
      // Already finished — stopping twice is not an error worth surfacing.
    }
    activeSource = null;
  }
}

/**
 * Plays one clip through the three EQ filters and resolves when it ends.
 * Rejects if the browser can't do it, so the caller can fall back to the
 * server route.
 */
async function playThroughBrowser(filename, gains) {
  const ctx = await getAudioContext();
  const buffer = await loadSample(filename);

  // Only one clip should ever be audible; starting B while A still plays
  // would make the comparison meaningless.
  stopActiveSource();

  const source = ctx.createBufferSource();
  source.buffer = buffer;

  // Chain the filters in series, matching the server's pipeline order.
  let node = source;
  for (const spec of EQ_FILTERS) {
    const filter = ctx.createBiquadFilter();
    filter.type = spec.type;
    filter.frequency.value = spec.frequency;
    filter.Q.value = spec.Q;
    filter.gain.value = Number(gains?.[spec.gainKey] ?? 0);
    node.connect(filter);
    node = filter;
  }
  node.connect(ctx.destination);

  activeSource = source;

  return new Promise(resolve => {
    source.onended = () => {
      if (activeSource === source) activeSource = null;
      resolve();
    };
    source.start();
  });
}

async function getCurrentUserId() {
  const res = await fetch('api/auth/me.php');
  if (!res.ok) {
    document.getElementById('status').textContent = 'Please log in first.';
    return null;
  }
  const data = await res.json();
  return data.id;
}

async function getAutoPlaySetting() {
  try {
    const res = await fetch('api/settings.php');
    if (!res.ok) return false;
    const settings = await res.json();
    return !!settings.autoPlay;
  } catch (err) {
    return false;
  }
}

// Runs on page load: just checks the user is logged in. Nothing starts
// until they press "Get Started" (see beginQuiz()).
async function initPicker() {
  currentUserId = await getCurrentUserId();
  if (!currentUserId) return;

  const startBtn = document.getElementById('start-test-btn');
  if (startBtn) startBtn.disabled = false;
}

// ---------------------------------------------------------------------------
// Step 1: the written pre-quiz. Its answers become a starting estimate that
// narrows the listening test's search range (scored server-side in
// pre_quiz.py — the impact values are deliberately not sent to the browser,
// so nobody can reverse-engineer which answer "adds bass").
// ---------------------------------------------------------------------------

function buildQuizQuestion(question) {
  const options = question.options.map(option => `
            <label class="quiz-option">
              <input type="radio" name="q-${question.id}" value="${option.value}">
              <span>${option.label}</span>
            </label>
          `).join('');

  return `
      <div class="quiz-q" data-question-id="${question.id}">
        <div class="quiz-prompt">${question.question}</div>
        <div class="quiz-options">${options}</div>
      </div>
    `;
}

/**
 * Highlight the chosen option's whole row, not just the radio dot.
 */
function wireQuizHighlighting(container) {
  container.querySelectorAll('.quiz-q').forEach(group => {
    group.addEventListener('change', () => {
      group.querySelectorAll('.quiz-option').forEach(option => {
        option.classList.toggle('picked', option.querySelector('input').checked);
      });
    });
  });
}

async function beginQuiz() {
  document.getElementById('track-picker').style.display = 'none';
  document.getElementById('quiz-screen').style.display = 'block';

  const container = document.getElementById('quiz-questions');

  try {
    const res = await fetch(`${DSP_SERVICE_URL}/api/quiz/questions`);
    const data = await res.json();

    if (!res.ok || !data.questions || !data.questions.length) {
      throw new Error('no questions returned');
    }

    container.innerHTML = data.questions.map(buildQuizQuestion).join('');
    wireQuizHighlighting(container);
  } catch (err) {
    // The quiz is an enhancement, not a gate — if it can't load, let them
    // into the listening test rather than dead-ending here.
    container.innerHTML =
      '<p class="subtext">Could not load the questions — skipping ahead to the listening test.</p>';
    document.getElementById('quiz-submit-btn').textContent = 'Continue';
  }
}

// Reads the selected answers and hands them to the listening test.
async function submitQuiz() {
  const answers = {};
  let unanswered = 0;

  document.querySelectorAll('#quiz-questions .quiz-q').forEach(group => {
    const id = group.dataset.questionId;
    const picked = group.querySelector('input:checked');
    if (picked) {
      answers[id] = picked.value;
    } else {
      unanswered++;
    }
  });

  const errorEl = document.getElementById('quiz-error');
  if (unanswered > 0) {
    errorEl.textContent =
      `Please answer all questions — ${unanswered} still ${unanswered === 1 ? 'needs' : 'need'} an answer.`;
    return;
  }
  errorEl.textContent = '';

  document.getElementById('quiz-screen').style.display = 'none';
  await beginTest(answers);
}

// ---------------------------------------------------------------------------
// Step 2: the A/B listening test.
// ---------------------------------------------------------------------------

async function beginTest(quizAnswers) {
  document.getElementById('track-picker').style.display = 'none';
  document.getElementById('test-screen').style.display = 'block';
  document.getElementById('loadingOverlay').classList.remove('hidden');

  autoPlayEnabled = await getAutoPlaySetting();
  await startTest(quizAnswers);
}

async function startTest(quizAnswers) {
  try {
    const payload = { user_id: currentUserId };
    if (quizAnswers && Object.keys(quizAnswers).length) {
      payload.quiz = quizAnswers;
    }

    const res = await fetch(`${DSP_SERVICE_URL}/api/dsp/adaptive/start`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const pair = await res.json();

    if (!res.ok) {
      document.getElementById('status').textContent = pair.error || 'Could not start the test.';
      document.getElementById('progress').innerHTML =
        '<span class="dot"></span><span>Could not start the test</span>';
      return;
    }

    renderPair(pair);
  } catch (err) {
    // Most likely the DSP service (ai_service.py) isn't running, or
    // DSP_SERVICE_URL in script.js points at the wrong machine/IP.
    document.getElementById('status').textContent =
      'Could not reach the DSP service. Is ai_service.py running, and is DSP_SERVICE_URL set to the right IP?';
    document.getElementById('progress').innerHTML =
      '<span class="dot"></span><span>Could not reach the DSP service</span>';
  }
}

/**
 * Decides whether the option cards can be chosen yet.
 *
 * A forced-choice comparison only means anything if the listener has
 * actually heard both options. Previously each card unlocked itself 600ms
 * after its own Play button was pressed, so someone could play A, wait
 * half a second and pick A without ever hearing B — and the test would
 * record that as a considered preference. Ten questions of that produces
 * a profile that looks entirely valid and measures nothing.
 *
 * The 600ms delay was also left over from when Play was a server call
 * that returned immediately. Browser playback lasts the length of the
 * clip, so the old timer unlocked the card while the audio was still
 * playing.
 *
 * Both cards now unlock together, and only once both have finished
 * playing.
 */
function updateChoiceAvailability() {
  const bothPlayed = hasPlayedA && hasPlayedB;

  [['a', hasPlayedA], ['b', hasPlayedB]].forEach(([side, played]) => {
    const card = document.getElementById(`option-${side}`);
    const hint = document.getElementById(`hint-${side}`);
    if (!card) return;

    card.classList.toggle('selectable', bothPlayed);

    if (hint) {
      hint.textContent = bothPlayed
        ? 'Tap to choose this one'
        : (played ? 'Now play the other one' : 'Play to unlock');
    }
  });
}

function renderPair(pair) {
  hasPlayedA = false;
  hasPlayedB = false;
  currentPair = pair;
  stopActiveSource();

  // Reset both cards back to their pre-play state for the new round — the
  // previous round leaves them 'selectable'/'chosen' with an unlocked hint,
  // which needs clearing here since test.html's own click-handling script
  // only sets those up once and won't do it again on its own.
  ['a', 'b'].forEach(side => {
    const card = document.getElementById(`option-${side}`);
    const hint = document.getElementById(`hint-${side}`);
    const btn = document.getElementById(`play-${side}`);
    if (card) card.classList.remove('playing', 'selectable', 'chosen');
    if (hint) hint.textContent = 'Play to unlock';
    if (btn) btn.disabled = false;
  });

  document.getElementById('status').textContent = 'Play both, then pick which you prefer.';

  const trackNameEl = document.getElementById('track-name');
  if (trackNameEl) trackNameEl.textContent = pair.sampleLabel ? `🎵 Track: ${pair.sampleLabel}` : '';

  document.getElementById('progress').innerHTML =
    `<span class="dot"></span><span>Question ${pair.question} of ${pair.totalQuestions} — ` +
    `tuning ${paramLabels[pair.param]} (round ${pair.round} of ${pair.totalRoundsForParam})</span>`;

  if (autoPlayEnabled) {
    document.getElementById('status').textContent = 'Auto-playing A, then B...';
    playSide('A').then(() => playSide('B'));
  }
}

function setStatus(text) {
  document.getElementById('status').textContent = text;
}

function markSidePlayed(side) {
  if (side === 'A') hasPlayedA = true;
  if (side === 'B') hasPlayedB = true;
}

/**
 * Play locally, so the listener hears it on their own headphones.
 * Returns false when the browser can't, so the caller can fall back.
 */
async function playLocally(side) {
  const gains = currentPair ? currentPair[side] : null;
  const sample = currentPair ? currentPair.sample : null;

  if (!browserAudioSupported() || !gains || !sample) return false;

  try {
    setStatus(bufferCache.has(browserSampleName(sample))
      ? 'Playing...' : 'Loading audio...');

    await playThroughBrowser(sample, gains);
    markSidePlayed(side);
    setStatus('Play both, then pick which you prefer.');
    return true;
  } catch (err) {
    // A missing clip, a codec the browser won't decode, or a blocked
    // audio context all land here.
    console.error('Browser playback failed, falling back to server:', err);
    return false;
  }
}

/**
 * Ask the server to play through CamillaDSP. Only audible to someone
 * sitting at the machine running ai_service.py, so this is a last resort.
 */
async function playOnServer(side) {
  try {
    const res = await fetch(`${DSP_SERVICE_URL}/api/dsp/adaptive/play`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ side, user_id: currentUserId }),
    });
    const data = await res.json();

    if (res.ok) {
      markSidePlayed(side);
    } else {
      setStatus(data.error || 'Something went wrong.');
    }
  } catch (err) {
    setStatus('Could not play the audio. Is ai_service.py running?');
  }

  // Server playback reports no completion, so wait out the known clip
  // length. Browser playback resolves when the buffer actually finishes.
  await new Promise(resolve => setTimeout(resolve, SAMPLE_PLAYBACK_MS));
}

async function playSide(side) {
  const btn = document.getElementById(side === 'A' ? 'play-a' : 'play-b');
  const optionCard = document.getElementById(side === 'A' ? 'option-a' : 'option-b');
  const otherCard = document.getElementById(side === 'A' ? 'option-b' : 'option-a');
  const original = btn.textContent;

  btn.textContent = 'Playing...';
  btn.disabled = true;
  otherCard.classList.remove('playing');
  optionCard.classList.add('playing');

  if (!await playLocally(side)) {
    await playOnServer(side);
  }

  optionCard.classList.remove('playing');
  btn.textContent = original;
  btn.disabled = false;

  // The cards become choosable only once both sides have been heard.
  updateChoiceAvailability();
}

async function chooseSide(side) {
  document.getElementById('status').textContent = 'Saving your answer...';

  const res = await fetch(`${DSP_SERVICE_URL}/api/dsp/adaptive/answer`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ preferred: side, user_id: currentUserId }),
  });
  const data = await res.json();

  if (data.error) {
    document.getElementById('status').textContent = data.error;
    return;
  }

  if (data.done) {
    showDoneScreen(data.profile);
  } else {
    renderPair(data.next);
  }
}

function showDoneScreen(profile) {
  document.getElementById('test-screen').style.display = 'none';
  document.getElementById('done-screen').style.display = 'block';

  const lines = Object.entries(profile)
    .map(([key, val]) => `${paramLabels[key] || key}: ${val > 0 ? '+' : ''}${val} dB`)
    .join('\n');

  document.getElementById('profile-output').textContent = lines;

  notifyTestComplete();
}

// Fires a browser notification if the user has the Notifications setting
// turned on and has granted permission (requested from settings.html).
async function notifyTestComplete() {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;

  try {
    const res = await fetch('api/settings.php');
    if (!res.ok) return;
    const settings = await res.json();
    if (settings.notifications) {
      new Notification('EqualizeME', {
        body: 'Your listening profile is ready — check your recommendations!',
      });
    }
  } catch (err) {
    console.error(err);
  }
}

initPicker();
