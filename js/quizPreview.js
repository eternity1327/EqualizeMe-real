/**
 * Hear what each pre-quiz answer means, by clicking it.
 *
 * Two of the six written questions describe sound, and both are far easier
 * to answer by ear than by reading. Picking an option plays a demonstration
 * in a small panel in the corner.
 *
 *   "What do you listen to most?"   plays a track of that genre, pulled
 *                                   live from the Audius catalogue
 *
 *   "Which describes your ideal     plays ONE clip with that voicing
 *    sound?"                        applied through the EQ
 *
 * The second is the more useful of the two, and the reason it uses a single
 * clip is the same reason the listening test switches in place rather than
 * restarting: comparing two voicings on two different pieces of music
 * compares the music. Same clip, different EQ, and the difference is the
 * only thing that changed.
 *
 * WHAT THESE GAINS ARE NOT
 * The demo voicings below are illustrative and deliberately exaggerated —
 * they are chosen to be obvious over a few seconds on a laptop speaker.
 * They are NOT the scoring weights in api/pre_quiz.php, which stay on the
 * server and are never sent to the browser precisely so that nobody can
 * answer strategically. Someone reading this file learns that "warm" means
 * more bass, which is the entire point of letting them hear it; they learn
 * nothing about what the answer is worth.
 *
 * Shares the audio graph in songSearch.js, so a preview and the
 * results-screen player can never play over each other.
 */

// Audius genre names, in preference order per option. Tried in turn because
// a small catalogue can have nothing trending in a narrow genre today.
const PREVIEW_GENRES = {
  hiphop: ["Hip-Hop/Rap", "R&B/Soul", "Electronic"],
  rock: ["Rock", "Metal", "Alternative"],
  classical: ["Jazz", "Classical", "Acoustic"],
  pop: ["Pop", "Electronic"],
};

// If trending-by-genre comes back empty for every genre above, fall back to
// an ordinary keyword search. Slower and less on-the-nose, but it is the
// difference between a demonstration and a dead button.
const PREVIEW_KEYWORDS = {
  hiphop: "hip hop beat",
  rock: "rock guitar",
  classical: "jazz piano",
  pop: "pop",
};

// The clip every voicing demo plays. Short and already on the server, so
// the preview starts almost immediately -- a full track would spend the
// first few seconds buffering, which is most of the time anyone spends
// listening to one of these.
const PREVIEW_CLIP = "data/audio/samples/sample1.mp3";

const PREVIEW_VOICINGS = {
  warm: { bassGain: 5, presenceGain: 0, trebleGain: -2 },
  balanced: { bassGain: 0, presenceGain: 0, trebleGain: 0 },
  bright: { bassGain: -1, presenceGain: 1, trebleGain: 5 },
  vshape: { bassGain: 4, presenceGain: -3, trebleGain: 4 },
};

// Long enough to judge, short enough not to become the activity. The
// listener is filling in a questionnaire, not auditioning.
const PREVIEW_SECONDS = 12;

let previewTimer = null;
let previewToken = 0;      // cancels a slow fetch whose option was replaced


/**
 * Play a demonstration for one answer, if that answer has one.
 *
 * Unknown questions and unknown options fall through silently. Four of the
 * six questions have no natural sound — "where do you usually listen" is
 * not a thing you can play — and inventing audio for them would teach the
 * listener something false.
 */
async function quizPreview(questionId, optionValue) {
  if (questionId === "signature") {
    previewVoicing(optionValue);
    return;
  }
  if (questionId === "genre") {
    await previewGenre(optionValue);
  }
}


/* ───────────────────────────── voicings ───────────────────────────────── */

function previewVoicing(value) {
  const gains = PREVIEW_VOICINGS[value];
  if (!gains) return;

  const label = {
    warm: "Warm and full",
    balanced: "Balanced and natural",
    bright: "Bright and detailed",
    vshape: "Punchy bass and sparkly highs",
  }[value] || "Preview";

  previewPlay(PREVIEW_CLIP, gains, label, "same clip, different tuning");
}


/* ────────────────────────────── genres ────────────────────────────────── */

