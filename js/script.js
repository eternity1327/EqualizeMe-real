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

// Same machine as ai_service.py, so localhost is more robust than a LAN IP
// (which breaks any time DHCP hands out a different address).
const DSP_SERVICE_URL = "http://127.0.0.1:5001";

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
      <div class="iem-card">
        <img class="iem-card-img" src="${item.image_url || 'images/iem-placeholder.svg'}"
          alt="${item.brand} ${item.name}"
          onerror="this.onerror=null; this.src='images/iem-placeholder.svg';">
        <h3>${item.brand} ${item.name}</h3>
        <p>${item.sound_signature ?? ""}</p>
        <p>Match: ${item.match_score}%</p>
        <p class="price">${item.price !== null ? "₱" + item.price.toLocaleString() : "N/A"}</p>
        ${item.product_url ? `<a href="${item.product_url}" target="_blank">Buy at ${item.retailer_name ?? "retailer"}</a>` : ""}
      </div>
    `).join("");
  } catch (err) {
    grid.innerHTML = "<p>Could not load recommendations.</p>";
    console.error(err);
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
    await fetch("api/settings.php", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ [key]: checked })
    });
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
