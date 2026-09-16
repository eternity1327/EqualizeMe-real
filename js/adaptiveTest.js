let hasPlayedA = false;
let hasPlayedB = false;
let currentUserId = null;
let autoPlayEnabled = false;

const paramLabels = {
  bassGain: 'Bass',
  trebleGain: 'Treble',
  presenceGain: 'Presence (3kHz)',
};

let currentPair = null;

const EQ_FILTERS = EQ_BANDS;

const SAMPLES_BASE_URL = 'data/audio/samples/';

let audioCtx = null;
let activeSource = null;
const bufferCache = new Map();

function browserAudioSupported() {
  return typeof (window.AudioContext || window.webkitAudioContext) !== 'undefined';
}

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

function browserSampleName(filename) {
  return filename.replace(/\.wav$/i, '.mp3');
}

// Downloaded but not yet decoded clips, keyed by file name.
//
// Kept separate from bufferCache on purpose. Decoding needs an AudioContext,
// and a browser will not let one start until the user has interacted with
// the page — so anything that waits for a context cannot run during the
// pre-quiz. Downloading has no such restriction. Splitting the two lets the
// slow half, the network, happen early and leaves only the fast half for
// the moment Play is pressed.
const encodedCache = new Map();

// One in-flight request per clip. Without this, warming a sample and then
// pressing Play before it arrives would fetch the same 300 KB twice.
const inFlight = new Map();

function fetchEncoded(webName) {
  if (encodedCache.has(webName)) {
    return Promise.resolve(encodedCache.get(webName));
  }
  if (inFlight.has(webName)) {
    return inFlight.get(webName);
  }

  const request = fetch(SAMPLES_BASE_URL + encodeURIComponent(webName))
    .then(res => {
      if (!res.ok) {
        throw new Error(`Could not fetch ${webName} (${res.status})`);
      }
      return res.arrayBuffer();
    })
    .then(encoded => {
      encodedCache.set(webName, encoded);
      inFlight.delete(webName);
      return encoded;
    })
    .catch(err => {
      inFlight.delete(webName);
      throw err;
    });

  inFlight.set(webName, request);
  return request;
}

async function loadSample(filename) {
  const webName = browserSampleName(filename);

  if (bufferCache.has(webName)) {
    return bufferCache.get(webName);
  }

  const encoded = await fetchEncoded(webName);
  const ctx = await getAudioContext();

  // decodeAudioData detaches the ArrayBuffer it is given, which would empty
  // the cached copy and make a second decode fail. Hand it a copy.
  const buffer = await ctx.decodeAudioData(encoded.slice(0));

  bufferCache.set(webName, buffer);
  return buffer;
}

/**
 * Start downloading and decoding a sample without waiting for it.
 *
 * Each clip is about 300 KB, and on shared hosting the round trip is
 * noticeable. Fetching only when Play is pressed put that wait directly in
 * front of the listener, every time a new clip came up — which is the
 * delay testers reported on the first play.
 *
 * loadSample() already caches by name and returns the cached buffer on a
 * second call, so warming is just calling it early and throwing away the
 * promise. If it fails there is nothing to do here: the real play will try
 * again and report properly. The empty catch is to stop an unhandled
 * rejection appearing in the console for a request nobody asked for.
 */
function warmSample(filename) {
  if (!filename || !browserAudioSupported()) return;
  // Download only. Decoding is left for playback, because it needs an
  // AudioContext and the browser will not start one until the user has
  // interacted with the page — so a warm during the pre-quiz would
  // otherwise do nothing at all.
  fetchEncoded(browserSampleName(filename)).catch(() => {});
}


/**
 * The clip a given question uses.
 *
 * Mirrors at_sample_for_question() in api/adaptive_test.php, which maps
 * question N to sampleN. Duplicating that rule here is deliberate and
 * narrow: it is only ever used to fetch ahead, so if the two ever disagree
 * the cost is a wasted download, not a wrong question. The pair the server
 * sends still decides what is actually played.
 */
