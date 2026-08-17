let hasPlayedA = false;
let hasPlayedB = false;
let currentUserId = null;
let autoPlayEnabled = false;

const SAMPLE_PLAYBACK_MS = 10000;

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
    const res = await fetch(`${DSP_SERVICE_URL}/api/quiz/questions`);
    const data = await res.json();

    if (!res.ok || !data.questions || !data.questions.length) {
      throw new Error('no questions returned');
    }

    container.innerHTML = data.questions.map(buildQuizQuestion).join('');
    wireQuizHighlighting(container);
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
    document.getElementById('status').textContent =
      'Could not reach the DSP service. Is ai_service.py running, and is DSP_SERVICE_URL set to the right IP?';
    document.getElementById('progress').innerHTML =
      '<span class="dot"></span><span>Could not reach the DSP service</span>';
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
    setStatus(bufferCache.has(browserSampleName(sample))
      ? 'Playing...' : 'Loading audio...');

    await playThroughBrowser(sample, gains);
    markSidePlayed(side);
    setStatus('Play both, then pick which you prefer.');
    return true;
  } catch (err) {
    console.error('Browser playback failed, falling back to server:', err);
    return false;
  }
}

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
