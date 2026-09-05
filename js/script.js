(function () {
  const html = document.documentElement;

  if (localStorage.getItem("theme") === "dark") {
    html.setAttribute("data-theme", "dark");
  }

  document.addEventListener("DOMContentLoaded", () => {
    const btn = document.getElementById("themeToggle");
    if (btn) {
      updateIcon();
      btn.addEventListener("click", () => {
        const isDark = html.getAttribute("data-theme") === "dark";
        if (isDark) {
          html.removeAttribute("data-theme");
          localStorage.setItem("theme", "light");
        } else {
          html.setAttribute("data-theme", "dark");
          localStorage.setItem("theme", "dark");
        }
        updateIcon();
      });
    }

    function updateIcon() {
      if (btn) btn.textContent = html.getAttribute("data-theme") === "dark" ? "☀️" : "🌙";
    }
  });
})();

document.addEventListener("DOMContentLoaded", () => {
  const avatarBtn = document.getElementById("profileAvatarBtn");
  const dropdown = document.getElementById("profileDropdown");
  if (!avatarBtn || !dropdown) return;

  avatarBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    const isOpen = dropdown.classList.toggle("open");
    avatarBtn.setAttribute("aria-expanded", isOpen);
  });

  document.addEventListener("click", (e) => {
    if (!dropdown.contains(e.target) && !avatarBtn.contains(e.target)) {
      dropdown.classList.remove("open");
      avatarBtn.setAttribute("aria-expanded", "false");
    }
  });
});

// The listening test and the recommendation engine used to live in a
// Flask service that PHP proxied to. They are PHP now, so these are
// ordinary endpoints and there is no proxy left to name.
//
// One consequence worth recording: the old service took whatever user id
// it was handed, and a proxy existed purely to overwrite that with the
// session's. These endpoints read the session directly, so there is no id
// in the request for anyone to tamper with — the bug that proxy guarded
// against is now unreachable rather than merely guarded.
const API = {
  quizQuestions: "api/quiz.php",
  testStart: "api/test-start.php",
  testAnswer: "api/test-answer.php",
  recommendations: "api/recommendations.php",
  iemCurve: "api/iem-curve.php",
};

// Send, and if the token was stale, get a fresh one and send once more.
//
// A 403 here means the CSRF token no longer matches the session — almost
// always because the session rotated its id, which happens every 30
// minutes. A listening test takes several minutes and a settings page can
// sit open far longer, so without this a rotation part-way through would
// break the next request for no reason the user could see.
//
// Retried exactly once. If a second, freshly-minted token is also refused
// then the problem is not staleness, and retrying again would only turn a
// clear failure into a hang.
//
// `send` receives the token and returns the fetch. Everything else about
// the request — method, headers, body, whether the token rides in a header
// or in the body — belongs to the caller, which is why this knows nothing
// about any of it.
async function sendWithCsrfRetry(send) {
  const res = await send(await getCsrfToken());
  if (res.status !== 403) {
    return res;
  }
  invalidateCsrfToken();
  return send(await getCsrfToken());
}


function apiPost(url, payload = {}) {
  return sendWithCsrfRetry(token => fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ...payload, csrf_token: token || "" }),
  }));
}

let _csrfToken = null;

async function getCsrfToken() {
  if (_csrfToken) return _csrfToken;

  try {
    const res = await fetch("api/csrf-token.php");
    if (!res.ok) return null;
    const data = await res.json();
    _csrfToken = data.token;
    return _csrfToken;
  } catch (err) {
    return null;
  }
}

function invalidateCsrfToken() {
  _csrfToken = null;
}

const USD_TO_PHP = 61.2;
const USD_TO_PHP_ASOF = "Aug 2026";

function formatPrice(price) {
  if (price === null || price === undefined) return "N/A";

  const usd = Number(price);
  const php = usd * USD_TO_PHP;

  const phpText = "₱" + php.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  });
  const usdText = "$" + usd.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  return `${phpText} <span class="price-usd" title="Converted at ${USD_TO_PHP} PHP/USD, ${USD_TO_PHP_ASOF}. Local retail is usually higher.">(${usdText})</span>`;
}

