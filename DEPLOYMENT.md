# Deploying EqualizeME

Written for **Azure for Students** — $100 credit, no credit card, verified
with a school email. Steps 2 onward are plain Ubuntu and apply to any VPS
(Hostinger, Hetzner, Oracle) if you move later.

**Shared hosting will not work.** `backend/ai_service.py` is a long-running
Flask process, and shared plans give you PHP and MySQL and nothing else.
Without it there is no listening test and no recommendations.

---

## Before you start

Two things first, and neither is optional.

**Rotate the Gmail app password.** The one currently in
`api/config.local.php` has been shown on screen. Delete it at
[myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords),
create a new one, and only ever type it directly into the server's config
file.

**Claim the student credit.** [education.github.com/pack](https://education.github.com/pack)
→ Azure for Students. School email or enrolment documents. No card.

---

## 1. Create the VM (Azure)

Portal → **Virtual machines** → **Create**.

| Setting | Value | Why |
|---|---|---|
| Region | **Southeast Asia** | Singapore. Closest to the Philippines; US regions add ~200 ms |
| Image | **Ubuntu Server 24.04 LTS** | Everything below assumes it |
| Size | **B1ms** (1 vCPU, 2 GB) | ~$15/mo, so ~6 months of credit |
| Authentication | **SSH public key** | Generate one; Azure gives you the private key to download |
| Inbound ports | **SSH (22), HTTP (80), HTTPS (443)** | |

**Not B1s.** It has 1 GB, and MySQL plus Apache plus Flask on 1 GB means
you spend the project tuning `innodb_buffer_pool_size` instead of building.
The extra $6 a month is worth it.

### The free hostname

You do **not** need to buy a domain. On the VM's **Overview** page, next to
the public IP, click **Configure** under DNS name and set a label. You get:

```
equalizeme.southeastasia.cloudapp.azure.com
```

Free, permanent, and certbot issues certificates for it happily. That is
your `base_url`.

### Two Azure-specific traps

**The firewall is in two places.** Azure's Network Security Group sits in
front of the VM, separate from `ufw` inside it. A port has to be open in
both. If the site is unreachable and `ufw status` looks right, the NSG is
what is blocking you.

**Do not turn on Auto-shutdown.** Azure offers it under Operations to save
credit. It stops the VM on a schedule — which for a site that is supposed
to be reachable when your laptop is off defeats the whole point.

### Set a budget alert

Cost Management → Budgets → alert at $80. Azure for Students has a spending
cap and disables the subscription rather than billing you, so there is no
surprise charge — but you want warning before the site simply stops.

```bash
ssh -i ~/Downloads/equalizeme_key.pem azureuser@<your-public-ip>
```

---

## 2. Server basics

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 mysql-server php php-mysql php-curl php-mbstring \
                    python3 python3-pip python3-venv git certbot python3-certbot-apache
sudo mysql_secure_installation
```

Firewall — only HTTP, HTTPS and SSH:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Apache Full'
sudo ufw enable
```

Port 5001 is deliberately **not** opened. The Python service binds to
127.0.0.1 and is reached only by `api/dsp.php` on the same machine. If you
ever find yourself opening that port, something has gone wrong.

## 3. Code

```bash
sudo git clone https://github.com/eternity1327/EqualizeMe-real.git /var/www/equalizeme
cd /var/www/equalizeme
sudo chown -R www-data:www-data /var/www/equalizeme
```

`uploads/` and `logs/` are written to at runtime:

```bash
sudo chmod 775 uploads logs
```

## 4. Database

```sql
CREATE DATABASE equalizeme CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'equalizeme_app'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON equalizeme.* TO 'equalizeme_app'@'localhost';
FLUSH PRIVILEGES;
```

No DDL rights on purpose. The app never creates or alters tables, so it does
not need to, and an SQL injection that slipped through cannot drop anything.
Run migrations as root instead.

Then import the schema and every migration in `sql/`, in this order:

```
password_resets.sql
add_profile_history.sql
add_two_factor.sql
add_email_verification.sql
drop_legacy_assessment.sql   (optional — removes six unused tables)
```

## 5. Config

```bash
sudo cp api/config.example.php api/config.local.php
sudo nano api/config.local.php
```

The four that matter for a public deployment. `base_url` is the DNS
name label you set in step 1 — it must match exactly, including the
`https://` and with no trailing slash:

```php
'environment'  => 'production',
'base_url'     => 'https://equalizeme.southeastasia.cloudapp.azure.com',
'force_https'  => true,
'require_email_verification' => true,
```

`environment => 'production'` turns off on-screen errors, forces HTTPS, and
makes a missing `base_url` a hard failure rather than falling back to the
`Host` header. That fallback is fine on a laptop and a genuine vulnerability
in public — it is what lets an attacker have a real password-reset email
sent to you carrying a link to their server.

Same for the Python side:

```bash
sudo cp backend/config.local.example.json backend/config.local.json
sudo nano backend/config.local.json
```

Both files are gitignored and neither has ever been committed. Keep it that
way — type the passwords in, do not paste them from anywhere they have been
written down.

```bash
sudo chown www-data:www-data api/config.local.php backend/config.local.json
sudo chmod 640 api/config.local.php backend/config.local.json
```

## 6. Apache

```apache
<VirtualHost *:80>
    ServerName equalizeme.southeastasia.cloudapp.azure.com
    DocumentRoot /var/www/equalizeme

    <Directory /var/www/equalizeme>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/equalizeme-error.log
    CustomLog ${APACHE_LOG_DIR}/equalizeme-access.log combined
</VirtualHost>
```

`AllowOverride All` is required — the `.htaccess` at the project root is
what sets the security headers, and Apache ignores it otherwise.

```bash
sudo a2enmod rewrite headers
sudo a2ensite equalizeme
sudo systemctl reload apache2
```

**Set a strict ServerName and no default vhost.** With `AllowOverride` on
and a catch-all vhost, Apache answers to any `Host` header, which is the
other half of the reset-link attack above.

## 7. HTTPS

```bash
sudo certbot --apache -d equalizeme.southeastasia.cloudapp.azure.com
```

Then uncomment the HSTS line in `.htaccess`. Only after the certificate
works — HSTS pins browsers to HTTPS, and turning it on before you can serve
it means visitors get stuck.

## 8. The Python service

```bash
cd /var/www/equalizeme
sudo python3 -m venv .venv
sudo .venv/bin/pip install -r requirements.txt
```

`/etc/systemd/system/equalizeme-dsp.service`:

```ini
[Unit]
Description=EqualizeME DSP service
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/equalizeme/backend
ExecStart=/var/www/equalizeme/.venv/bin/python ai_service.py
Restart=always
RestartSec=5

# Loopback only. Never set this to 0.0.0.0 — the service does no
# authentication of its own and relies entirely on being unreachable
# except from api/dsp.php on this machine.
Environment=DSP_BIND_HOST=127.0.0.1
Environment=FLASK_DEBUG=0

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now equalizeme-dsp
sudo systemctl status equalizeme-dsp
```

`Restart=always` and `enable` are what make this survive a reboot — the
thing shared hosting cannot give you.

## 9. Backups

Real accounts means real data. A daily dump, kept for a fortnight:

`/etc/cron.daily/equalizeme-backup`:

```bash
#!/bin/sh
mysqldump --single-transaction equalizeme \
  | gzip > /var/backups/equalizeme-$(date +%F).sql.gz
find /var/backups -name 'equalizeme-*.sql.gz' -mtime +14 -delete
```

```bash
sudo chmod +x /etc/cron.daily/equalizeme-backup
```

A backup you have never restored is a guess. Try it once on a copy.

---

## Checks before you tell anyone the address

```bash
php api/totp_selftest.php          # all 31 pass
sudo systemctl status equalizeme-dsp
curl -I https://equalizeme.southeastasia.cloudapp.azure.com | grep -i "content-security\|x-frame\|strict-transport"
```

In a browser:

- `http://equalizeme.southeastasia.cloudapp.azure.com` redirects to `https://`
- Register a new account — the confirmation email arrives and the link works
- Log in, take the test, see recommendations
- `https://equalizeme.southeastasia.cloudapp.azure.com/api/config.local.php` returns **403**, not
  your credentials
- `https://equalizeme.southeastasia.cloudapp.azure.com/sql/add_two_factor.sql` returns **403**
- Trigger an error and confirm you get a plain message, not a stack trace

---

## Things this setup does not do

Worth knowing, rather than discovering later.

**Sessions and rate-limit counters live on one machine.** Both use local
files, so this runs on one server and does not scale sideways without
moving them to Redis or the database.

**Uploads have no virus scanning.** Type and size are checked and
`uploads/.htaccess` stops anything executing, but that is not the same as
knowing what a file contains.

**No admin interface.** Unlocking an account that has lost its phone and its
recovery codes is a SQL statement — it is written at the bottom of
`sql/add_two_factor.sql`.

**Registration is open.** Verification proves someone owns an address; it
does not stop a script signing up a thousand throwaway ones. If that starts
happening, the register rate limit is in `api/rate_limit.php`.

**You become responsible for other people's data** the moment strangers can
sign up — email addresses, password hashes, and a record of how they hear.
Keep backups off the server as well as on it, and if the project ends, take
the site down rather than leaving it running unattended.

**The credit runs out.** Roughly six months on a B1ms. When the Azure for
Students balance hits zero the subscription is disabled, not billed — so
the site stops rather than costing you money. Good for your wallet, bad for
anyone mid-signup. Take a final backup before the balance gets low, and if
you are not continuing, shut it down deliberately instead of letting it
lapse.

**The catalogue is squig.link data under academic permission.** The user
agent in `backend/fetch_measurements.py` says so in as many words. A public
site recommending products, with shop links attached, may or may not still
count as academic use — worth asking them before opening registration
rather than after.
