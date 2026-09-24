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

// Used only when the server does not send a path -- an old cached copy of
// this script talking to a new endpoint, or the reverse. The real path comes
// from the audio_samples table; this is the shape it had before that table
// existed, kept so a mismatch degrades to the original files rather than to
// silence.
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

/**
 * The URL to fetch for a pair.
 *
 * The server sends samplePath, read from the audio_samples table. The
 * identifier in pair.sample is no longer a filename — it names the slot,
 * not the file — so it is only used to rebuild the old path when no
 * samplePath arrives.
 */
function samplePathFor(pair) {
  if (pair && typeof pair.samplePath === 'string' && pair.samplePath) {
    return pair.samplePath;
  }
  if (pair && typeof pair.sample === 'string') {
    return SAMPLES_BASE_URL + pair.sample.replace(/\.wav$/i, '.mp3');
  }
  return null;
}

// Downloaded but not yet decoded tracks, keyed by path.
//
// Kept separate from bufferCache on purpose. Decoding needs an AudioContext,
// and a browser will not let one start until the user has interacted with
// the page — so anything that waits for a context cannot run during the
// pre-quiz. Downloading has no such restriction. Splitting the two lets the
// slow half, the network, happen early and leaves only the fast half for
// the moment Play is pressed.
const encodedCache = new Map();

// One in-flight request per track. Without this, warming a track and then
// pressing Play before it arrives would download the same file twice — a
// few hundred kilobytes wasted when these were clips, several megabytes now
// that they are whole songs.
const inFlight = new Map();

function fetchEncoded(path) {
  if (encodedCache.has(path)) {
    return Promise.resolve(encodedCache.get(path));
  }
  if (inFlight.has(path)) {
    return inFlight.get(path);
  }

  // The path is used whole rather than encoded: it arrives from the server
  // as a relative URL with its own slashes, and encoding it would turn
  // those into %2F and ask for a file whose name contains the folder.
  const request = fetch(path)
    .then(res => {
      if (!res.ok) {
        throw new Error(`Could not fetch ${path} (${res.status})`);
      }
      return res.arrayBuffer();
    })
    .then(encoded => {
      encodedCache.set(path, encoded);
      inFlight.delete(path);
      return encoded;
    })
    .catch(err => {
      inFlight.delete(path);
      throw err;
    });

  inFlight.set(path, request);
  return request;
}

// How many decoded songs to keep in memory at once.
//
// Two, because that is all the flow ever needs: the question on screen and
// the one being warmed for next. The cap exists because a decoded buffer is
// float32 PCM, not compressed audio -- a four-minute stereo track at 48 kHz
// occupies about 92 MB decoded, against roughly 4 MB as an MP3. Caching all
// ten would ask the browser for nearly a gigabyte and fail on a phone.
//
// encodedCache is left uncapped on purpose: it holds the compressed bytes,
// which are small, and keeping them means revisiting a song costs a decode
// rather than a download.
const MAX_DECODED_BUFFERS = 2;

/**
 * Drop the oldest decoded buffers until the cache is within its cap.
 *
 * Map preserves insertion order, so the first key is the least recently
 * added. Deleting it is enough — the browser reclaims the memory once
 * nothing references the buffer, and a stopped source holds no reference.
 */
function trimBufferCache(keep) {
  for (const name of bufferCache.keys()) {
    if (bufferCache.size <= MAX_DECODED_BUFFERS) {
      return;
    }
    if (name !== keep) {
      bufferCache.delete(name);
    }
  }
}

async function loadSample(path) {
  if (bufferCache.has(path)) {
    return bufferCache.get(path);
  }

  const encoded = await fetchEncoded(path);
  const ctx = await getAudioContext();

  // decodeAudioData detaches the ArrayBuffer it is given, which would empty
  // the cached copy and make a second decode fail. Hand it a copy.
  const buffer = await ctx.decodeAudioData(encoded.slice(0));

  bufferCache.set(path, buffer);
  trimBufferCache(path);
  return buffer;
}