function buildBandMatch(bandMatch) {
  if (!bandMatch) return "";

  const labels = {
    bass_gain: "Bass",
    presence_gain: "Presence",
    treble_gain: "Treble",
  };

  const rows = Object.keys(labels).map(band => {
    // Coerced to a number and clamped rather than interpolated as-is. This
    // value lands inside a style attribute, which is the one place where a
    // string that merely looks numeric can still do damage.
    const pct = Number(bandMatch[band]);
    if (!Number.isFinite(pct)) return "";

    const width = Math.max(0, Math.min(100, pct));
    return `
      <div class="band-row">
        <span class="band-name">${labels[band]}</span>
        <span class="band-bar"><span class="band-fill" style="width:${width}%"></span></span>
        <span class="band-pct">${Math.round(width)}%</span>
      </div>`;
  }).join("");

  return `<div class="band-match">${rows}</div>`;
}

// Only http and https survive. Everything here — image sources, retailer
// links — arrives from the IEM catalogue, which is imported from squig.link
// rather than written by us. A "javascript:..." address in one of those
// fields would otherwise become a working script the moment someone clicked
// a Buy link.
function safeUrl(value, fallback = "") {
  const raw = String(value ?? "").trim();
  if (!raw) return fallback;

  try {
    // Parsed with no base on purpose, so only absolute URLs get through.
    //
    // An earlier version resolved against window.location, which meant a
    // mangled value like `x" onerror="...` came back as a real same-origin
    // URL with the quotes percent-encoded. Inert, but wrong: the browser
    // then fired a 404 at our own server instead of the placeholder being
    // used. Both fields this guards — a retailer's product page and a
    // retailer's image — are external and absolute anyway, so anything
    // relative is malformed by definition.
    const url = new URL(raw);
    return (url.protocol === "http:" || url.protocol === "https:")
      ? url.href
      : fallback;
  } catch (err) {
    return fallback;
  }
}

function buildBuyLinks(item) {
  const links = [];

  const productUrl = safeUrl(item.product_url);
  if (productUrl) {
    const label = item.retailer_name
      ? `Buy at ${escapeHtml(item.retailer_name)}`
      : "Buy from shop";
    links.push(
      `<a href="${escapeHtml(productUrl)}" target="_blank" rel="noopener noreferrer">${label}</a>`
    );
  }

  const query = encodeURIComponent(`${item.brand ?? ""} ${item.name ?? ""}`.trim());
  links.push(
    `<a href="https://shopee.ph/search?keyword=${query}" target="_blank" rel="noopener noreferrer">Search on Shopee</a>`
  );

  return `<div class="iem-links">${links.join("")}</div>`;
}

const IEM_PLACEHOLDER = "images/iem-placeholder.svg";

function buildIemCard(item) {
  // Every value below comes out of the catalogue table. None of it is
  // written by us, so none of it is trusted: text is escaped and URLs are
  // filtered by scheme first. Without that, a model name containing
  // `"><script>` — or an image_url of `x" onerror="...` — would run.
  const name = escapeHtml(`${item.brand ?? ""} ${item.name ?? ""}`.trim());
  const image = escapeHtml(safeUrl(item.image_url, IEM_PLACEHOLDER));

  return `
      <div class="iem-card" data-iem-id="${escapeHtml(item.iem_id)}">
        <img class="iem-card-img" src="${image}"
          alt="${name}"
          onerror="this.onerror=null; this.src='${IEM_PLACEHOLDER}';">
        <h3>${name}</h3>
        <p>${escapeHtml(item.sound_signature ?? "")}</p>
        <p class="iem-match">Match: ${escapeHtml(item.match_score)}%</p>
        <p class="price">${formatPrice(item.price)}</p>
        ${buildBandMatch(item.band_match)}
        <div class="iem-curve-wrap" hidden>
          <canvas class="iem-curve-chart"></canvas>
          <p class="iem-description"></p>
        </div>
        ${buildBuyLinks(item)}
      </div>
    `;
}

function renderCurves(grid, data) {
  data.recommendations.forEach(item => {
    const card = grid.querySelector(`.iem-card[data-iem-id="${item.iem_id}"]`);
    if (card) renderIemCurve(item.iem_id, card, data.profile);
  });
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, ch => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  }[ch]));
}

