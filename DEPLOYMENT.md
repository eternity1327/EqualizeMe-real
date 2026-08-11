# Letting groupmates use EqualizeME over the internet

This describes how to expose your local XAMPP + Flask setup so groupmates can
register, log in, and use the site from anywhere — not just your home wifi.

## Read this first: what will and won't work remotely

**The listening test audio only plays through the speakers on the machine
running `ai_service.py`.** CamillaDSP is controlled by that service and
outputs to its own local audio device — it does not stream audio to the
browser. So if you host this on your PC, only *you* (physically sitting at
your PC) will hear the A/B samples during the Sound Test. Groupmates
connecting remotely can register, log in, browse Settings/Profile, and view
Recommendations, but the Sound Test itself won't play anything on their end.

If everyone needs to actually take the listening test themselves, each
person needs to run `ai_service.py` + CamillaDSP on their own machine. That's
a bigger architecture change than what's covered here — flag it if it turns
out to matter for your group.

## The two things that need to be reachable

1. **Apache/PHP** (the website itself) — normally `http://localhost/equalizeme-ai/`
2. **Flask** (`ai_service.py`, the API) — normally `http://localhost:5001`

The browser calls both, so both need public URLs.

## Recommended: Cloudflare Tunnel (no port forwarding, no router config)

Cloudflare's tunnel client (`cloudflared`) creates an outbound-only
connection from your PC to Cloudflare and gives you a public HTTPS URL. It
avoids exposing your home IP or messing with router settings.

### 1. Install cloudflared

Download from https://github.com/cloudflare/cloudflared/releases (Windows
64-bit `.msi` or `.exe`), or if you have winget:

```
winget install --id Cloudflare.cloudflared
```

### 2. Start two quick tunnels (no Cloudflare account needed)

Open two terminals and leave both running while people are using the site:

```
cloudflared tunnel --url http://localhost
```

```
cloudflared tunnel --url http://localhost:5001
```

Each prints a random URL like `https://random-two-words.trycloudflare.com`.
Call the first one your **app URL** and the second your **api URL**.

### 3. Point the frontend at your api URL

`js/script.js` currently builds the API address from the page's own
hostname and port 5001, which only works when the app and API share a
hostname (true on localhost/LAN, not true across two separate tunnel URLs).
For a tunnel deployment, hardcode it instead:

Open `js/script.js`, find this line near the top:

```js
const DSP_SERVICE_URL = `${window.location.protocol}//${window.location.hostname}:5001`;
```

Replace it with your api URL from step 2:

```js
const DSP_SERVICE_URL = "https://random-two-words.trycloudflare.com";
```

**Quick tunnel URLs change every time you restart `cloudflared`.** If you
stop and restart the tunnels, update this line again and let groupmates know
the new app URL.

### 4. Lock down CORS on the Flask side

Before starting `ai_service.py`, set `ALLOWED_ORIGIN` to your app URL from
step 2, so only your site can call the API:

```
set ALLOWED_ORIGIN=https://your-app-url.trycloudflare.com
set FLASK_DEBUG=0
python ai_service.py
```

(`FLASK_DEBUG` must stay `0`/unset for anything reachable from the
internet — the Werkzeug debugger allows remote code execution if left on.)

### 5. Share the app URL

Send groupmates the app URL from step 2 (the Apache one, not the API one).
`login.php?tab=register` lets them create their own accounts.

## Simpler alternative: LAN only

If "anywhere" isn't actually required and everyone's on the same wifi (e.g.
a lab session), skip tunnels entirely — share `http://<your-LAN-IP>/equalizeme-ai/`
directly. The existing dynamic-hostname code already handles this case with
no edits needed, and it's the only setup where the Sound Test's audio
question doesn't come up in quite the same way (still only plays on your
machine, but at least everyone's nearby to listen together).

## Do not expose these to the internet

Only Apache (port 80) and Flask (port 5001) should ever go through a tunnel
or port-forward.

- **Never** create a tunnel/ingress rule for MySQL (port 3306) or
  phpMyAdmin. Both assume a trusted local network and are not hardened for
  public exposure.
- Your MySQL `root` user should have a real password before doing any of
  this — XAMPP's default is a blank password, which is fine on localhost but
  not once the app is reachable from outside.
- Keep `FLASK_DEBUG` off (default) whenever the service is reachable
  remotely.

## For a stable, permanent URL instead of quick tunnels

If quick tunnels' changing URLs get annoying, a free Cloudflare account plus
a domain (even a cheap one, or a free subdomain provider) lets you run a
*named* tunnel with a `config.yml` that maps two fixed hostnames — e.g.
`app.yourdomain.com` → port 80 and `api.yourdomain.com` → port 5001 — so the
URLs never change between restarts. Cloudflare's docs at
https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/
walk through named tunnel setup if you want to go that route later.
