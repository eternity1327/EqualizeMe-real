/**
 * Hear your profile on a song you choose.
 *
 * Shown after the ten questions are answered, never during them. The test
 * itself keeps its curated tracks because a measuring instrument needs
 * controlled stimuli -- one listener's profile is only comparable to
 * another's if both were measured on the same material. Once the profile
 * exists that constraint is gone, and playing it against music the listener
 * actually likes is the clearest demonstration of what it means.
 *
 * WHY A MEDIA ELEMENT AND NOT A DECODED BUFFER
 * The rest of the test decodes each track into an AudioBuffer, which is
 * what makes the waveform, the click-to-seek and the loudness matching
 * possible. That path needs fetch(), and fetch() is governed by
 * connect-src. Audius is decentralised: a stream request redirects to
 * whichever content node holds the file, on domains run by independent
 * operators, so there is no fixed hostname to allow. An <audio> element is
 * governed by media-src instead, which we can open safely because it only
 * says where audio may be loaded from and grants nothing the other way.
 *
 * The cost of that choice is real and worth stating: no waveform and no
 * loudness matching here. This is a listening demonstration, not a
 * measurement, so neither is load-bearing.
 *
 * Self-contained on purpose. If Audius is unreachable, or this file fails
 * to load at all, the listening test and the results are unaffected.
 */

const AUDIUS_HOST = "https://discoveryprovider.audius.co";
const AUDIUS_APP = "EqualizeME";

// Discovery nodes are independent and occasionally return an empty list for
// a query another node answers. Worth one retry before telling the user
// there is nothing there.
const SEARCH_RETRIES = 2;

const SEARCH_LIMIT = 8;

// crossOrigin="anonymous" is not optional. Without it the Web Audio graph
// treats the stream as tainted and outputs silence -- audio that plays but
// cannot be processed, which looks like a bug in the EQ rather than a
// permissions problem.
const CROSS_ORIGIN = "anonymous";

let songCtx = null;
let songEl = null;
let songFilters = [];
let songProfile = null;      // the listener's learned gains
let songEqOn = true;         // profile applied, or flat

// One audio graph, two users: the results-screen player above, and the
// pre-quiz previews below. Sharing it rather than building a second means
// one AudioContext, one media element, and no chance of both playing at
// once -- starting either stops the other, which is what a listener would
// expect anyway.
let songGains = null;        // whatever the filters should currently be set to


/**
 * The profile, in the shape the filters expect.
 *
 * showDoneScreen() hands over whatever the server returned, which is keyed
 * bassGain / trebleGain / presenceGain -- the same keys EQ_BANDS uses.
 */
function songSetProfile(profile) {
  songProfile = profile || null;

  const panel = document.getElementById("song-search");
  if (panel) panel.hidden = !songProfile;
}


function songAudioContext() {
  if (!songCtx) {
    const Ctor = window.AudioContext || window.webkitAudioContext;
    songCtx = new Ctor();
  }
  return songCtx;
}


/**
 * Build the element and the filter chain once, then reuse them.
 *
 * createMediaElementSource can only be called once per element, so the
 * element has to outlive any single track -- changing songs means changing
 * .src, not building a new graph.
 */
function songEnsureGraph() {
  if (songEl) return;

  const ctx = songAudioContext();

  songEl = new Audio();
  songEl.crossOrigin = CROSS_ORIGIN;
  songEl.preload = "none";

  let node = ctx.createMediaElementSource(songEl);

  songFilters = [];
  for (const spec of EQ_BANDS) {
    const filter = ctx.createBiquadFilter();
    filter.type = spec.type;
    filter.frequency.value = spec.frequency;
    filter.Q.value = spec.Q;
    filter.gain.value = 0;
    filter._gainKey = spec.gainKey;
    node.connect(filter);
    node = filter;
    songFilters.push(filter);
  }

  node.connect(ctx.destination);

  songEl.addEventListener("error", () => {
    songStatus("That track would not load. Try another one.", "bad");
  });

  songEl.addEventListener("ended", () => songUpdateButtons());
  songEl.addEventListener("play", () => songUpdateButtons());
  songEl.addEventListener("pause", () => songUpdateButtons());

  songApplyEq();
}


/**
 * Set the filters to a given set of gains, or flatten them.
 *
 * Ramped rather than assigned, for the same reason the listening test ramps
 * them: a bare assignment steps the coefficient and clicks audibly, and the
 * click would be the most noticeable thing about the comparison.
 */
function songSetGains(gains) {
  songGains = gains || null;

  if (!songCtx || !songFilters.length) return;

  const at = songCtx.currentTime + 0.02;
  for (const filter of songFilters) {
    const target = songGains ? Number(songGains[filter._gainKey] ?? 0) : 0;
    filter.gain.linearRampToValueAtTime(target, at);
  }
}


