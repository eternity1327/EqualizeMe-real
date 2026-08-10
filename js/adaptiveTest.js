const DSP_SERVICE_URL = "http://192.168.1.9:5001";

let hasPlayedA = false;
let hasPlayedB = false;
let currentUserId = null;

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

async function startTest() {
  currentUserId = await getCurrentUserId();
  if (!currentUserId) return;

  const res = await fetch(`${DSP_SERVICE_URL}/api/dsp/adaptive/start`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: currentUserId }),
  });
  const pair = await res.json();
  renderPair(pair);
}

function renderPair(pair) {
  hasPlayedA = false;
  hasPlayedB = false;
  document.getElementById('prefer-a').disabled = true;
  document.getElementById('prefer-b').disabled = true;
  document.getElementById('option-a').classList.remove('playing');
  document.getElementById('option-b').classList.remove('playing');
  document.getElementById('status').textContent = 'Play both, then pick which you prefer.';

  document.getElementById('progress').innerHTML =
    `<span class="dot"></span><span>Tuning ${paramLabels[pair.param]} — Round ${pair.round} of ${pair.totalRoundsForParam} ` +
    `(Parameter ${pair.paramNumber} of ${pair.totalParams})</span>`;
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

      if (hasPlayedA) document.getElementById('prefer-a').disabled = false;
      if (hasPlayedB) document.getElementById('prefer-b').disabled = false;
    }
  } catch (err) {
    document.getElementById('status').textContent = 'Could not reach the DSP service. Is it running?';
  }

  setTimeout(() => optionCard.classList.remove('playing'), 6000);

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
}

startTest();