/**
 * Start downloading and decoding a sample without waiting for it.
 *
 * A full track is a few megabytes, and on shared hosting the round trip is
 * noticeable. Fetching only when Play is pressed put that wait directly in
 * front of the listener, every time a new song came up — which is the
 * delay testers reported on the first play.
 *
 * loadSample() already caches by name and returns the cached buffer on a
 * second call, so warming is just calling it early and throwing away the
 * promise. If it fails there is nothing to do here: the real play will try
 * again and report properly. The empty catch is to stop an unhandled
 * rejection appearing in the console for a request nobody asked for.
 */
function warmSample(path) {
  if (!path || !browserAudioSupported()) return;
  // Download only. Decoding is left for playback, because it needs an
  // AudioContext and the browser will not start one until the user has
  // interacted with the page — so a warm during the pre-quiz would
  // otherwise do nothing at all.
  fetchEncoded(path).catch(() => {});
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
let livePath = null;       // the track in the transport, for loudness lookup
let makeupNode = null;     // the loudness correction, last in the chain
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

  // The loudness correction moves with the EQ, on the same ramp. Changing
  // the filters without it would let the level jump on every switch, which
  // is the bias this whole mechanism exists to remove.
  //
  // Read from the cache rather than awaited: prepareLoudness() measured
  // both versions when the track loaded, so the value is already here. If
  // it somehow is not, the correction stays where it is rather than
  // stalling the switch.
  if (makeupNode) {
    const cached = loudnessCache.get(loudnessKey(livePath, gains));
    if (typeof cached === 'number') {
      makeupNode.gain.linearRampToValueAtTime(cached, at);
    }
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

/* ──────────────────────────── loudness matching ─────────────────────────
 *
 * Why this exists.
 *
 * Boosting a band does not only change the tone, it adds energy — a +6 dB
 * bass shelf makes that version measurably louder. Listeners reliably
 * prefer the louder of two otherwise similar sounds, and they do not know
 * that is what they are doing. Left uncorrected, part of every answer in
 * this test would be "B was louder" rather than "B sounded better", and the
 * profile would drift towards whichever direction happens to add energy.
 *
 * So each version is measured and given a compensating gain. What the
 * listener compares is then the shape of the sound alone.
 *
 * The same correction handles the other loudness problem for free: songs
 * are mastered at different levels, and without this one track would arrive
 * noticeably louder than the last and send people reaching for the volume
 * control mid-test.
 */

// Roughly -20 dBFS. Quiet enough that a boosted version has headroom left
// before clipping, loud enough to be comfortable at a normal system volume.
const TARGET_RMS = 0.1;

// Measuring the whole song would be wasteful and, on a track with a quiet
// intro or a long outro, misleading. A window from the middle is where the
// music is densest and is what the listener will actually be judging.
const LOUDNESS_WINDOW_SECONDS = 12;

// Never trust a measurement enough to multiply by an extreme number. A
// near-silent window would otherwise ask for a gain of eighty and blast
// whatever follows it.
const MIN_MAKEUP_GAIN = 0.05;
const MAX_MAKEUP_GAIN = 4;

// Leaves a sliver of headroom below full scale, so a corrected peak lands
// just under rather than exactly at the clipping point.
const PEAK_CEILING = 0.99;

// Keyed by path plus the gains applied, because the answer depends on both.
const loudnessCache = new Map();

function loudnessKey(path, gains) {
  return path + '|' + EQ_FILTERS
    .map(spec => Number(gains?.[spec.gainKey] ?? 0).toFixed(2))
    .join(',');
}

/**
 * The slice of the track that gets measured.
 *
 * Centred, because the middle of a song is representative in a way the
 * first twelve seconds often are not. A track shorter than the window is
 * measured whole.
 */
function loudnessWindow(buffer) {
  const windowLength = Math.min(
    buffer.length,
    Math.floor(LOUDNESS_WINDOW_SECONDS * buffer.sampleRate)
  );
  const start = Math.floor((buffer.length - windowLength) / 2);
  return { start, length: windowLength };
}

/**
 * Render that window through the given EQ and report how loud it came out.
 *
 * Rendered offline rather than estimated from the filter settings. The
 * energy a shelf adds depends on how much of the signal sits in that band,
 * which is a property of the music and not of the filter — a bass boost on
 * a sparse acoustic track adds far less than the same boost on a kick-heavy
 * one. Actually rendering it is the only way to know.
 *
 * Offline rendering runs far faster than real time, so a twelve-second
 * window costs a fraction of a second even on a phone.
 */
async function measureLoudness(buffer, gains) {
  const { start, length } = loudnessWindow(buffer);
  const channels = buffer.numberOfChannels;

  const offline = new OfflineAudioContext(channels, length, buffer.sampleRate);

  const windowBuffer = offline.createBuffer(channels, length, buffer.sampleRate);
  for (let ch = 0; ch < channels; ch++) {
    windowBuffer.copyToChannel(
      buffer.getChannelData(ch).subarray(start, start + length), ch);
  }

  const source = offline.createBufferSource();
  source.buffer = windowBuffer;

  // The same chain as playback, in the same order. If these ever diverge
  // the correction would be measuring something the listener never hears.
  let node = source;
  for (const spec of EQ_FILTERS) {
    const filter = offline.createBiquadFilter();
    filter.type = spec.type;
    filter.frequency.value = spec.frequency;
    filter.Q.value = spec.Q;
    filter.gain.value = Number(gains?.[spec.gainKey] ?? 0);
    node.connect(filter);
    node = filter;
  }
  node.connect(offline.destination);
  source.start();

  const rendered = await offline.startRendering();

  let sumSquares = 0;
  let peak = 0;
  let samples = 0;
  for (let ch = 0; ch < rendered.numberOfChannels; ch++) {
    const data = rendered.getChannelData(ch);
    for (let i = 0; i < data.length; i++) {
      const v = data[i];
      sumSquares += v * v;
      const a = Math.abs(v);
      if (a > peak) peak = a;
    }
    samples += data.length;
  }

  return {
    rms: samples ? Math.sqrt(sumSquares / samples) : 0,
    peak,
  };
}

/**
 * The gain to apply so this version lands at the target loudness.
 *
 * Two limits, and the tighter one wins. The first keeps the correction
 * sane. The second keeps it from clipping: bringing a quiet-but-peaky
 * recording up to the target RMS can push its peaks past full scale, which
 * would distort — and distortion is not a tonal difference, it is a defect,
 * and the listener would hear it as one.
 */
function makeupGainFrom(measurement) {
  if (!measurement || measurement.rms <= 0) {
    return 1;
  }

  let gain = TARGET_RMS / measurement.rms;
  gain = Math.min(MAX_MAKEUP_GAIN, Math.max(MIN_MAKEUP_GAIN, gain));

  if (measurement.peak > 0) {
    gain = Math.min(gain, PEAK_CEILING / measurement.peak);
  }
  return gain;
}

async function makeupGainFor(path, buffer, gains) {
  const key = loudnessKey(path, gains);
  if (loudnessCache.has(key)) {
    return loudnessCache.get(key);
  }

  let gain = 1;
  try {
    gain = makeupGainFrom(await measureLoudness(buffer, gains));
  } catch (err) {
    // A failed measurement means no correction, not no audio. Unmatched
    // levels make the test less rigorous; a thrown error makes it unusable.
    console.warn('Loudness measurement failed; playing uncorrected.', err);
  }

  loudnessCache.set(key, gain);
  return gain;
}

/**
 * Measure both versions of the pair now, so switching later is instant.
 *
 * Done at load rather than on the first switch, because a gain that arrives
 * a moment after the switch would be heard as the level moving — which is
 * the exact artefact this is here to remove.
 */
async function prepareLoudness(path, pair) {
  if (!pair) return;
  await Promise.all([
    makeupGainFor(path, playBuffer, pair.A),
    makeupGainFor(path, playBuffer, pair.B),
  ]);
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

  // Last in the chain, after the EQ, so it corrects what the EQ produced.
  // Its starting value is whatever was measured for this side; 1 only if
  // the measurement is somehow missing, which means uncorrected playback
  // rather than silence.
  makeupNode = ctx.createGain();
  const measured = loudnessCache.get(
    loudnessKey(livePath, livePair?.[liveSide]));
  makeupNode.gain.value = typeof measured === 'number' ? measured : 1;

  node.connect(makeupNode);
  makeupNode.connect(ctx.destination);

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

async function loadIntoTransport(path, pair, side) {
  const ctx = await getAudioContext();
  playBuffer = await loadSample(path);
  livePair = pair;
  livePath = path;
  liveSide = side;
  positionAtStart = 0;
  startedAt = ctx.currentTime;
  isPlaying = false;

  // Both versions are measured before anything plays. Waiting costs a
  // fraction of a second once, and buys a switch that changes the tone
  // without changing the level.
  await prepareLoudness(path, pair);

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
  makeupNode = null;
  livePath = null;
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

  const peaks = peaksFor(samplePathFor(currentPair), playBuffer);
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

  drawMarkers(g, w, h, dpr, played, ink);

  const timeEl = document.getElementById('track-time');
  if (timeEl) {
    timeEl.textContent = formatTime(pos) + ' / ' + formatTime(playBuffer.duration);
  }
}

/* ────────────────────────── section markers ───────────────────────────── */

// The flag sits at the top of the waveform, out of the way of the second
// ticks along the bottom.
const MARKER_FLAG_HEIGHT = 17;
const MARKER_PADDING = 5;

/**
 * Where each marker sits, in canvas pixels.
 *
 * Shared by the drawing and the hit testing, so a flag is always clickable
 * exactly where it appears. Computing it twice is how those two drift apart
 * and a marker ends up responding a few pixels to the left of itself.
 */
function markerBoxes(canvasWidth, dpr, measure) {
  if (!currentPair || !playBuffer || !playBuffer.duration) return [];

  const markers = Array.isArray(currentPair.markers) ? currentPair.markers : [];

  return markers.map(marker => {
    const start = Math.max(0, Math.min(playBuffer.duration, Number(marker.start) || 0));
    const x = (start / playBuffer.duration) * canvasWidth;
    const textWidth = measure(marker.label || '');
    const boxWidth = textWidth + MARKER_PADDING * 2 * dpr;

    // Nudged back inside at the right-hand edge, so a marker near the end
    // of the song does not have half its label cut off by the canvas.
    const left = Math.min(x, canvasWidth - boxWidth);

    return {
      label: marker.label || '',
      start,
      x,
      left: Math.max(0, left),
      width: boxWidth,
      height: MARKER_FLAG_HEIGHT * dpr,
    };
  });
}

function drawMarkers(g, w, h, dpr, accent, ink) {
  g.font = (10 * dpr) + 'px Arial';
  const boxes = markerBoxes(w, dpr, text => g.measureText(text).width);
  if (!boxes.length) return;

  g.textBaseline = 'middle';

  for (const box of boxes) {
    // A full-height line first: the flag says what this is, the line says
    // precisely where. Without it a label two pixels wide at the edge of
    // its box looks like it marks the wrong moment.
    g.globalAlpha = 0.45;
    g.fillStyle = accent;
    g.fillRect(box.x - dpr / 2, 0, dpr, h);
    g.globalAlpha = 1;

    g.fillStyle = accent;
    g.fillRect(box.left, 0, box.width, box.height);

    g.fillStyle = '#161a23';
    g.fillText(box.label, box.left + MARKER_PADDING * dpr, box.height / 2);
  }
}

/**
 * The marker under a click, if any.
 *
 * Checked before the seek, because a flag and the waveform beneath it
 * occupy the same pixels and the flag should win — clicking the word
 * "Chorus" should go to the chorus, not to whatever point on the timeline
 * the word happens to be drawn over.
 */
function markerAt(canvas, clientX, clientY) {
  const rect = canvas.getBoundingClientRect();
  const dpr = window.devicePixelRatio || 1;

  const x = (clientX - rect.left) * (canvas.width / rect.width);
  const y = (clientY - rect.top) * (canvas.height / rect.height);

  const g = canvas.getContext('2d');
  g.font = (10 * dpr) + 'px Arial';

  for (const box of markerBoxes(canvas.width, dpr, t => g.measureText(t).width)) {
    if (y <= box.height && x >= box.left && x <= box.left + box.width) {
      return box;
    }
  }
  return null;
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
    if (!playBuffer) return;

    // A marker takes the click if the press landed on one. Note it does
    // NOT capture the pointer: capturing would turn a tap on a flag into
    // the start of a drag, and dragging away from it would scrub the
    // waveform — which is the opposite of what tapping a label means.
    const marker = markerAt(canvas, event.clientX, event.clientY);
    if (marker) {
      transportSeek(marker.start);
      setStatus('Jumped to ' + marker.label + '.');
      return;
    }

    canvas.setPointerCapture(event.pointerId);
    seekFromEvent(event);
  });
  canvas.addEventListener('pointermove', event => {
    if (event.buttons === 1) {
      seekFromEvent(event);
      return;
    }
    // A pointer cursor over a flag, the default arrow elsewhere, so the
    // markers look clickable before anyone tries.
    canvas.style.cursor =
      markerAt(canvas, event.clientX, event.clientY) ? 'pointer' : '';
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

      // Play what the chosen answer sounds like, where that means
      // anything. Guarded because js/quizPreview.js is optional -- the
      // questionnaire works perfectly well in silence, and a missing file
      // should not take the test down with it.
      if (typeof quizPreview !== 'function') return;

      const picked = group.querySelector('input:checked');
      if (picked) {
        quizPreview(group.dataset.questionId, picked.value);
      }
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

    // The first track downloads while the questions are being answered.
    // This is the delay testers actually hit: answering the pre-quiz takes
    // a while, the listening test then opens, and the first Play sat
    // waiting on a fetch that could already have happened.
    //
    // The path is sent with the questions rather than worked out here. The
    // filename lives in the audio_samples table now, so there is no formula
    // left in the browser to derive it from.
    warmSample(data.firstSamplePath);
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

  // Leaving the questionnaire with a preview still playing would put it
  // underneath the listening test, which is the one place on this site
  // where stray audio actually corrupts the result.
  if (typeof previewStop === 'function') previewStop();

  document.getElementById('quiz-screen').style.display = 'none';
  await beginTest(answers);
}

/* ───────────────────────────── apparatus ──────────────────────────────── */

// What the listener said they were wearing, sent with the first request of
// a test and stored alongside the result.
//
// Always null now. The screen that asked was removed on request, and this
// is deliberately left in place rather than torn out: startTest() sends it,
// api/test-start.php remembers it, and auditory_profiles.apparatus stores
// it, so the whole path still works and simply records "not stated". Ripping
// it out would mean editing four files and a table to achieve exactly the
// same stored value.
//
// To bring the question back, restore the markup in test.html and set this
// from it. Nothing else has to change.
let selectedApparatus = null;

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

  // Fetch this question's track as soon as the question appears rather than
  // when Play is pressed, and start the next one too. Reading the question
  // takes a few seconds; the download can happen during them instead of
  // afterwards.
  //
  // nextSamplePath comes from the server. The browser used to work it out by
  // adding one to the question number and building a filename; with the
  // filenames in the database there is nothing to add one to.
  warmSample(samplePathFor(pair));
  warmSample(pair.nextSamplePath);

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

  const path = samplePathFor(currentPair);
  if (!browserAudioSupported() || !currentPair || !path) {
    reportPlaybackFailure();
    return;
  }

  try {
    // "Loading" only when the track still has to come off the network.
    // Once downloaded, decoding takes a moment at most, and calling that
    // loading made a warmed track look slower than it was.
    const ready = bufferCache.has(path) || encodedCache.has(path);
    if (!ready) setStatus('Loading audio...');

    if (!playBuffer) {
      await loadIntoTransport(path, currentPair, liveSide || 'A');
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

  // Reveals the song search and gives it the gains to apply. Guarded
  // because js/songSearch.js is optional — if it failed to load, or the
  // page does not include it, the results screen is unaffected.
  if (typeof songSetProfile === 'function') {
    songSetProfile(profile);
  }

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