/** The results-screen player's own rule: profile applied, or flat. */
function songApplyEq() {
  songSetGains(songEqOn ? songProfile : null);
}


function songStatus(text, kind = "") {
  const el = document.getElementById("song-status");
  if (!el) return;
  el.textContent = text || "";
  el.className = "song-status" + (kind ? " " + kind : "");
}


async function songSearch(event) {
  if (event) event.preventDefault();

  const input = document.getElementById("song-query");
  const query = (input ? input.value : "").trim();

  if (!query) {
    songStatus("Type something to search for.");
    return;
  }

  songStatus("Searching...");

  let hits = [];
  for (let attempt = 0; attempt <= SEARCH_RETRIES && !hits.length; attempt++) {
    hits = await songFetchResults(query);
  }

  if (!hits.length) {
    songStatus("Nothing found. Audius is a catalogue of independent music, "
      + "so a chart song may genuinely not be there — try a genre or a mood.");
    songRenderResults([]);
    return;
  }

  songStatus("");
  songRenderResults(hits);
}


async function songFetchResults(query) {
  const url = AUDIUS_HOST + "/v1/tracks/search"
    + "?query=" + encodeURIComponent(query)
    + "&app_name=" + encodeURIComponent(AUDIUS_APP)
    + "&limit=" + SEARCH_LIMIT;

  try {
    const res = await fetch(url);
    if (!res.ok) return [];

    const data = await res.json();

    // is_streamable is false for tracks that exist in the catalogue but
    // cannot be played -- gated, deleted, or still uploading. Offering one
    // would produce a result that silently does nothing when clicked.
    return (data.data || []).filter(t => t && t.id && t.is_streamable !== false);
  } catch (err) {
    console.error("Audius search failed:", err);
    return [];
  }
}


function songRenderResults(tracks) {
  const list = document.getElementById("song-results");
  if (!list) return;

  if (!tracks.length) {
    list.innerHTML = "";
    return;
  }

  // Everything here is written by strangers who upload to Audius, so every
  // field is escaped. escapeHtml() lives in script.js, which is loaded
  // before this file on every page that uses it.
  list.innerHTML = tracks.map(track => `
      <button class="song-result" data-id="${escapeHtml(track.id)}">
        <span class="song-title">${escapeHtml(track.title || "Untitled")}</span>
        <span class="song-artist">${escapeHtml(
          (track.user && track.user.name) || "Unknown artist")}</span>
        <span class="song-length">${songDuration(track.duration)}</span>
      </button>`).join("");

  list.querySelectorAll(".song-result").forEach(button => {
    button.addEventListener("click", () => songPlay(button.dataset.id,
      button.querySelector(".song-title").textContent,
      button.querySelector(".song-artist").textContent));
  });
}


function songDuration(seconds) {
  const s = Math.max(0, Math.floor(Number(seconds) || 0));
  return Math.floor(s / 60) + ":" + String(s % 60).padStart(2, "0");
}


async function songPlay(id, title, artist) {
  if (!id) return;

  songEnsureGraph();

  // A browser will not start an AudioContext until the user has interacted
  // with the page. This runs from a click, so resuming here is allowed --
  // but it is still worth awaiting, because a suspended context produces
  // silence with no error.
  if (songCtx.state === "suspended") {
    await songCtx.resume();
  }

  songEl.src = AUDIUS_HOST + "/v1/tracks/" + encodeURIComponent(id) + "/stream";
  songApplyEq();

  const nowPlaying = document.getElementById("song-now-playing");
  if (nowPlaying) nowPlaying.textContent = title + " — " + artist;

  try {
    await songEl.play();
    songStatus("");
  } catch (err) {
    console.error("Audius playback failed:", err);
    songStatus("That track would not play. Try another one.", "bad");
  }

  songUpdateButtons();
}


function songToggleEq() {
  songEqOn = !songEqOn;
  songApplyEq();
  songUpdateButtons();
}


function songTogglePlay() {
  if (!songEl || !songEl.src) return;
  if (songEl.paused) {
    songEl.play().catch(err => console.error(err));
  } else {
    songEl.pause();
  }
}


function songUpdateButtons() {
  const eqBtn = document.getElementById("song-eq-toggle");
  if (eqBtn) {
    eqBtn.textContent = songEqOn ? "Your profile" : "Flat (no EQ)";
    eqBtn.classList.toggle("is-on", songEqOn);
  }

  const playBtn = document.getElementById("song-play-toggle");
  if (playBtn) {
    const playing = songEl && !songEl.paused && songEl.src;
    playBtn.textContent = playing ? "❚❚ Pause" : "▶ Play";
    playBtn.disabled = !(songEl && songEl.src);
  }
}


/**
 * Stop and forget, so leaving the results screen does not leave music on.
 */
function songStop() {
  if (songEl) {
    songEl.pause();
    songEl.removeAttribute("src");
    songEl.load();
  }
  songUpdateButtons();
}
