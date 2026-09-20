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

/* ─────────── continuous playback with live A/B switching ─────────── */

// The filter chain is built once per playback and then kept. Switching
// sides only changes the gain on the filters it already owns.
//
// This replaces playing A from the start, then playing B from the start.
// Restarting the clip means comparing a sound against a memory of a sound,
// which is exactly the comparison people are worst at -- small differences
// vanish in the gap. Switching in place, mid-phrase, keeps both versions in
// the same moment of music and makes the same difference obvious.
let liveFilters = [];      // the biquads currently in the signal path
let liveSide = null;       // 'A' or 'B'
let livePair = null;       // the pair being compared
let waveFrame = null;      // requestAnimationFrame handle for the playhead

// Gains are ramped rather than assigned. A bare `.value = x` steps the
// coefficient instantly and clicks audibly, which would itself become a cue
// -- the listener would hear the switch rather than the difference.
const SWITCH_RAMP_SECONDS = 0.02;

function setSideGains(gains) {
  const ctx = audioCtx;
  if (!ctx) return;
  const at = ctx.currentTime + SWITCH_RAMP_SECONDS;
  for (const filter of liveFilters) {
    const target = Number(gains?.[filter._gainKey] ?? 0);
    filter.gain.linearRampToValueAtTime(target, at);
  }
}

/**
 * Switch which version is playing, without interrupting it.
 *
 * If nothing is playing this only records the choice, so pressing A before
 * Play behaves sensibly rather than doing nothing.
 */
function switchSide(side) {
  liveSide = side;
  if (livePair && isPlaying) {
    setSideGains(livePair[side]);
    // Only counts as heard if it was actually audible. Switching while
    // paused sets which version plays next; it is not listening to it.
    markSidePlayed(side);
  }
  updateSideButtons();
}

/* ─────────────────────────── the transport ─────────────────────────── */

// A Web Audio buffer source cannot be paused -- once stopped it is spent,
// and a new one has to be created. So position is tracked here rather than
// asked of the node: `positionAtStart` is where the current source began,
// `startedAt` is the context clock reading when it did, and the difference
// gives the playhead. Pausing stops the node and keeps the number.
let playBuffer = null;        // the decoded clip
let positionAtStart = 0;      // seconds into the clip when this source began
let startedAt = 0;            // ctx.currentTime at that moment
let isPlaying = false;

function playbackPosition() {
  if (!playBuffer) return 0;
  const raw = isPlaying && audioCtx
    ? positionAtStart + (audioCtx.currentTime - startedAt)
    : positionAtStart;
  return Math.max(0, Math.min(playBuffer.duration, raw));
}

/**
 * Build the signal path and start at `offset` seconds.
 *
 * Called by play, by seek and by rewind-while-playing -- anything that
 * needs the audio running from a particular point. The filters are rebuilt
 * each time because they belong to the source they are connected to.
 */
function startSourceAt(offset) {
  const ctx = audioCtx;
  if (!ctx || !playBuffer) return;

  stopActiveSource();

  const source = ctx.createBufferSource();
  source.buffer = playBuffer;

  let node = source;
  liveFilters = [];
  for (const spec of EQ_FILTERS) {
    const filter = ctx.createBiquadFilter();
    filter.type = spec.type;
    filter.frequency.value = spec.frequency;
    filter.Q.value = spec.Q;
    filter.gain.value = Number(livePair?.[liveSide]?.[spec.gainKey] ?? 0);
    filter._gainKey = spec.gainKey;
    node.connect(filter);
    node = filter;
    liveFilters.push(filter);
  }
  node.connect(ctx.destination);

  // Reaching the end is a stop, not a pause: the playhead stays at the end
  // so Play would otherwise start a new source that instantly finishes.
  // Rewind, or clicking the waveform, is what gets you moving again.
  source.onended = () => {
    if (activeSource !== source) return;   // superseded by a seek
    activeSource = null;
    isPlaying = false;
    positionAtStart = playBuffer.duration;
    updateSideButtons();
  };

  positionAtStart = Math.max(0, Math.min(playBuffer.duration, offset));
  startedAt = ctx.currentTime;
  source.start(0, positionAtStart);

  activeSource = source;
  isPlaying = true;
}