function sampleForQuestion(questionNumber) {
  return 'sample' + questionNumber + '.wav';
}


function stopActiveSource() {
  if (activeSource) {
    try {
      activeSource.stop();
    } catch (err) {
    }
    activeSource = null;
  }
}

async function playThroughBrowser(filename, gains) {
  const ctx = await getAudioContext();
  const buffer = await loadSample(filename);

  stopActiveSource();

  const source = ctx.createBufferSource();
  source.buffer = buffer;

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

async function initPicker() {
  currentUserId = await getCurrentUserId();
  if (!currentUserId) return;

  const startBtn = document.getElementById('start-test-btn');
  if (startBtn) startBtn.disabled = false;
}

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
    const res = await fetch(API.quizQuestions);
    const data = await res.json();

    if (!res.ok || !data.questions || !data.questions.length) {
      throw new Error('no questions returned');
    }

    container.innerHTML = data.questions.map(buildQuizQuestion).join('');
    wireQuizHighlighting(container);

    // The first listening clip downloads while the questions are being
    // answered. This is the delay testers actually hit: answering the
    // pre-quiz takes a while, the listening test then opens, and the first
    // Play sat waiting on a 300 KB fetch that could already have happened.
    warmSample(sampleForQuestion(1));
  } catch (err) {
    container.innerHTML =
      '<p class="subtext">Could not load the questions — skipping ahead to the listening test.</p>';
    document.getElementById('quiz-submit-btn').textContent = 'Continue';
  }
}

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

/* ───────────────────────── consent and apparatus ──────────────────────── */

// Chosen at the start of each test and sent with the first request. Not
// remembered between tests on purpose: people change headphones, and a
// value carried over would quietly mislabel the next sitting.
let selectedApparatus = null;

const APPARATUS_CHOICES = [
  ['iem', 'In-ear monitors (wired)'],
  ['earbuds', 'Wireless earbuds'],
  ['headphones', 'Over-ear or on-ear headphones'],
  ['other', 'Something else, or not sure'],
];

async function beginConsent() {
  document.getElementById('track-picker').style.display = 'none';
  document.getElementById('consent-screen').style.display = 'block';

  document.getElementById('apparatus-options').innerHTML =
    APPARATUS_CHOICES.map(([value, label]) => `
      <label class="quiz-option">
        <input type="radio" name="apparatus" value="${value}">
        <span>${label}</span>
      </label>`).join('');

  // The terms step was removed from this screen on request. What remains
  // is the apparatus question, which is asked every test because the answer
  // genuinely can change between sittings.
}

async function acceptConsent() {
  const errorEl = document.getElementById('consent-error');

  const picked = document.querySelector('input[name="apparatus"]:checked');
  if (!picked) {
    errorEl.textContent = 'Please say what you are listening through.';
    return;
  }

  errorEl.textContent = '';
  selectedApparatus = picked.value;

  document.getElementById('consent-screen').style.display = 'none';
  beginQuiz();
}


async function beginTest(quizAnswers) {
  document.getElementById('track-picker').style.display = 'none';
  document.getElementById('test-screen').style.display = 'block';
  document.getElementById('loadingOverlay').classList.remove('hidden');

  autoPlayEnabled = await getAutoPlaySetting();
  await startTest(quizAnswers);
}

