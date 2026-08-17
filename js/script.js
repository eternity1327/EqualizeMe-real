// ===== Dark Mode =====
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

// ===== Profile dropdown =====
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

// Resolves to whatever host/domain the page itself was loaded from, so this
// works on localhost and over the LAN with no edits — the normal case while
// developing.
//
// EXCEPTION: Cloudflare quick tunnels put the app and the API on two
// different random hostnames, so this same-host assumption breaks there.
// When running tunnels, comment this line out and hardcode the API tunnel's
// URL instead (and update it again on every cloudflared restart, since quick
// tunnel URLs change each time):
//
//   const DSP_SERVICE_URL = "https://your-api-tunnel.trycloudflare.com";
//
const DSP_SERVICE_URL = `${window.location.protocol}//${window.location.hostname}:5001`;

// ---------------------------------------------------------------------------
// CSRF token
//
// The PHP endpoints that change data require a token tied to your session.
// Fetched once and reused — it doesn't change for the life of the session.
// Only needed for the PHP API; the Flask service on :5001 is a separate
// origin and relies on its ALLOWED_ORIGIN setting instead.
// ---------------------------------------------------------------------------
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

// Clears the cached token so the next request fetches a fresh one — used
// after a 403, which usually means the session (and its token) was
// replaced while the page stayed open.
function invalidateCsrfToken() {
  _csrfToken = null;
}

// ---------------------------------------------------------------------------
// Prices
//
// The `price` column stores USD, because that's what the squig.link
// catalogue quotes. Our users are in the Philippines, so pesos are shown
// as the headline figure with the original dollar amount kept alongside.
//
// Showing both matters: the peso number is a CONVERSION, not a real local
// price. Philippine retail for imported IEMs includes shipping, duties and
// reseller margin, so the actual Shopee/Lazada price is usually higher
// than this. Displaying only pesos would present a converted figure as if
// it were what you'd pay.
//
// The rate below is fixed at build time rather than fetched live — a
// student project shouldn't depend on a currency API being up, and a
// stale-but-labelled rate is easier to defend than a silently changing
// one. Update it (and the date) if it drifts enough to matter.
// ---------------------------------------------------------------------------
const USD_TO_PHP = 61.2;          // mid-market rate, checked 2026-08-13
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

// ---------------------------------------------------------------------------
// Buy links
//
// Not every catalogue entry has a shop link — plenty of IEMs, especially
// boutique ones, have no `shopLink` field at all, which left those cards
// with nothing to click. A Shopee search is offered for every IEM so
// there's always somewhere to go, and it's the store people here actually
// use.
//
// This is a SEARCH url, not a verified product page: it's built from the
// brand and model, so it lands on Shopee's results rather than guaranteeing
// the exact item exists. Labelled "Search on Shopee" rather than "Buy"
// so nobody expects a checkout page.
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// Per-band agreement
//
// The headline match score is one number covering three bands, which hides
// the interesting part: an IEM can nail your bass and miss your treble
// completely, and end up with the same overall score as one that's mediocre
// everywhere. Showing each band separately says WHERE it fits.
// ---------------------------------------------------------------------------
function buildBandMatch(bandMatch) {
  if (!bandMatch) return "";

  const labels = {
    bass_gain: "Bass",
    presence_gain: "Presence",
    treble_gain: "Treble",
  };

  const rows = Object.keys(labels).map(band => {
    const pct = bandMatch[band];
    if (pct === undefined) return "";
    return `
      <div class="band-row">
        <span class="band-name">${labels[band]}</span>
        <span class="band-bar"><span class="band-fill" style="width:${pct}%"></span></span>
        <span class="band-pct">${Math.round(pct)}%</span>
      </div>`;
  }).join("");

  return `<div class="band-match">${rows}</div>`;
}

function buildBuyLinks(item) {
  const links = [];

  if (item.product_url) {
    const label = item.retailer_name ? `Buy at ${item.retailer_name}` : "Buy from shop";
    links.push(
      `<a href="${item.product_url}" target="_blank" rel="noopener noreferrer">${label}</a>`
    );
  }

  const query = encodeURIComponent(`${item.brand} ${item.name}`.trim());
  links.push(
    `<a href="https://shopee.ph/search?keyword=${query}" target="_blank" rel="noopener noreferrer">Search on Shopee</a>`
  );

  return `<div class="iem-links">${links.join("")}</div>`;
}

