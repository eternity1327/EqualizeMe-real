/**
 * The admin page: assigning songs to the ten listening-test slots.
 *
 * Everything here is convenience. The page draws nothing until the server
 * confirms the account is an administrator, and every request it makes is
 * checked again at the endpoint. Deleting this file would remove the
 * interface and change nobody's permissions.
 */

const ADMIN_API = {
  me: "api/auth/me.php",
  songs: "api/admin/songs.php",
  upload: "api/admin/upload-song.php",
};

/**
 * Show the page only to administrators, and send everyone else home.
 *
 * Deliberately quiet about why. Telling a signed-in ordinary user "you are
 * not an administrator" confirms that an admin area exists and that their
 * account is simply the wrong rank for it. Going to the homepage says
 * nothing either way.
 *
 * requireLogin() in script.js has already dealt with signed-out visitors,
 * so anyone reaching here has a session — the only question is the role.
 */
async function guardAdminPage() {
  let me;
  try {
    const res = await fetch(ADMIN_API.me);
    if (!res.ok) return false;          // requireLogin handles the redirect
    me = await res.json();
  } catch (err) {
    // Offline or the server is unreachable. Bouncing to another page that
    // also needs the network would turn one failure into a loop.
    console.error(err);
    return false;
  }

  if (!me.isAdmin) {
    window.location.replace("index.html");
    return false;
  }

  const section = document.getElementById("admin-section");
  if (section) section.hidden = false;
  return true;
}

function adminError(message) {
  const el = document.getElementById("admin-error");
  if (el) el.textContent = message || "";
}

async function loadSlots() {
  const list = document.getElementById("slot-list");
  if (!list) return;

  let data;
  try {
    const res = await fetch(ADMIN_API.songs);
    if (!res.ok) {
      adminError("Could not load the song list.");
      return;
    }
    data = await res.json();
  } catch (err) {
    adminError("Could not reach the server.");
    console.error(err);
    return;
  }

  adminError("");
  list.innerHTML = data.songs.map(buildSlot).join("");
  data.songs.forEach(wireSlot);
}

/**
 * Which question a slot belongs to.
 *
 * The key is "sampleN.wav" and N is the question number — the mapping
 * at_sample_for_question() makes. Parsed rather than stored so the two
 * cannot drift apart in the display.
 */
function slotNumber(sampleKey) {
  const match = /(\d+)/.exec(sampleKey || "");
  return match ? match[1] : "?";
}

function buildSlot(song) {
  const broken = !song.pathIsValid || !song.fileExists;

  const warning = broken
    ? `<p class="slot-warning">${escapeHtml(
        !song.pathIsValid
          ? "This path is not allowed, so the test is falling back to the original file."
          : "No file at this path on the server, so the test is falling back to the original file."
      )}</p>`
    : "";

  // A player, so the person assigning a track can confirm it is the right
  // one without starting a listening test. Only offered when the file is
  // actually there -- an audio element pointing at nothing just fails
  // silently and looks like a bug in the page.
  const preview = broken
    ? ""
    : `<audio controls preload="none" src="${escapeHtml(song.filePath)}"></audio>`;

  return `
    <div class="slot${broken ? " broken" : ""}" data-key="${escapeHtml(song.sampleKey)}">
      <div class="slot-head">
        <span class="slot-number">Question ${escapeHtml(slotNumber(song.sampleKey))}</span>
        <span class="slot-file">${escapeHtml(song.filePath)}</span>
      </div>

      ${warning}

      <div class="slot-fields">
        <div class="field">
          <label>Title</label>
          <input type="text" class="slot-title" value="${escapeHtml(song.title || "")}"
            maxlength="120" placeholder="Song title">
        </div>
        <div class="field">
          <label>Section — leave blank for a whole song</label>
          <input type="text" class="slot-section" value="${escapeHtml(song.section || "")}"
            maxlength="60" placeholder="Chorus">
        </div>
      </div>

      <div class="field slot-markers-field">
        <label>Markers — label then time, separated by commas</label>
        <input type="text" class="slot-markers" value="${escapeHtml(song.markers || "")}"
          placeholder="Intro 0:00, Verse 0:34, Chorus 1:12">
        <p class="field-hint">These appear as flags on the waveform during
          the test. Clicking one jumps the playhead there.</p>
      </div>

      <div class="slot-actions">
        <button class="slot-save">Save</button>
        <button class="slot-choose secondary">Replace audio</button>
        <input type="file" class="slot-upload" accept="audio/*">
        <span class="slot-status"></span>
      </div>

      ${preview}
    </div>`;
}

