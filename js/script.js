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

const DSP_SERVICE_URL = `${window.location.protocol}//${window.location.hostname}:5001`;

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
    console.error(`Could not load curve for IEM ${iemId}:`, err);
  }
}

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

document.addEventListener("DOMContentLoaded", () => {
  updateNavAuthState();
  loadRecommendations();
  loadProfile();
  loadSettings();
});