// The results page hides every block until there is something to put in it,
// so a failure part-way through leaves empty headings rather than headings
// with "undefined" underneath.
function showBlock(id) {
  const el = document.getElementById(id);
  if (el) el.hidden = false;
}

function failResults(message) {
  const grid = document.getElementById("iem-grid");
  if (grid) grid.innerHTML = `<p>${escapeHtml(message)}</p>`;
  showBlock("iem-block");
}

async function loadRecommendations() {
  const grid = document.getElementById("iem-grid");
  if (!grid) return;

  try {
    // No user id in the URL — the endpoint takes it from the session, so
    // there is nothing here for a caller to tamper with.
    const res = await fetch(API.recommendations);

    if (res.status === 401) {
      failResults("Please log in first.");
      return;
    }

    const data = await res.json();

    if (data.error) {
      const p = document.createElement("p");
      p.textContent = `${data.error}. Take the `;
      p.insertAdjacentHTML("beforeend",
        '<a href="test.html">listening test</a> first.');
      grid.replaceChildren(p);
      showBlock("iem-block");
      return;
    }

    // Sections 1-3 come from the preference target. They are rendered even
    // if the catalogue turns out to be empty, because they are about the
    // listener rather than about the IEMs.
    renderPreferenceProfile(data.preference);
    renderPreferenceTarget(data.preference);
    renderAudioAnalysis(data.preference);

    if (!data.recommendations || data.recommendations.length === 0) {
      grid.innerHTML = "<p>No matching IEMs found yet.</p>";
    } else {
      grid.innerHTML = data.recommendations.map(buildIemCard).join("");
      renderCurves(grid, data);
    }
    showBlock("iem-block");

    // Section 5. Rendered last and reads nothing but the finished profile —
    // it cannot affect the target or the ranking above it.
    renderHearingPreservation(data.hearing_preservation);
  } catch (err) {
    failResults("Could not load recommendations.");
    console.error(err);
  }
}

/* ─────────────────────────── 1. Preference Profile ─────────────────── */

// Which way a band leans, for colour only. The words come from the server.
const LEVEL_TONE = {
  strong_up: "up-strong", up: "up", slight_up: "up-slight",
  neutral: "neutral",
  slight_down: "down-slight", down: "down", strong_down: "down-strong",
};

function signed(db) {
  const n = Number(db) || 0;
  return `${n > 0 ? "+" : ""}${n.toFixed(1)} dB`;
}

function renderPreferenceProfile(preference) {
  const wrap = document.getElementById("preference-bands");
  if (!wrap || !preference) return;

  const summary = document.getElementById("preference-summary");
  if (summary) summary.textContent = preference.analysis?.summary || "";

  wrap.innerHTML = preference.regions.map(region => `
    <div class="band-card tone-${LEVEL_TONE[region.level] || "neutral"}">
      <div class="band-card-name">${escapeHtml(region.label)}</div>
      <div class="band-card-level">${escapeHtml(region.level_label)}</div>
      <div class="band-card-value">${signed(region.value)}</div>
      <div class="band-card-range">${escapeHtml(region.range)}</div>
    </div>
  `).join("");

  showBlock("preference-block");
}

/* ─────────────────────────── 2. Preference Target ──────────────────── */

const TARGET_MIN_HZ = 20;
const TARGET_MAX_HZ = 20000;
const TARGET_POINTS = 160;

// Log-spaced sweep, so the low end gets as much of the chart as the top end
// does. A linear sweep would spend half its points above 10 kHz.
function targetFrequencies() {
  const lo = Math.log10(TARGET_MIN_HZ);
  const hi = Math.log10(TARGET_MAX_HZ);
  const step = (hi - lo) / (TARGET_POINTS - 1);
  return Array.from({ length: TARGET_POINTS },
    (_, i) => Math.round(10 ** (lo + i * step)));
}

// Chart.js refuses to draw onto a canvas that already holds a chart, and
// throws rather than replacing it. Keeping the handle means a second render
// — a re-fetch, a theme change, anything — tears the old one down instead
// of blowing up the whole results page.
let targetChart = null;