async function loadIntoTransport(filename, pair, side) {
  const ctx = await getAudioContext();
  playBuffer = await loadSample(filename);
  livePair = pair;
  liveSide = side;
  positionAtStart = 0;
  startedAt = ctx.currentTime;
  isPlaying = false;
  drawTrack();
}

async function transportPlay() {
  if (isPlaying) return;
  await getAudioContext();

  // At the end, Play means play again rather than doing nothing.
  const from = playBuffer && playbackPosition() >= playBuffer.duration - 0.01
    ? 0
    : playbackPosition();

  startSourceAt(from);
  markSidePlayed(liveSide);
  startPlayheadLoop();
  updateSideButtons();
}

function transportPause() {
  if (!isPlaying) return;
  positionAtStart = playbackPosition();   // freeze before the node dies
  stopActiveSource();
  isPlaying = false;
  updateSideButtons();
  drawTrack();
}

function transportRewind() {
  if (isPlaying) {
    startSourceAt(0);
  } else {
    positionAtStart = 0;
    drawTrack();
  }
  updateSideButtons();
}

function transportSeek(seconds) {
  if (!playBuffer) return;
  if (isPlaying) {
    startSourceAt(seconds);
  } else {
    positionAtStart = Math.max(0, Math.min(playBuffer.duration, seconds));
    drawTrack();
  }
}

function stopLivePlayback() {
  stopActiveSource();
  if (waveFrame !== null) {
    cancelAnimationFrame(waveFrame);
    waveFrame = null;
  }
  liveFilters = [];
  playBuffer = null;
  positionAtStart = 0;
  isPlaying = false;
  clearTrack();
  updateSideButtons();
}

/* ──────────────────── the file, drawn as a whole ──────────────────── */

// The shape of the entire clip, computed once and cached by file name.
// Redoing it every frame would walk hundreds of thousands of samples sixty
// times a second for a picture that never changes.
const peakCache = new Map();
const PEAK_COLUMNS = 320;

function peaksFor(name, buffer) {
  if (peakCache.has(name)) return peakCache.get(name);

  const data = buffer.getChannelData(0);
  const per = Math.floor(data.length / PEAK_COLUMNS) || 1;
  const peaks = new Float32Array(PEAK_COLUMNS);

  for (let col = 0; col < PEAK_COLUMNS; col++) {
    let max = 0;
    const start = col * per;
    const end = Math.min(start + per, data.length);
    for (let i = start; i < end; i++) {
      const v = Math.abs(data[i]);
      if (v > max) max = v;
    }
    peaks[col] = max;
  }

  peakCache.set(name, peaks);
  return peaks;
}

function trackCanvas() {
  return document.getElementById('waveform');
}

function clearTrack() {
  const canvas = trackCanvas();
  if (!canvas) return;
  canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
  const el = document.getElementById('track-time');
  if (el) el.textContent = '0:00 / 0:00';
}

function formatTime(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}

function drawTrack() {
  const canvas = trackCanvas();
  if (!canvas || !playBuffer || !currentPair) return;

  const rect = canvas.getBoundingClientRect();
  const dpr = window.devicePixelRatio || 1;
  if (canvas.width !== Math.round(rect.width * dpr)) {
    canvas.width = Math.round(rect.width * dpr);
    canvas.height = Math.round(rect.height * dpr);
  }

  const g = canvas.getContext('2d');
  const w = canvas.width;
  const h = canvas.height;
  const mid = h / 2;

  const styles = getComputedStyle(document.documentElement);
  const played = styles.getPropertyValue('--accent').trim() || '#f2a93b';
  const ahead = styles.getPropertyValue('--border').trim() || '#d8dee6';
  const ink = styles.getPropertyValue('--text-muted').trim() || '#64748b';

  g.clearRect(0, 0, w, h);

  const peaks = peaksFor(browserSampleName(currentPair.sample), playBuffer);
  const pos = playbackPosition();
  const progress = playBuffer.duration ? pos / playBuffer.duration : 0;

  // Bars, not a line. A line is an oscilloscope; this is the file.
  const barW = w / peaks.length;
  for (let i = 0; i < peaks.length; i++) {
    const x = i * barW;
    const amp = Math.max(peaks[i] * mid * 0.92, dpr);
    g.fillStyle = (i / peaks.length) <= progress ? played : ahead;
    g.fillRect(x, mid - amp, Math.max(barW - dpr, 1), amp * 2);
  }

  // Playhead.
  const px = Math.round(progress * w);
  g.fillStyle = played;
  g.fillRect(px - dpr, 0, dpr * 2, h);

  // Second markers along the bottom, as many as will fit legibly.
  const every = playBuffer.duration > 30 ? 5 : 1;
  g.fillStyle = ink;
  g.font = (10 * dpr) + 'px Arial';
  g.textBaseline = 'bottom';
  for (let t = every; t < playBuffer.duration; t += every) {
    const x = (t / playBuffer.duration) * w;
    g.globalAlpha = 0.35;
    g.fillRect(x, h - 10 * dpr, dpr, 6 * dpr);
    g.globalAlpha = 1;
    g.fillText(formatTime(t), x + 3 * dpr, h - 1 * dpr);
  }

  const timeEl = document.getElementById('track-time');
  if (timeEl) {
    timeEl.textContent = formatTime(pos) + ' / ' + formatTime(playBuffer.duration);
  }
}

