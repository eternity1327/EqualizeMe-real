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

// Runs on page load: checks the user is logged in and fills the track
// picker's dropdown. Does NOT start the test — that waits for the user to
// pick a track and press "Start Test" (see beginTest()).
async function initPicker() {
  currentUserId = await getCurrentUserId();
  if (!currentUserId) return;

  const select = document.getElementById('sample-select');
  try {
    const res = await fetch(`${DSP_SERVICE_URL}/api/dsp/adaptive/samples`);
    const data = await res.json();

    if (!res.ok || !data.samples || !data.samples.length) {
      throw new Error('no samples returned');
    }

    select.innerHTML = data.samples
      .map(s => `<option value="${s.file}">${s.label}</option>`)
      .join('');
  } catch (err) {
    select.innerHTML = '<option value="">Could not load tracks — is ai_service.py running?</option>';
  }
}

// Called when the user presses "Start Test" on the track picker.
async function beginTest() {
  const select = document.getElementById('sample-select');
  const chosenSample = select.value;

  document.getElementById('track-picker').style.display = 'none';
  document.getElementById('test-screen').style.display = 'block';
  document.getElementById('loadingOverlay').classList.remove('hidden');

  autoPlayEnabled = await getAutoPlaySetting();
  await startTest(chosenSample);
}

async function startTest(sampleFile) {
  try {
    const res = await fetch(`${DSP_SERVICE_URL}/api/dsp/adaptive/start`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user_id: currentUserId, sample: sampleFile }),
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
    `<span class="dot"></span><span>Tuning ${paramLabels[pair.param]} — Round ${pair.round} of ${pair.totalRoundsForParam} ` +
    `(Parameter ${pair.paramNumber} of ${pair.totalParams})</span>`;

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
