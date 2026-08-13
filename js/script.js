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
async function loadRecommendations() {
  const grid = document.getElementById("iem-grid");
  if (!grid) return;

  try {
    // Get the logged-in user's id via the PHP session (same-origin, so this works)
    const meRes = await fetch("api/auth/me.php");
    if (!meRes.ok) {
      grid.innerHTML = "<p>Please log in first.</p>";
      return;
    }
    const me = await meRes.json();

    const res = await fetch(`${DSP_SERVICE_URL}/recommendations/${me.id}`);
    const data = await res.json();

    if (data.error) {
      grid.innerHTML = `<p>${data.error}. Take the <a href="test.html">listening test</a> first.</p>`;
      return;
    }

    if (!data.recommendations || data.recommendations.length === 0) {
      grid.innerHTML = "<p>No matching IEMs found yet.</p>";
      return;
    }

    grid.innerHTML = data.recommendations.map(item => `
      <div class="iem-card" data-iem-id="${item.iem_id}">
        <img class="iem-card-img" src="${item.image_url || 'images/iem-placeholder.svg'}"
          alt="${item.brand} ${item.name}"
          onerror="this.onerror=null; this.src='images/iem-placeholder.svg';">
        <h3>${item.brand} ${item.name}</h3>
        <p>${item.sound_signature ?? ""}</p>
        <p>Match: ${item.match_score}%</p>
        <p class="price">${formatPrice(item.price)}</p>
        <div class="iem-curve-wrap" hidden>
          <canvas class="iem-curve-chart" height="150"></canvas>
          <p class="iem-description"></p>
        </div>
        ${buildBuyLinks(item)}
      </div>
    `).join("");

    // Charts are loaded after the cards are on the page, one request per
    // IEM. Done separately from the main render so a missing measurement
    // (the common case until REW files are imported) can't hold up or
    // break the cards themselves.
    data.recommendations.forEach(item => {
      const card = grid.querySelector(`.iem-card[data-iem-id="${item.iem_id}"]`);
      if (card) renderIemCurve(item.iem_id, card);
    });
  } catch (err) {
    grid.innerHTML = "<p>Could not load recommendations.</p>";
    console.error(err);
  }
}

// Fetches one IEM's measured frequency response and renders it into its
// card as a chart plus the plain-language description generated by
// interpreter.py on the backend.
//
// An IEM with no imported measurement returns 404 — expected, not an
// error — so the curve section just stays hidden and the card renders
// normally without it.
async function renderIemCurve(iemId, cardEl) {
  const wrap = cardEl.querySelector(".iem-curve-wrap");
  if (!wrap) return;

  try {
    const res = await fetch(`${DSP_SERVICE_URL}/api/iems/${iemId}/curve`);
    if (!res.ok) return;

    const { curve, description } = await res.json();
    if (!curve || !curve.length) return;

    cardEl.querySelector(".iem-description").textContent = description || "";

    // Chart.js is loaded from a CDN in recommendations.html. If it's
    // blocked or offline, still show the written description rather than
    // failing outright — the sentence is the more important half.
    if (typeof Chart === "undefined") {
      wrap.hidden = false;
      return;
    }

    const ctx = cardEl.querySelector(".iem-curve-chart").getContext("2d");
    new Chart(ctx, {
      type: "line",
      data: {
        labels: curve.map(([freq]) => freq),
        datasets: [{
          data: curve.map(([, spl]) => spl),
          borderColor: getComputedStyle(document.documentElement)
            .getPropertyValue("--accent").trim() || "#e8a33d",
          borderWidth: 1.5,
          pointRadius: 0,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        scales: {
          x: {
            type: "logarithmic",
            title: { display: true, text: "Hz" },
            ticks: { maxTicksLimit: 6 },
          },
          y: { title: { display: true, text: "dB SPL" } },
        },
        plugins: { legend: { display: false } },
      },
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