async function previewGenre(value) {
  const token = ++previewToken;

  previewShow("Finding a track...", "");

  const track = await previewFindTrack(value);

  // The listener changed their mind while this was in flight. Playing now
  // would contradict what is on screen.
  if (token !== previewToken) return;

  if (!track) {
    previewShow("Could not reach the music catalogue", "try again in a moment");
    return;
  }

  previewPlay(
    AUDIUS_HOST + "/v1/tracks/" + encodeURIComponent(track.id) + "/stream",
    null,                                   // genre previews play unprocessed
    track.title || "Untitled",
    (track.user && track.user.name) || "Audius"
  );
}


async function previewFindTrack(value) {
  for (const genre of (PREVIEW_GENRES[value] || [])) {
    const hits = await previewFetch("/v1/tracks/trending?genre="
      + encodeURIComponent(genre));
    if (hits.length) return previewPick(hits);
  }

  const keyword = PREVIEW_KEYWORDS[value];
  if (keyword) {
    const hits = await previewFetch("/v1/tracks/search?query="
      + encodeURIComponent(keyword));
    if (hits.length) return previewPick(hits);
  }

  return null;
}


async function previewFetch(path) {
  const url = AUDIUS_HOST + path
    + "&app_name=" + encodeURIComponent(AUDIUS_APP) + "&limit=10";

  try {
    const res = await fetch(url);
    if (!res.ok) return [];
    const data = await res.json();
    return (data.data || []).filter(t => t && t.id && t.is_streamable !== false);
  } catch (err) {
    console.error("Audius preview lookup failed:", err);
    return [];
  }
}


/**
 * A different track each time, so clicking an option twice is not a
 * pointless repeat and the demonstration does not become one song.
 */
function previewPick(hits) {
  return hits[Math.floor(Math.random() * hits.length)];
}


/* ────────────────────────────── playback ──────────────────────────────── */

function previewPlay(url, gains, title, subtitle) {
  songEnsureGraph();

  if (songCtx.state === "suspended") {
    // Called from a click, so the browser permits this. Not awaited: the
    // resume resolves quickly and making every caller async to wait for it
    // would gain nothing.
    songCtx.resume();
  }

  window.clearTimeout(previewTimer);

  songSetGains(gains);
  songEl.src = url;

  songEl.play().then(() => {
    previewShow(title, subtitle);
    // Stops itself. A preview that runs on while someone reads the next
    // question is noise, and nobody thinks to press stop.
    previewTimer = window.setTimeout(previewStop, PREVIEW_SECONDS * 1000);
  }).catch(err => {
    console.error("Preview playback failed:", err);
    previewShow("That preview would not play", "");
  });
}


function previewStop() {
  window.clearTimeout(previewTimer);
  previewToken++;                    // abandons anything still loading

  if (songEl) {
    songEl.pause();
  }
  previewHide();
}


/* ──────────────────────────────── panel ───────────────────────────────── */

function previewPanel() {
  let panel = document.getElementById("quiz-preview");
  if (panel) return panel;

  panel = document.createElement("div");
  panel.id = "quiz-preview";
  panel.className = "quiz-preview";
  panel.innerHTML = `
      <div class="qp-bars"><span></span><span></span><span></span><span></span></div>
      <div class="qp-text">
        <div class="qp-title" id="qp-title"></div>
        <div class="qp-sub" id="qp-sub"></div>
      </div>
      <button class="qp-stop" id="qp-stop" aria-label="Stop preview">✕</button>`;

  document.body.appendChild(panel);
  panel.querySelector("#qp-stop").addEventListener("click", previewStop);
  return panel;
}


function previewShow(title, subtitle) {
  const panel = previewPanel();
  // Text, not HTML: the title and artist come from whoever uploaded the
  // track, and this panel is built by string concatenation above.
  panel.querySelector("#qp-title").textContent = title || "";
  panel.querySelector("#qp-sub").textContent = subtitle || "";
  panel.classList.add("is-open");
}


function previewHide() {
  const panel = document.getElementById("quiz-preview");
  if (panel) panel.classList.remove("is-open");
}