function wireSlot(song) {
  const el = document.querySelector(`.slot[data-key="${CSS.escape(song.sampleKey)}"]`);
  if (!el) return;

  const status = el.querySelector(".slot-status");
  const fileInput = el.querySelector(".slot-upload");

  const say = (text, kind = "") => {
    status.textContent = text;
    status.className = "slot-status" + (kind ? " " + kind : "");
  };

  // The real input is hidden because file pickers cannot be styled. The
  // visible button forwards the click.
  el.querySelector(".slot-choose").addEventListener("click", () => {
    fileInput.click();
  });

  el.querySelector(".slot-save").addEventListener("click", () => {
    saveSlot(el, song, say);
  });

  fileInput.addEventListener("change", () => {
    if (fileInput.files.length) uploadForSlot(el, song, fileInput.files[0], say);
  });
}

/**
 * Save the title, section and current file path for one slot.
 *
 * The path is whatever the row already had unless an upload changed it —
 * the field is not editable by hand on purpose. A typed path is a typo
 * waiting to become silence in a listening test, and the endpoint would
 * reject a missing file anyway.
 */
async function saveSlot(el, song, say) {
  const title = el.querySelector(".slot-title").value;
  const section = el.querySelector(".slot-section").value;
  const markers = el.querySelector(".slot-markers").value;

  say("Saving...");

  try {
    const res = await sendWithCsrfRetry(token => fetch(ADMIN_API.songs, {
      method: "PUT",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
      body: JSON.stringify({
        sampleKey: song.sampleKey,
        title,
        section,
        markers,
        filePath: el.dataset.newPath || song.filePath,
        isActive: true,
      }),
    }));

    const data = await res.json();
    if (!res.ok) {
      say(data.error || "Could not save.", "bad");
      return;
    }

    say("Saved.", "ok");
    // Reload rather than patch the DOM. The server may have tidied the
    // title, and the file and warning state can both have changed; asking
    // again is shorter than replaying those rules in the browser.
    setTimeout(loadSlots, 700);
  } catch (err) {
    say("Could not reach the server.", "bad");
    console.error(err);
  }
}

async function uploadForSlot(el, song, file, say) {
  say(`Uploading ${(file.size / 1048576).toFixed(1)} MB...`);

  const form = new FormData();
  form.append("song", file);
  form.append("title", el.querySelector(".slot-title").value);

  try {
    const res = await sendWithCsrfRetry(token => {
      // The token rides in the body rather than a header: this is a
      // multipart POST, and upload-song.php reads $_POST["csrf_token"].
      form.set("csrf_token", token);
      return fetch(ADMIN_API.upload, { method: "POST", body: form });
    });

    const data = await res.json();
    if (!res.ok) {
      say(data.error || "Upload failed.", "bad");
      return;
    }

    // Held on the element rather than saved immediately. Uploading a file
    // and pointing a slot at it are two decisions, and doing the second
    // automatically would make a mis-click irreversible.
    el.dataset.newPath = data.filePath;
    say(data.note || "Uploaded. Press Save to use it.", "ok");
  } catch (err) {
    say("Could not reach the server.", "bad");
    console.error(err);
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  if (await guardAdminPage()) loadSlots();
});