function renderPreferenceTarget(preference) {
  const canvas = document.getElementById("target-chart");
  if (!canvas || !preference || typeof Chart === "undefined") return;

  if (targetChart) {
    targetChart.destroy();
    targetChart = null;
  }

  const freqs = targetFrequencies();
  const curve = buildPreferenceCurve(preference.target, freqs);
  if (!curve) return;

  const styles = getComputedStyle(document.documentElement);
  const colour = styles.getPropertyValue("--accent-2").trim() || "#5b8def";

  const options = curveChartOptions();
  options.scales.y.title.text = "dB relative to midrange";
  options.plugins.legend.display = false;

  targetChart = new Chart(canvas.getContext("2d"), {
    type: "line",
    data: {
      labels: freqs,
      datasets: [{
        label: "Your preference target",
        data: curve,
        borderColor: colour,
        borderWidth: CURVE_LINE_WIDTH,
        borderDash: PREFERENCE_DASH,
        pointRadius: 0,
        tension: CURVE_TENSION,
        fill: false,
      }],
    },
    options,
  });

  showBlock("target-block");
}

/* ─────────────────────────── 3. Audio Analysis ─────────────────────── */

function renderAudioAnalysis(preference) {
  const list = document.getElementById("analysis-list");
  if (!list || !preference?.analysis) return;

  list.innerHTML = preference.analysis.regions.map(region => `
    <div class="analysis-row">
      <div class="analysis-band">
        ${escapeHtml(region.label)}
        <span class="analysis-level">${escapeHtml(region.level_label)}</span>
      </div>
      <p class="analysis-text">${escapeHtml(region.sentence)}</p>
    </div>
  `).join("");

  showBlock("analysis-block");
}

/* ────────────────────── 5. Hearing Preservation ────────────────────── */

function renderHearingPreservation(section) {
  const block = document.getElementById("hearing-block");
  if (!block || !section) return;

  const notes = (section.notes || []).map(note => `
    <div class="hearing-note">
      <h3>${escapeHtml(note.title)}</h3>
      <p>${escapeHtml(note.body)}</p>
    </div>
  `).join("");

  const tips = (section.tips || []).map(tip => `
    <li>
      <strong>${escapeHtml(tip.title)}</strong>
      <span>${escapeHtml(tip.body)}</span>
    </li>
  `).join("");

  block.innerHTML = `
    <div class="hearing-inner">
      <h2 class="hearing-title">${escapeHtml(section.title)}</h2>
      <p class="hearing-intro">${escapeHtml(section.intro)}</p>
      ${notes}
      <ul class="hearing-tips">${tips}</ul>
      <p class="hearing-standard">${escapeHtml(section.standard_note)}</p>
      <p class="hearing-disclaimer">${escapeHtml(section.disclaimer)}</p>
    </div>
  `;

  block.hidden = false;
}

const EQ_BANDS = [
  { type: "lowshelf", frequency: 100, Q: 0.7, band: "bass_gain", gainKey: "bassGain" },
  { type: "peaking", frequency: 3000, Q: 1.4, band: "presence_gain", gainKey: "presenceGain" },
  { type: "highshelf", frequency: 8000, Q: 0.7, band: "treble_gain", gainKey: "trebleGain" },
];

const ANALYSIS_SAMPLE_RATE = 44100;

const DB_PER_DECADE = 20;

function buildPreferenceCurve(profile, frequencies) {
  if (!profile || typeof OfflineAudioContext === "undefined") return null;

  try {
    const ctx = new OfflineAudioContext(1, 1, ANALYSIS_SAMPLE_RATE);
    const freqArray = new Float32Array(frequencies);
    const totalDb = new Float32Array(frequencies.length);

    for (const spec of EQ_BANDS) {
      const gain = Number(profile[spec.band] ?? 0);
      if (!gain) continue;

      const filter = ctx.createBiquadFilter();
      filter.type = spec.type;
      filter.frequency.value = spec.frequency;
      filter.Q.value = spec.Q;
      filter.gain.value = gain;

      const mag = new Float32Array(frequencies.length);
      const phase = new Float32Array(frequencies.length);
      filter.getFrequencyResponse(freqArray, mag, phase);

      for (let i = 0; i < mag.length; i++) {
        totalDb[i] += DB_PER_DECADE * Math.log10(mag[i]);
      }
    }

    return Array.from(totalDb);
  } catch (err) {
    console.error("Could not build preference curve:", err);
    return null;
  }
}