function startPlayheadLoop() {
  if (waveFrame !== null) cancelAnimationFrame(waveFrame);
  const frame = () => {
    drawTrack();
    if (isPlaying) {
      waveFrame = requestAnimationFrame(frame);
    } else {
      waveFrame = null;
    }
  };
  frame();
}

/**
 * Click or drag anywhere on the file to move the playhead there.
 *
 * Wired once, on the canvas, rather than per question -- the canvas
 * outlives the pairs drawn on it.
 */
function wireTrackSeeking() {
  const canvas = trackCanvas();
  if (!canvas || canvas._seekWired) return;
  canvas._seekWired = true;

  const seekFromEvent = event => {
    if (!playBuffer) return;
    const rect = canvas.getBoundingClientRect();
    const ratio = (event.clientX - rect.left) / rect.width;
    transportSeek(Math.max(0, Math.min(1, ratio)) * playBuffer.duration);
  };

  canvas.addEventListener('pointerdown', event => {
    canvas.setPointerCapture(event.pointerId);
    seekFromEvent(event);
  });
  canvas.addEventListener('pointermove', event => {
    if (event.buttons === 1) seekFromEvent(event);
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

/**
 * A side counts as heard once it has been selected during playback.
 *
 * It used to mean "that clip played to the end", which no longer happens:
 * playback loops and the listener switches between sides rather than
 * waiting for either to finish. The guarantee worth keeping is the one
 * that mattered -- nobody chooses a side without having heard both -- and
 * switching to a side while the audio is running is exactly that.
 */
function updateChoiceAvailability() {
  const bothHeard = hasPlayedA && hasPlayedB;

  [['a', hasPlayedA], ['b', hasPlayedB]].forEach(([side, heard]) => {
    const card = document.getElementById(`option-${side}`);
    const hint = document.getElementById(`hint-${side}`);
    if (!card) return;

    card.classList.toggle('selectable', bothHeard);

    if (hint) {
      hint.textContent = bothHeard
        ? 'Tap to choose this one'
        : (heard ? 'Now listen to the other one' : 'Listen to both to unlock');
    }
  });
}

function renderPair(pair) {
  hasPlayedA = false;
  hasPlayedB = false;
  currentPair = pair;

  // Stop the previous question's clip and tear down its filter chain.
  // Leaving it running would carry the last question's gains into this one.
  stopLivePlayback();
  livePair = pair;
  liveSide = 'A';

  ['a', 'b'].forEach(side => {
    const card = document.getElementById(`option-${side}`);
    const hint = document.getElementById(`hint-${side}`);
    if (card) card.classList.remove('playing', 'selectable', 'chosen');
    if (hint) hint.textContent = 'Listen to both to unlock';
  });

  document.getElementById('status').textContent =
    'Press play, switch between A and B, then pick one.';

  // The clip name arrives as "Song — Section", so it splits into a title
  // and the part of the song this is. Naming the section matters: a
  // difference that is obvious in a chorus can be inaudible in an intro,
  // and the listener deserves to know which they are judging.
  const label = pair.sampleLabel || '';
  const [songTitle, songSection] = label.split('—').map(s => s.trim());

  const titleEl = document.getElementById('track-title');
  if (titleEl) titleEl.textContent = songTitle || label;

  const sectionEl = document.getElementById('track-section');
  if (sectionEl) {
    sectionEl.textContent = songSection || '';
    sectionEl.style.display = songSection ? '' : 'none';
  }

  const timeEl = document.getElementById('track-time');
  if (timeEl) timeEl.textContent = '0:00 / 0:00';

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

  updateSideButtons();

  if (autoPlayEnabled) {
    // Starts the comparison on A and leaves it running. It no longer plays
    // A then B in sequence, because there is no longer a sequence -- both
    // are one continuous playback the listener switches between.
    togglePlayback();
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
 * Reflect the playing state in the transport controls.
 *
 * Kept in one place because three things call it -- start, stop and switch
 * -- and having each set the buttons itself is how they drift apart.
 */
function updateSideButtons() {
  const playBtn = document.getElementById('play-toggle');
  if (playBtn) {
    playBtn.textContent = isPlaying ? '❚❚ Pause' : '▶ Play';
    playBtn.classList.toggle('is-playing', isPlaying);
  }

  // A and B stay usable while paused. Switching sides with the audio
  // stopped is a legitimate thing to do -- it sets which version the next
  // press of play will start with.
  const loaded = Boolean(playBuffer);
  ['A', 'B'].forEach(side => {
    const btn = document.getElementById('side-' + side.toLowerCase());
    if (!btn) return;
    btn.classList.toggle('active', liveSide === side);
    btn.disabled = false;
  });

  const rewindBtn = document.getElementById('rewind-btn');
  if (rewindBtn) rewindBtn.disabled = !loaded;

  ['a', 'b'].forEach(letter => {
    const card = document.getElementById('option-' + letter);
    if (card) {
      card.classList.toggle('playing',
        isPlaying && liveSide === letter.toUpperCase());
    }
  });

  if (typeof updateChoiceAvailability === 'function') {
    updateChoiceAvailability();
  }
}


/**
 * Play or pause. The page's main transport button calls this.
 *
 * Pause keeps the position, so pressing play again continues from where it
 * was rather than starting over. Rewind is a separate control precisely so
 * that "go back to the beginning" is a deliberate act.
 */
async function togglePlayback() {
  if (isPlaying) {
    transportPause();
    setStatus('Paused. Play to continue, or rewind to start again.');
    return;
  }

  const sample = currentPair ? currentPair.sample : null;
  if (!browserAudioSupported() || !currentPair || !sample) {
    reportPlaybackFailure();
    return;
  }

  try {
    // "Loading" only when the clip still has to come off the network.
    // Once downloaded, decoding takes a moment at most, and calling that
    // loading made a warmed clip look slower than it was.
    const webName = browserSampleName(sample);
    const ready = bufferCache.has(webName) || encodedCache.has(webName);
    if (!ready) setStatus('Loading audio...');

    if (!playBuffer) {
      await loadIntoTransport(sample, currentPair, liveSide || 'A');
      wireTrackSeeking();
    }

    await transportPlay();
    setStatus('Switch between A and B as it plays, then pick one.');
  } catch (err) {
    console.error('Browser playback failed:', err);
    reportPlaybackFailure();
  }
}

// The rewind control. Separate from play/pause on purpose: going back to
// the start is a decision, and folding it into play would take the position
// away from anyone who only meant to pause.
function rewindPlayback() {
  transportRewind();
  setStatus(isPlaying
    ? 'Back to the start.'
    : 'Back to the start. Press play when ready.');
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

/**
 * Kept as the entry point the page already calls.
 *
 * If the comparison is running this switches side in place; if it is not,
 * it starts playback on that side. Either way the listener gets what they
 * asked for from one control, rather than having to press play first.
 */
async function playSide(side) {
  if (activeSource) {
    switchSide(side);
    return;
  }
  liveSide = side;
  await togglePlayback();
}

async function chooseSide(side) {
  // Silence first. Playback loops, so without this the previous question's
  // clip keeps running underneath the next one being set up -- and if the
  // answer fails to save, the listener is left with audio playing against a
  // question they have already answered.
  stopLivePlayback();

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
