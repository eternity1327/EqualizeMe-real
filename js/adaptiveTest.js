// DSP_SERVICE_URL is declared once in script.js (loaded before this file on
// test.html) — redeclaring it here as `const` threw
// "Identifier 'DSP_SERVICE_URL' has already been declared", which silently
// aborted this entire script and took playSide/chooseSide/startTest with it.

let hasPlayedA = false;
let hasPlayedB = false;
let currentUserId = null;
let autoPlayEnabled = false;

// Matches the fixed length of the trimmed clips in data/audio/samples/.
// Used both to keep the "playing" indicator on for the actual clip length
// and, for Auto Play, to know when it's safe to hand off from A to B
// without cutting the first clip off mid-playback.
const SAMPLE_PLAYBACK_MS = 10000;

const paramLabels = {
  bassGain: 'Bass',
  trebleGain: 'Treble',
  presenceGain: 'Presence (3kHz)',
};

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

    container.innerHTML = data.questions.map(q => `
      <div class="quiz-q" data-question-id="${q.id}">
        <div class="quiz-prompt">${q.question}</div>
        <div class="quiz-options">
          ${q.options.map(o => `
            <label class="quiz-option">
              <input type="radio" name="q-${q.id}" value="${o.value}">
              <span>${o.label}</span>
            </label>
          `).join('')}
        </div>
      </div>
    `).join('');

    // Highlight the chosen option's row, not just the radio dot.
    container.querySelectorAll('.quiz-q').forEach(group => {
      group.addEventListener('change', () => {
        group.querySelectorAll('.quiz-option').forEach(opt => {
          opt.classList.toggle('picked', opt.querySelector('input').checked);
        });
      });
    });
  } catch (err) {
    // Quiz is an enhancement, not a gate — if it can't load, let them
    // straight into the listening test rather than dead-ending here.
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

function renderPair(pair) {
  hasPlayedA = false;
  hasPlayedB = false;

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

async function playSide(side) {
  const btn = document.getElementById(side === 'A' ? 'play-a' : 'play-b');
  const optionCard = document.getElementById(side === 'A' ? 'option-a' : 'option-b');
  const otherCard = document.getElementById(side === 'A' ? 'option-b' : 'option-a');
  const original = btn.textContent;

  btn.textContent = 'Playing...';
  btn.disabled = true;
  otherCard.classList.remove('playing');
  optionCard.classList.add('playing');

  try {
    const res = await fetch(`${DSP_SERVICE_URL}/api/dsp/adaptive/play`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ side, user_id: currentUserId }),
    });
    const data = await res.json();

    if (!res.ok) {
      document.getElementById('status').textContent = data.error || 'Something went wrong.';
    } else {
      if (side === 'A') hasPlayedA = true;
      if (side === 'B') hasPlayedB = true;
    }
  } catch (err) {
    document.getElementById('status').textContent = 'Could not reach the DSP service. Is it running?';
  }

  // Wait out the actual clip length before handing control back — this is
  // what lets Auto Play safely start B only once A has really finished,
  // instead of cutting it off mid-playback.
  await new Promise(resolve => setTimeout(resolve, SAMPLE_PLAYBACK_MS));

  optionCard.classList.remove('playing');
  btn.textContent = original;
  btn.disabled = false;
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