function normaliseToMidband(curve) {
  const mid = curve.filter(([f]) => f >= 500 && f <= 2000).map(([, spl]) => spl);
  if (!mid.length) return curve.map(([f, spl]) => [f, spl]);

  const ref = mid.reduce((a, b) => a + b, 0) / mid.length;
  return curve.map(([f, spl]) => [f, spl - ref]);
}

const CURVE_LINE_WIDTH = 2;
const CURVE_TENSION = 0.1;
const PREFERENCE_DASH = [6, 4];
const CURVE_X_TICK_LIMIT = 7;

// Minimum gap, in pixels, between two labels on the frequency axis.
const CURVE_X_TICK_PADDING = 12;

// Shorten frequencies to the kHz form used on every published frequency
// response graph: 5,000 becomes 5k, 10,000 becomes 10k.
//
// This is the actual fix for labels colliding. The axis is logarithmic, so
// ticks sit at ratios rather than even distances and the decades bunch up at
// the right-hand end; on a card-width chart "5,000" and "10,000" printed
// touching and read as one number, "5,00010,000". Neither maxTicksLimit nor
// autoSkipPadding reliably prevents that -- Chart.js's auto-skipping is
// approximate on log scales -- but "5k" and "10k" are less than half the
// width, so they no longer meet. autoSkipPadding stays as a backstop.
//
// Below 1 kHz the plain number is clearer, so 500 stays 500 rather than
// becoming 0.5k.
function formatFrequencyTick(value) {
  const hz = Number(value);
  if (!Number.isFinite(hz)) return value;
  if (hz < 1000) return String(Math.round(hz));
  const k = hz / 1000;
  // 1.5k reads fine; 1.0k does not, so drop a trailing zero.
  return (Number.isInteger(k) ? k : k.toFixed(1)) + "k";
}
const LEGEND_BOX_WIDTH = 24;
const LEGEND_FONT_SIZE = 11;

// Fixed vertical range for the curve charts, in dB relative to midrange.
//
// Left to itself, Chart.js scales to the data, and the data includes the
// ultrasonic roll-off above ~19 kHz where measurements dive to -35 dB and
// beyond. That is inaudible, it varies wildly between units, and letting it
// set the scale squashes everything else flat -- the preference curve spans
// about a decibel and a half, so against a -60 dB axis it renders as a
// straight line at zero and the comparison the card exists to show
// disappears.
//
// +/-15 dB covers the range IEMs actually differ over. Curves that exceed it
// are clipped at the edge rather than rescaling the chart, which is the
// deliberate trade: a little detail lost at the extremes, in exchange for the
// part anyone can hear being legible at all.
const CURVE_Y_MIN_DB = -15;
const CURVE_Y_MAX_DB = 15;

function curveDatasets(shaped, preference) {
  const styles = getComputedStyle(document.documentElement);
  const iemColour = styles.getPropertyValue("--accent").trim() || "#e8a33d";
  const prefColour = styles.getPropertyValue("--accent-2").trim() || "#5b8def";

  const datasets = [{
    label: "This IEM",
    data: shaped.map(([, db]) => db),
    borderColor: iemColour,
    borderWidth: CURVE_LINE_WIDTH,
    pointRadius: 0,
    tension: CURVE_TENSION,
  }];

  if (preference) {
    datasets.push({
      label: "Your preference",
      data: preference,
      borderColor: prefColour,
      borderWidth: CURVE_LINE_WIDTH,
      borderDash: PREFERENCE_DASH,
      pointRadius: 0,
      tension: CURVE_TENSION,
    });
  }

  return datasets;
}