// Used on test.html - sends the chosen preference to the backend
async function choose(sound) {
  const result = document.getElementById("result");
  result.innerHTML = "Saving...";

  try {
    const res = await fetch("/api/test", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ sound })
    });

    const data = await res.json();

    if (!res.ok) {
      result.innerHTML = `Error: ${data.error}`;
      return;
    }

    const labels = { bass: "Bass", balanced: "Balanced", detail: "Clarity" };
    result.innerHTML = `You prefer: ${labels[sound]}`;
  } catch (err) {
    result.innerHTML = "Something went wrong. Is the server running?";
    console.error(err);
  }
}

// Used on recommendations.html - fetches matching IEMs from the real backend
function buildIemCard(item) {
  return `
      <div class="iem-card" data-iem-id="${item.iem_id}">
        <img class="iem-card-img" src="${item.image_url || 'images/iem-placeholder.svg'}"
          alt="${item.brand} ${item.name}"
          onerror="this.onerror=null; this.src='images/iem-placeholder.svg';">
        <h3>${item.brand} ${item.name}</h3>
        <p>${item.sound_signature ?? ""}</p>
        <p class="iem-match">Match: ${item.match_score}%</p>
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

/**
 * Charts load after the cards are on the page, one request per IEM, so a
 * missing measurement can't hold up or break the cards themselves.
 */
function renderCurves(grid, data) {
  data.recommendations.forEach(item => {
    const card = grid.querySelector(`.iem-card[data-iem-id="${item.iem_id}"]`);
    if (card) renderIemCurve(item.iem_id, card, data.profile);
  });
}

async function loadRecommendations() {
  const grid = document.getElementById("iem-grid");
  if (!grid) return;

  try {
    // The user's id comes from the PHP session, which is same-origin.
    const meRes = await fetch("api/auth/me.php");
    if (!meRes.ok) {
      grid.innerHTML = "<p>Please log in first.</p>";
      return;
    }
    const me = await meRes.json();

    const res = await fetch(`${DSP_SERVICE_URL}/recommendations/${me.id}`);
    const data = await res.json();

    if (data.error) {
      grid.innerHTML =
        `<p>${data.error}. Take the <a href="test.html">listening test</a> first.</p>`;
      return;
    }

    if (!data.recommendations || data.recommendations.length === 0) {
      grid.innerHTML = "<p>No matching IEMs found yet.</p>";
      return;
    }

    grid.innerHTML = data.recommendations.map(buildIemCard).join("");
    renderCurves(grid, data);
  } catch (err) {
    grid.innerHTML = "<p>Could not load recommendations.</p>";
    console.error(err);
  }
}

// Turning the listener's three preference numbers into a curve.
//
// A profile is three figures; an IEM measurement is several hundred
// points. To draw them on the same axes, the three are expanded through
// the exact filters the listening test applied — the same biquads
// camilla_dsp.py and adaptiveTest.js use. The line drawn is literally the
// EQ the listener chose, computed by Web Audio's own
// getFrequencyResponse() rather than a hand-rolled approximation.
//
// Defined here because script.js loads before adaptiveTest.js, which
// builds its playback filters from this same list.
const EQ_BANDS = [
  { type: "lowshelf", frequency: 100, Q: 0.7, band: "bass_gain", gainKey: "bassGain" },
  { type: "peaking", frequency: 3000, Q: 1.4, band: "presence_gain", gainKey: "presenceGain" },
  { type: "highshelf", frequency: 8000, Q: 0.7, band: "treble_gain", gainKey: "trebleGain" },
];

// getFrequencyResponse() needs a context but not a real one; a single
// frame at CD rate is the cheapest that satisfies the constructor.
const ANALYSIS_SAMPLE_RATE = 44100;

// Amplitude ratio to decibels.
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

      // Filters chain in series, so their dB contributions add.
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

// The measured curve's absolute SPL depends on how loud the rig was driven,
// so it means nothing on its own. Subtracting the 500-2000 Hz average turns
// it into a shape — deviation from its own midrange — which is directly
// comparable to the preference curve, since that's also dB relative to flat.
// This is the same normalisation measurement_parser.py uses for the gains.
function normaliseToMidband(curve) {
  const mid = curve.filter(([f]) => f >= 500 && f <= 2000).map(([, spl]) => spl);
  if (!mid.length) return curve.map(([f, spl]) => [f, spl]);

  const ref = mid.reduce((a, b) => a + b, 0) / mid.length;
  return curve.map(([f, spl]) => [f, spl - ref]);
}

// Fetches one IEM's measured frequency response and renders it into its
// card, overlaid with the listener's own preference curve, plus the
// plain-language description generated by interpreter.py on the backend.
//
// An IEM with no imported measurement returns 404 — expected, not an
// error — so the curve section just stays hidden and the card renders
// normally without it.
// Chart appearance, kept together so the two lines stay visually
// distinguishable if either is restyled.
const CURVE_LINE_WIDTH = 2;
const CURVE_TENSION = 0.1;
const PREFERENCE_DASH = [6, 4];
const CURVE_X_TICK_LIMIT = 7;
const LEGEND_BOX_WIDTH = 24;
const LEGEND_FONT_SIZE = 11;

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

function curveChartOptions() {
  return {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    interaction: { mode: "index", intersect: false },
    scales: {
      // Logarithmic because hearing is: the octave from 100 to 200 Hz
      // matters as much as the one from 5 to 10 kHz.
      x: {
        type: "logarithmic",
        title: { display: true, text: "Frequency (Hz)" },
        ticks: { maxTicksLimit: CURVE_X_TICK_LIMIT },
      },
      y: {
        title: { display: true, text: "dB (relative to midrange)" },
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
    const res = await fetch(`${DSP_SERVICE_URL}/api/iems/${iemId}/curve`);
    if (!res.ok) return;

    const { curve, description } = await res.json();
    if (!curve || !curve.length) return;

    cardEl.querySelector(".iem-description").textContent = description || "";

    // Chart.js comes from a CDN. If it's blocked, still show the written
    // description — the sentence is the more important half.
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
      options: curveChartOptions(),
    });

    wrap.hidden = false;
  } catch (err) {
    // Network failure or malformed data — leave the card without a chart.
    console.error(`Could not load curve for IEM ${iemId}:`, err);
  }
}

// Used on profile.html - loads the user's saved auditory profile
async function loadProfile() {
  const nameEl = document.getElementById("profile-name");
  const soundEl = document.getElementById("profile-sound");
  if (!nameEl) return;

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
      soundEl.textContent = "No profile yet — take the sound test";
    } else {
      soundEl.textContent =
        `Bass ${profile.bassGain > 0 ? "+" : ""}${profile.bassGain}dB, ` +
        `Treble ${profile.trebleGain > 0 ? "+" : ""}${profile.trebleGain}dB, ` +
        `Presence ${profile.presenceGain > 0 ? "+" : ""}${profile.presenceGain}dB`;
    }

    if (typeof setProfilePicture === "function") {
      setProfilePicture(profile.profilePicture || null);
    }
  } catch (err) {
    console.error(err);
  }
}

// Used on settings.html - loads and saves checkbox toggles
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

async function saveSetting(key, checked) {
  try {
    const token = await getCsrfToken();

    const res = await fetch("api/settings.php", {
      method: "PUT",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": token || ""
      },
      body: JSON.stringify({ [key]: checked })
    });

    // A rejected token usually means the session was replaced while this
    // page sat open — get a fresh one and retry once before giving up.
    if (res.status === 403) {
      invalidateCsrfToken();
      const retryToken = await getCsrfToken();
      await fetch("api/settings.php", {
        method: "PUT",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": retryToken || ""
        },
        body: JSON.stringify({ [key]: checked })
      });
    }
  } catch (err) {
    console.error(err);
  }
}

// Notifications checkbox needs to request browser permission before the
// setting can actually do anything. Permission must be requested from a
// direct user gesture (this onchange handler), so it's asked for here
// first, synchronously, before saveSetting's async fetch runs.
function toggleNotifications(checked) {
  if (checked && "Notification" in window && Notification.permission === "default") {
    Notification.requestPermission().then(updateNotifStatus);
  }
  saveSetting("notifications", checked);
  updateNotifStatus();
}

function updateNotifStatus() {
  const statusEl = document.getElementById("notif-status");
  if (!statusEl) return;

  if (!("Notification" in window)) {
    statusEl.textContent = "Your browser doesn't support notifications.";
  } else if (Notification.permission === "denied") {
    statusEl.textContent = "Notifications are blocked in your browser settings — enable them there to use this.";
  } else {
    statusEl.textContent = "";
  }
}

// Shows Login/Register or Logout in the nav depending on whether the user is logged in
async function updateNavAuthState() {
  const loginLink = document.getElementById("nav-login");
  const registerLink = document.getElementById("nav-register");
  const logoutLink = document.getElementById("nav-logout");
  if (!loginLink && !registerLink && !logoutLink) return;

  try {
    const res = await fetch("api/auth/me.php");
    const loggedIn = res.ok;

    if (loginLink) loginLink.style.display = loggedIn ? "none" : "";
    if (registerLink) registerLink.style.display = loggedIn ? "none" : "";
    if (logoutLink) logoutLink.style.display = loggedIn ? "" : "none";
  } catch (err) {
    console.error(err);
  }
}

// Run the right loader depending on which page we're on
document.addEventListener("DOMContentLoaded", () => {
  updateNavAuthState();
  loadRecommendations();
  loadProfile();
  loadSettings();
});