async function startTest(quizAnswers) {
  try {
    // No user id here, and nowhere to put one. The endpoint reads the
    // session, so a test can only ever be started as yourself.
    const payload = {};
    if (quizAnswers && Object.keys(quizAnswers).length) {
      payload.quiz = quizAnswers;
    }
    // Recorded with the result. The server checks it against its own list
    // and stores null if it does not recognise it, so nothing here has to
    // be trusted.
    if (selectedApparatus) {
      payload.apparatus = selectedApparatus;
    }

    const res = await apiPost(API.testStart, payload);
    const pair = await res.json();

    if (!res.ok) {
      document.getElementById('status').textContent = pair.error || 'Could not start the test.';
      document.getElementById('progress').innerHTML =
        '<span class="dot"></span><span>Could not start the test</span>';
      return;
    }

    renderPair(pair);
  } catch (err) {
    document.getElementById('status').textContent =
      'Could not reach the server. Check your connection and try again.';
    document.getElementById('progress').innerHTML =
      '<span class="dot"></span><span>Could not reach the server</span>';
  }
}

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

  // Fetch this question's clip as soon as the question appears rather than
  // when Play is pressed, and start the next one too. Reading the question
  // takes a few seconds; the download can happen during them instead of
  // afterwards.
  warmSample(pair.sample);
  if (pair.question < pair.totalQuestions) {
    warmSample(sampleForQuestion(pair.question + 1));
  }

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

async function playLocally(side) {
  const gains = currentPair ? currentPair[side] : null;
  const sample = currentPair ? currentPair.sample : null;

  if (!browserAudioSupported() || !gains || !sample) return false;

  try {
    // "Loading" only when the clip still has to come off the network.
    // Once it has been downloaded, decoding takes a moment at most, and
    // calling that loading made a warmed clip look slower than it was.
    const webName = browserSampleName(sample);
    const ready = bufferCache.has(webName) || encodedCache.has(webName);
    setStatus(ready ? 'Playing...' : 'Loading audio...');

    await playThroughBrowser(sample, gains);
    markSidePlayed(side);
    setStatus('Play both, then pick which you prefer.');
    return true;
  } catch (err) {
    console.error('Browser playback failed:', err);
    return false;
  }
}

/**
 * There is no longer a fallback, and there was never a sensible one.
 *
 * The old path asked the server to play the clip through CamillaDSP — its
 * own sound card. That only ever made sense while the server and the
 * listener were the same laptop. Hosted anywhere else it plays audio into
 * an empty room in a data centre, and the test still cannot be answered.
 *
 * So the honest behaviour is to say the browser could not do it, rather
 * than to appear to play something the user cannot hear.
 */
function reportPlaybackFailure() {
  setStatus(
    "Your browser couldn't play the audio. Try Chrome, Edge or Firefox, "
    + "check the tab isn't muted, and make sure something is plugged in."
  );
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
    reportPlaybackFailure();
  }

  optionCard.classList.remove('playing');
  btn.textContent = original;
  btn.disabled = false;

  updateChoiceAvailability();
}

async function chooseSide(side) {
  document.getElementById('status').textContent = 'Saving your answer...';

  const res = await apiPost(API.testAnswer, { preferred: side });
  const data = await res.json();

  if (data.error) {
    document.getElementById('status').textContent = data.error;
    return;
  }

  if (data.done) {
    showDoneScreen(data.profile, data.confidence, data.precision);
  } else {
    renderPair(data.next);
  }
}

function showDoneScreen(profile, confidence, precision) {
  document.getElementById('test-screen').style.display = 'none';
  document.getElementById('done-screen').style.display = 'block';

  const lines = Object.entries(profile).map(([key, val]) => {
    const label = paramLabels[key] || key;
    const sign = val > 0 ? '+' : '';

    // precision is keyed by the same band names as the profile, and holds
    // how far each value could be off — worth showing, since a figure
    // quoted to one decimal place implies more certainty than the test
    // actually has.
    const margin = precision && precision[key] !== undefined
      ? `  (± ${precision[key]} dB)`
      : '';

    return `${label}: ${sign}${val} dB${margin}`;
  });

  if (typeof confidence === 'number') {
    lines.push('');
    lines.push(`Confidence: ${confidence}%`);
  }

  document.getElementById('profile-output').textContent = lines.join('\n');

  notifyTestComplete();
}

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