// clampY: fix the vertical axis to CURVE_Y_MIN_DB..CURVE_Y_MAX_DB.
//
// Only the IEM comparison charts want this. They plot a measured curve and
// the listener's preference on the same axes, and the measurement's
// ultrasonic tail would otherwise set the scale and flatten the preference
// into a straight line.
//
// The Preference Target chart on its own is the opposite case: it plots one
// curve spanning about a decibel and a half, and auto-scaling is exactly what
// makes its shape readable. Clamping that one to +/-15 dB would flatten the
// very thing it exists to show, so it keeps the default.
function curveChartOptions({ clampY = false } = {}) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    interaction: { mode: "index", intersect: false },
    scales: {
      x: {
        type: "logarithmic",
        title: { display: true, text: "Frequency (Hz)" },
        ticks: {
          maxTicksLimit: CURVE_X_TICK_LIMIT,
          autoSkip: true,
          autoSkipPadding: CURVE_X_TICK_PADDING,
          callback: (value) => formatFrequencyTick(value),
        },
      },
      y: {
        title: { display: true, text: "dB (relative to midrange)" },
        ...(clampY ? { min: CURVE_Y_MIN_DB, max: CURVE_Y_MAX_DB } : {}),
      },
    },
    plugins: {
      legend: {
        display: true,
        position: "bottom",
        labels: {
          boxWidth: LEGEND_BOX_WIDTH,
          font: { size: LEGEND_FONT_SIZE },
        },
      },
    },
  };
}

async function renderIemCurve(iemId, cardEl, profile) {
  const wrap = cardEl.querySelector(".iem-curve-wrap");
  if (!wrap) return;

  try {
    const res = await fetch(`${API.iemCurve}?id=${encodeURIComponent(iemId)}`);
    if (!res.ok) return;

    const { curve, description } = await res.json();
    if (!curve || !curve.length) return;

    cardEl.querySelector(".iem-description").textContent = description || "";

    if (typeof Chart === "undefined") {
      wrap.hidden = false;
      return;
    }

    const shaped = normaliseToMidband(curve);
    const freqs = shaped.map(([f]) => f);

    new Chart(cardEl.querySelector(".iem-curve-chart").getContext("2d"), {
      type: "line",
      data: {
        labels: freqs,
        datasets: curveDatasets(shaped, buildPreferenceCurve(profile, freqs)),
      },
      options: curveChartOptions({ clampY: true }),
    });

    wrap.hidden = false;
  } catch (err) {
    console.error(`Could not load curve for IEM ${iemId}:`, err);
  }
}

async function loadProfile() {
  const nameEl = document.getElementById("profile-name");
  if (!nameEl) return;

  // The three bands each get their own figure. They used to be one
  // sentence; three columns lets them be compared at a glance, which is
  // the only thing anyone does with them.
  const statsEl = document.getElementById("profile-sound-stats");
  const noteEl = document.getElementById("profile-sound-note");
  const bassEl = document.getElementById("profile-bass");
  const trebleEl = document.getElementById("profile-treble");
  const presenceEl = document.getElementById("profile-presence");

  const note = (text, isError = false) => {
    if (!noteEl) return;
    noteEl.textContent = text;
    noteEl.classList.toggle("error", isError);
  };

  try {
    const meRes = await fetch("api/auth/me.php");
    if (!meRes.ok) {
      window.location.href = "login.php?redirect=profile.html";
      return;
    }
    const me = await meRes.json();
    nameEl.textContent = me.name;

    const res = await fetch("api/profile.php");
    const profile = await res.json();

    if (profile.error) {
      if (statsEl) statsEl.style.display = "none";
      note("No profile yet — take the sound test");
    } else {
      // These are the aggregate across every test, the same numbers the
      // recommendations are matched against. Saying how many tests it is
      // built from stops it reading as a single measurement — and stops
      // it looking wrong when it does not equal the last test taken.
      const n = profile.assessmentCount || 1;
      const from = n === 1
        ? "from 1 listening test"
        : `averaged over ${n} listening tests`;

      if (bassEl) bassEl.textContent = signed(profile.bassGain);
      if (trebleEl) trebleEl.textContent = signed(profile.trebleGain);
      if (presenceEl) presenceEl.textContent = signed(profile.presenceGain);
      note(from);
    }

    if (typeof setProfilePicture === "function") {
      setProfilePicture(profile.profilePicture || null);
    }
  } catch (err) {
    // fetch() throws, rather than returning a status, when the request
    // never reaches a server at all -- the page opened straight off disk
    // as file://, or Apache not running. That used to land here and do
    // nothing but log, so the card sat showing its three placeholder
    // dashes: indistinguishable from a real profile that happened to read
    // zero, and with no hint that anything had failed. Say so instead.
    note("Could not load your profile. Check you are signed in and the "
      + "site is open through localhost rather than the file itself.", true);
    console.error(err);
  }
}

async function loadSettings() {
  const checkboxes = document.querySelectorAll(".setting-checkbox");
  if (!checkboxes.length) return;

  try {
    const res = await fetch("api/settings.php");
    const settings = await res.json();

    checkboxes.forEach(box => {
      const key = box.dataset.key;
      box.checked = !!settings[key];
    });
  } catch (err) {
    console.error(err);
  }

  updateNotifStatus();
}

/* ─────────────────────── two-factor, from Settings ───────────────────── */

// Posts JSON with a CSRF token, retrying once on 403. Sessions rotate their
// id every 30 minutes, so a token can go stale while a settings page sits
// open — the retry turns that from a confusing failure into nothing at all.
// The same POST as apiPost, with the body already parsed. Callers here all
// want both the status and the payload, and were each writing the await
// twice; this was a near-duplicate of apiPost until the retry moved out.
async function postWithCsrf(url, payload) {
  const res = await apiPost(url, payload);
  return { res, data: await res.json() };
}

function tfaStatus(message, kind) {
  const el = document.getElementById("tfa-status");
  if (!el) return;
  el.textContent = message || "";
  el.className = "tfa-status" + (kind ? " " + kind : "");
}

let twoFactorEnabled = false;

// What the two-factor block should say, given the server's answer.
//
// Deliberately knows nothing about the page: it takes the status payload
// and returns strings. Wording is the part that gets revised, and having it
// separate means it can be checked without a browser, and changed without
// going near the DOM code that displays it.
function twoFactorCopy(data) {
  const enabled = !!data.enabled;

  if (!enabled) {
    return {
      state: "Off",
      enabled: false,
      note: "Ask for a code from an authenticator app as well as your password. "
        + "Optional — your password alone still works until you turn this on.",
      action: "Turn On",
      actionHidden: false,
    };
  }

  const left = data.recoveryCodesRemaining;
  let note = `You'll be asked for a code from your authenticator app each time you `
    + `log in. ${left} recovery ${left === 1 ? "code" : "codes"} left.`;

  // When the policy makes it compulsory the server refuses to switch it
  // off, so the button is not offered at all — and the note says why,
  // rather than leaving a control that has silently vanished.
  if (data.required) {
    note += " Required on this site, so it can't be switched off.";
  }

  return {
    state: "On",
    enabled: true,
    note,
    action: "Turn Off",
    actionHidden: !!data.required,
  };
}


function renderTwoFactor(copy) {
  const state = document.getElementById("tfa-state");
  const note = document.getElementById("tfa-note");
  const action = document.getElementById("tfa-action");
  if (!state || !note || !action) return;

  state.textContent = copy.state;
  state.classList.toggle("on", copy.enabled);
  note.textContent = copy.note;
  action.textContent = copy.action;
  action.hidden = copy.actionHidden;
}


async function loadTwoFactorState() {
  const box = document.getElementById("tfa-box");
  if (!box) return;

  try {
    const res = await fetch("api/auth/2fa-status.php");
    if (!res.ok) return;

    const data = await res.json();
    twoFactorEnabled = !!data.enabled;

    renderTwoFactor(twoFactorCopy(data));
    box.hidden = false;
  } catch (err) {
    console.error(err);
  }
}

function handleTwoFactorAction() {
  if (!twoFactorEnabled) {
    // Enrolment needs the QR, the code entry and the recovery codes — a
    // whole page, not a button. two-factor.php already does exactly that
    // and works the same whether you arrive mid-login or from here.
    window.location.href = "two-factor.php?setup=1&redirect=settings.html";
    return;
  }

  document.getElementById("tfa-action").hidden = true;
  document.getElementById("tfa-disable-form").hidden = false;
  document.getElementById("tfa-password").focus();
  tfaStatus("");
}

function cancelTwoFactorOff() {
  document.getElementById("tfa-disable-form").hidden = true;
  document.getElementById("tfa-action").hidden = false;
  document.getElementById("tfa-password").value = "";
  tfaStatus("");
}

async function confirmTwoFactorOff() {
  const input = document.getElementById("tfa-password");
  const password = input.value;

  if (!password) {
    tfaStatus("Enter your password to confirm.", "error");
    return;
  }

  tfaStatus("Checking...");

  try {
    const { res, data } = await postWithCsrf("api/auth/2fa-disable.php", { password });

    if (!res.ok) {
      tfaStatus(data.error || "Something went wrong.", "error");
      return;
    }

    input.value = "";
    document.getElementById("tfa-disable-form").hidden = true;
    document.getElementById("tfa-action").hidden = false;
    tfaStatus("Two-factor is off. Your password alone now logs you in.", "success");
    await loadTwoFactorState();
  } catch (err) {
    tfaStatus("Could not reach the server.", "error");
  }
}

// PUT rather than POST, and the token rides in a header rather than the
// body — which is why this cannot just call apiPost. The retry is the same
// though, so it comes from the same place.
async function saveSetting(key, checked) {
  try {
    await sendWithCsrfRetry(token => fetch("api/settings.php", {
      method: "PUT",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": token || "",
      },
      body: JSON.stringify({ [key]: checked }),
    }));
  } catch (err) {
    console.error(err);
  }
}

async function toggleNotifications(checked) {
  saveSetting("notifications", checked);

  if (!checked || !("Notification" in window)) {
    updateNotifStatus();
    return;
  }

  // Ask only if the user hasn't already decided. Calling this when the
  // answer is already "denied" does nothing — the browser will not re-prompt,
  // which is why updateNotifStatus() has to explain that state instead.
  if (Notification.permission === "default") {
    try {
      await Notification.requestPermission();
    } catch (err) {
      console.error(err);
    }
  }

  updateNotifStatus();

  // Fire one immediately on enabling. Without it the toggle gives no sign of
  // working at all: the only other notification in the app happens at the end
  // of a listening test, so the setting looks broken until you finish one.
  if (Notification.permission === "granted") {
    try {
      new Notification("EqualizeME", {
        body: "Notifications are on. You'll get one when your profile is ready.",
      });
    } catch (err) {
      // Some browsers only allow notifications from a service worker. The
      // setting is still saved; it just can't be demonstrated here.
      console.error("Could not show a test notification:", err);
    }
  }
}

function updateNotifStatus() {
  const statusEl = document.getElementById("notif-status");
  if (!statusEl) return;

  const box = document.querySelector('.setting-checkbox[data-key="notifications"]');
  const wanted = box ? box.checked : false;

  if (!("Notification" in window)) {
    statusEl.textContent = "This browser doesn't support notifications.";
    return;
  }

  // A secure context is required. http://localhost counts as one, but
  // http://192.168.x.x does not — worth saying plainly, because the API is
  // simply absent there rather than failing with an error.
  if (!window.isSecureContext) {
    statusEl.textContent =
      "Notifications need HTTPS or localhost. On a plain http:// address the browser blocks them.";
    return;
  }

  if (Notification.permission === "denied") {
    statusEl.textContent =
      "Blocked by your browser. Click the icon at the left of the address bar to allow them for this site.";
  } else if (Notification.permission === "default" && wanted) {
    statusEl.textContent =
      "Waiting for permission — allow notifications when your browser asks.";
  } else if (Notification.permission === "granted" && wanted) {
    statusEl.textContent = "On. You'll be notified when a listening test finishes.";
  } else {
    statusEl.textContent = "";
  }
}

async function updateNavAuthState() {
  const loginLink = document.getElementById("nav-login");
  const registerLink = document.getElementById("nav-register");
  const logoutLink = document.getElementById("nav-logout");
  const historyLink = document.getElementById("nav-history");
  if (!loginLink && !registerLink && !logoutLink && !historyLink) return;

  try {
    const res = await fetch("api/auth/me.php");
    const loggedIn = res.ok;

    if (loginLink) loginLink.style.display = loggedIn ? "none" : "";
    if (registerLink) registerLink.style.display = loggedIn ? "none" : "";
    if (logoutLink) logoutLink.style.display = loggedIn ? "" : "none";
    if (historyLink) historyLink.style.display = loggedIn ? "" : "none";
  } catch (err) {
    console.error(err);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  updateNavAuthState();
  loadRecommendations();
  loadProfile();
  loadSettings();
  loadTwoFactorState();
});
