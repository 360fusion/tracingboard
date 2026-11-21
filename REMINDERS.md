# Reminders

Things to do outside the code, to tick off later. Update this file when something changes.

## Server (staging / production)

- [ ] Add the scheduler cron: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` (event payment reminders run daily at 09:00).
- [ ] Set up mail: a real `MAIL_MAILER`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. Sign-up email confirmation only starts once a real driver is set.
- [ ] Run `php artisan migrate` (adds guest emails, PayPal columns and the editable event email templates).
- [ ] After migrating, open Superadmin, Email templates, and check the event templates read the way you want.

## Move staging to PostgreSQL (one staging server, PostgreSQL installed by hand)

Decided 27 Oct 2026. Production is meant to run on PostgreSQL, and local dev already does (16.15). Staging (Forge server "staging", the droplet at 167.99.193.125) runs MySQL 8.4.8 shared with the other apps (panoramr, support, tenant1..N), so it does not test what production will run. Only one staging server is wanted, and Forge allows one database engine per server, so PostgreSQL goes on the same droplet by hand. The other apps stay on MySQL and are not touched.

Why it matters: `protect_financial_records_from_cascade_deletes` and `drop_status_check_constraint_from_accounting_bills` only act on PostgreSQL, so on MySQL the cascade protection on financial rows is not active.

Before starting: back up every database on the server (they are all shared), pick a quiet time, and check there is spare memory and disk.

- [ ] Add your SSH key in Forge, then connect: `ssh forge@167.99.193.125`. `sudo` on this server asks for the forge user's password (it is not passwordless) and Forge does not store it. Without it, use DigitalOcean's droplet console (droplet, Access tab, Launch Droplet Console), which logs in as root with no password: run the same commands without `sudo`, and set a password with `passwd forge`. Keep the password out of chats.
- [ ] Check space: `free -m; df -h /` (27 Oct 2026: 7.9 GB RAM with about 5.4 GB available, 133 GB disk free, so there is room)
- [ ] Install PostgreSQL (Ubuntu 24.04 ships version 16) and the PHP driver: `sudo apt update && sudo apt install -y postgresql postgresql-contrib php8.4-pgsql`
- [ ] Restart PHP-FPM so it loads the driver: `sudo systemctl restart php8.4-fpm`
- [ ] Create the user (it asks for a password; make it long and random, keep it in a password manager): `sudo -u postgres createuser --pwprompt club_manager`
- [ ] Create the database, owned by that user: `sudo -u postgres createdb --owner=club_manager club_manager`
- [ ] In Forge, open the site's Environment page and set `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=club_manager`, `DB_USERNAME=club_manager`, `DB_PASSWORD=<the password>`
- [ ] Deploy, then in the site's Commands tab run `php artisan migrate --force` (the database starts empty; the old data stays in MySQL `managerdev`, unused)
- [ ] Check it: `php artisan db:show` should say PostgreSQL 16
- [ ] Recreate the staging clubs you need (or say which data to carry over and it can be exported through the app)
- [ ] Do not open port 5432 in the Forge firewall. Forge will not back this database up: decide whether staging data needs a scheduled `pg_dump`
- [ ] Later: drop the unused `managerdev` database on MySQL

Optional second layer: add a PostgreSQL job to CI (GitHub Actions) so the test suite also runs on PostgreSQL. The tests normally use SQLite, which hides PostgreSQL-only problems.

## Server software that is behind (staging, checked 27 Oct 2026)

Not urgent; most of it goes away if the server is rebuilt, but worth knowing:

- [ ] nginx 1.28.1 comes from the Ondrej Sury package source (the one Forge uses). A second source, nginx.org mainline (`/etc/apt/sources.list.d/nginx.list`), offers 1.31.8, so a plain `apt upgrade` would swap packagers and jump to mainline and could break Forge's site configs. Run `apt-mark hold nginx` before any `apt upgrade` (checked 27 Oct 2026). The 1.28 line reached end of life in April 2026; look at the Nginx tab on Forge's Runtime page before upgrading by hand.
- [x] OS updates applied on 27 Oct 2026 (droplet snapshot first, then `apt upgrade` as root in the DigitalOcean console): 74 packages upgraded including MySQL 8.4.13, Redis 8.10.3 and Docker 29.9.2; all five sites answered afterwards. nginx is deliberately on hold (`apt-mark hold nginx`, still 1.28.1); only run `apt-mark unhold nginx` when you mean to upgrade it, and see the nginx note above. A reboot is flagged only for apparmor, so it can wait for a quiet moment. Consider switching on DigitalOcean's automated weekly backups for this droplet.
- [x] Node.js on staging moved from 22.23.4 to 24.22.1 on 27 Oct 2026 (NodeSource source switched to `node_24.x` as root in the DigitalOcean droplet console; npm is now 11.19.1). Still to do: redeploy each staging site once and check it loads. For production, set up Node 24 from the start.
- [ ] PHP 8.4.27 is current. cropperjs is on 1.7.0; 2.x is a rewrite of the crop dialog, to do as its own change.

## Go live on tracingboard.app (new Forge server, Cloudflare DNS)

Plan (21 Nov 2026): the app on `tracingboard.app`, DNS on Cloudflare, mail sent by a provider (Cloudflare does not send mail). **Live runs on the staging droplet for now** (Forge site 3513043, branch `main`, push-to-deploy off, zero-downtime; `dev` = staging, merge `dev` into `main` and press Deploy to release). Moving to its own server later is the section below.

Done 21 Nov 2026: Forge site with the deploy script, both queue workers and the scheduler ("Tracing Board scheduler"; Forge job names are unique per server); Cloudflare A records for `@`, `www`, `sites` (DNS only); Let's Encrypt certificate; `.env` set for PostgreSQL `tracingboard`, https, `CLUB_DOMAIN_TARGET`, FCM key (Forge's original kept as `.env.forge-original-2026-11-21`).

Still to do for this setup:
- [x] PostgreSQL user and database `tracingboard` created; first deploy of `bc605a6` ran every migration (21 Nov 2026). The password has special characters, so it is single-quoted in `.env`; keep the quotes if it is ever changed.
- [x] Nightly `pg_dump` (Forge job "Tracing Board database backup", midnight UTC): `~/backups/backup-tracingboard.sh` reads the password from `.env`, writes `~/backups/tracingboard/*.dump` (custom format, 14 days kept). Restore with `pg_restore -h 127.0.0.1 -U tracingboard -d tracingboard --clean <file>`.
- [x] Each dump is also uploaded to Cloudflare R2 bucket `tracingboard-backups` (`database/`), private, with a token limited to that bucket and the server's IPv4/IPv6 (`R2_BACKUP_KEY_ID`/`R2_BACKUP_SECRET` in `.env`). R2 keeps every dump: add a lifecycle rule if it ever matters (about 50 MB a month now).
- [x] Restore tested 21 Nov 2026: dump downloaded from R2, restored into a scratch database, row counts matched live.
- [ ] Failure alert for the nightly job (Forge heartbeat), so a broken backup is noticed.
- [x] Web-push keys made; directory seeded (9,688 lodges, 945 halls); workers restarted; `/up` answers 200.
- [ ] Sign up on https://tracingboard.app, then `php artisan superadmin:grant <your email>` in Forge's Commands tab.
- [x] `SECURITY_CONTACT_EMAIL=security@tracingboard.app` (security.txt live); Cloudflare Email Routing forwards `security@` and `hello@` to ben@360fusion.co.uk (root MX/SPF are Cloudflare's; Mailgun stays on `mail.`). Alerts go to the superadmin while `OPS_ALERT_EMAILS` is empty.
- [x] Superadmin (bennyk20@gmail.com) has two-factor on (21 Nov 2026). Its "Proxies trusted" failure is a false alarm from the command line: nothing sits in front of this server.
- [x] Mail: Mailgun EU domain `mail.tracingboard.app` verified (SPF, DKIM, MX, tracking in Cloudflare; DMARC `p=none` on `tracingboard.app`), SMTP user `app@mail.tracingboard.app`, password single-quoted in `.env`, `MAIL_MAILER=smtp` (21 Nov 2026). Move DMARC to `p=quarantine` after a few clean weeks.

Addresses:
- `tracingboard.app`: the app (members, admin, `/site/{slug}`). `www` forwards to it.
- `sites.tracingboard.app`: what lodges point their own domains at (`CLUB_DOMAIN_TARGET`). **DNS only (grey cloud)**, always.
- `mail.tracingboard.app` (or the provider's suggested name): the sending domain's SPF/DKIM/bounce records.
- Staging stays at `staging.manager.360fusionhosting.co.uk` for now.

Cloudflare:
- [ ] Add `tracingboard.app` to Cloudflare and switch the nameservers at the registrar.
- [ ] Records: `A tracingboard.app → <server IP>`, `CNAME www → tracingboard.app`, `A sites → <server IP>` (grey cloud). Start the main domain grey too, so Forge's Let's Encrypt works; turn the orange cloud on later only if you want Cloudflare's caching/DDoS shield.
- [ ] If the main domain is proxied: SSL/TLS mode **Full (strict)**, `TRUSTED_PROXIES` set to Cloudflare's IP ranges (or `*` with the Forge firewall only allowing Cloudflare on 80/443), and renew the certificate with Cloudflare's Origin Certificate instead of Let's Encrypt.
- [ ] Email Routing (free, receive only): forward `hello@`, `support@`, `security@` and `dmarc@` to your inbox.
- [ ] `.app` is HTTPS-only in every browser (HSTS preload): nothing works over plain http, so get the certificate before testing.

Own server (later):
- [ ] New server on DigitalOcean, London, Ubuntu 24.04, **4 GB RAM / 2 vCPU** at least (website builds run headless Chrome). PHP 8.4, database **PostgreSQL** (a new server can pick it in Forge, unlike staging), Node 24. Turn on DigitalOcean weekly backups.
- [ ] New site `tracingboard.app` (alias `www.tracingboard.app`), repository 360fusion/club-manager, branch `main`, **zero-downtime deploys**. Turn on "Optimize OPcache for production".
- [ ] Deploy script: copy staging's (it runs `artisan optimize`), and make sure it runs `migrate --force`, `npm ci && npm run build`, `storage:link` (once) and `queue:restart`.
- [ ] Install Chrome for Browsershot (`npx puppeteer browsers install chrome` as the site user, plus Chrome's system libraries as root). Without it builds still work, Vue draws the pages.
- [ ] SSL: Let's Encrypt for `tracingboard.app` and `www`.
- [ ] Daemons: `queue:work --queue=transactional,default,bulk --tries=3 --max-time=3600` and `queue:work --queue=sites --tries=3 --timeout=960 --max-time=3600`. Scheduler: `schedule:run` every minute. `DB_QUEUE_RETRY_AFTER=960`.
- [ ] Database backups: Forge's backup feature to DigitalOcean Spaces or S3, daily, and try one restore.
- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tracingboard.app`, new `APP_KEY`, `DB_CONNECTION=pgsql` and its details, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES`, `QUEUE_CONNECTION=database`, `CLUB_DOMAIN_TARGET=sites.tracingboard.app`, `OPS_ALERT_EMAILS`, the mail settings below, `FCM_CREDENTIALS_PATH` (copy the key from staging), APNs keys when the iPhone app ships. Leave `EVENTS_ONLINE_PAYMENTS`/`EVENTS_PLATFORM_PAYMENTS` off until the payment sections above are done.
- [ ] Run `php artisan app:doctor` until it passes (it checks most of the above), make your superadmin account, and point an uptime monitor at `https://tracingboard.app/up`.
- [ ] Seed the directory as on staging (ClubType, GrandLodge, Province, District, MasonicHall, Lodge seeders); do not run the demo seeders.

Email (sending):
- [ ] Pick a provider. Mailgun is already set up for staging (settings in `.env.mailgun-backup-2026-11-21` there), so reuse the account with a new domain `mail.tracingboard.app`; Postmark has the best inbox placement for transactional mail; SES is cheapest at volume.
- [ ] Add the provider's SPF, DKIM and return-path records in Cloudflare (all grey/DNS only), plus `_dmarc.tracingboard.app TXT "v=DMARC1; p=none; rua=mailto:dmarc@tracingboard.app"`; move to `p=quarantine` after a few clean weeks.
- [ ] `.env`: `MAIL_MAILER`, credentials, `MAIL_FROM_ADDRESS=hello@tracingboard.app` (or `noreply@`), `MAIL_FROM_NAME="Tracing Board"`.
- [ ] Test: sign up a new account (confirmation email), "Send test to me" on a newsletter, and check the headers show SPF, DKIM and DMARC pass (Gmail, Show original).

After go-live:
- [ ] Mobile app: `server.url` and `allowNavigation` to `https://tracingboard.app` (see the app section).
- [ ] Stripe Connect redirect and webhook URLs on `tracingboard.app`.

## Email sending (needed for newsletters and all emails now)

Emails are now sent from a **queue**, so a worker must be running or nothing is delivered (booking confirmations, invitations, newsletters, receipts).

- [ ] **Create the queue worker** (a background process that stays running and sends the queued emails). Nothing is delivered without it.
  - [ ] On Forge: Server, Daemons, New Daemon. Command: `php artisan queue:work --queue=transactional,default,bulk --tries=3 --max-time=3600`. Directory: the site's root (the folder containing `artisan`). User: the site user. Processes: 1 to start (add more if newsletters are slow).
  - [ ] A second daemon for website builds and sends, so they never hold up email: `php artisan queue:work --queue=sites --tries=3 --timeout=960 --max-time=3600`. Processes: 1 (each build runs headless Chrome, so this also caps how many run at once; add one when lots of lodges wait in the queue).
  - [ ] Without Forge: create a Supervisor program with the same command (`autostart=true`, `autorestart=true`, `stopwaitsecs=3600`), then `supervisorctl reread && supervisorctl update`.
  - [ ] Add `php artisan queue:restart` to the deploy script, so the worker picks up new code after every deploy.
  - [ ] Confirm `QUEUE_CONNECTION=database` in the server's `.env` (or `redis` if you move to Redis later).
  - [ ] Check it is running: send a booking confirmation or a "Send test to me" newsletter and see it arrive within a minute. If it does not, run `php artisan queue:work --once` on the server and check `storage/logs/laravel.log`, and look at the `failed_jobs` table.
  - [ ] Keep an eye on failures: `php artisan queue:failed` lists them and `php artisan queue:retry all` retries them.
  - [ ] Do the same for **every environment** that sends email: staging as well as production.
- [ ] Choose the mail provider (SendGrid, SES, Postmark or Mailgun) and set `MAIL_MAILER`, its credentials, `MAIL_FROM_ADDRESS` (an address on your sending domain) and `MAIL_FROM_NAME` in `.env`. SendGrid works over SMTP (`MAIL_MAILER=smtp`, host `smtp.sendgrid.net`, user `apikey`, password = the API key).
- [ ] Authenticate the sending domain with the provider once (SPF, DKIM and a DMARC record). Lodges then appear as the display name with their own reply-to, so lodges need no DNS work.
- [ ] Optional: if the provider limits sending speed, set `NEWSLETTER_PER_SECOND` (0 = no limit).
- [ ] Test: send a newsletter to yourself with "Send test to me", then a real one to yourself and a second address, click Unsubscribe in one, and send again to confirm it is skipped.
- [ ] Later: SendGrid's event webhook (bounces, complaints, opens, clicks) can feed a suppression list and reports; not built yet.
- [ ] Consider a policy for visitors' consent: they must actively subscribe, and every newsletter carries an unsubscribe link.

## Card payments (Stripe, lodge's own keys)

- [ ] In Stripe test mode: save the test secret key and webhook secret on a card option (Payment options page).
- [ ] Run `stripe listen --forward-to <site>/webhooks/stripe/<club id>`, make one test booking and pay it, and check it shows Paid with the history entry.
- [ ] Check Apple Pay and Google Pay appear on the Stripe Checkout page in test mode (no lodge account is needed; wallets just need to be on in the Stripe dashboard).
- [ ] Set `EVENTS_ONLINE_PAYMENTS=true` once the above works.

## PayPal

- [ ] Create a PayPal sandbox REST app, copy the client ID and secret, add a webhook (Payment capture completed) and copy its webhook ID.
- [ ] Save them on a PayPal option (mode: Sandbox), make one test booking and pay it, then refund it from the booking.
- [ ] Switch the option to Live with live credentials.
- [ ] Optional: a simple manual "PayPal by email or PayPal.me link" option (organiser ticks people paid) if lodges don't want the full setup.

## Platform-collected payments (Stripe Connect): built, needs setup before it can be switched on

Lodges see "Connect with Stripe" on their card option once these are done. Until then they only see the paste-your-keys option.

- [ ] Enable Stripe Connect on the platform's Stripe account and complete the platform profile (branding, support details).
- [ ] In Stripe, Connect settings, OAuth: turn on OAuth for Standard accounts, copy the client ID (`ca_...`) and add the redirect URI `https://<your-site>/members/payments/stripe-callback`.
- [ ] Add a Connect webhook (listening to **events on connected accounts**) pointing at `https://<your-site>/webhooks/stripe-connect` with `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `account.updated` and `account.application.deauthorized`; copy its signing secret.
- [ ] Set in `.env`: `EVENTS_PLATFORM_PAYMENTS=true`, `PLATFORM_STRIPE_SECRET` (or `STRIPE_SECRET`), `STRIPE_CONNECT_CLIENT_ID`, `STRIPE_CONNECT_WEBHOOK_SECRET`, and the commission `PLATFORM_COMMISSION_PERCENT`, `PLATFORM_COMMISSION_FIXED`, `PLATFORM_COMMISSION_MIN`, `PLATFORM_COMMISSION_MAX`. `EVENTS_ONLINE_PAYMENTS=true` is still needed too.
- [ ] Write the terms between the platform and lodges (fee, chargebacks, refunds, payout timing) and set `PLATFORM_PAYMENT_TERMS_URL` so lodges can read them before they accept.
- [ ] Ask an accountant about VAT/tax on the commission.
- [ ] Test in Stripe test mode with `stripe listen --forward-connect-to <site>/webhooks/stripe-connect`: connect an existing test account, make a booking, pay it, check the booking is Paid and the commission shows on Superadmin, Platform Payments, then refund it.
- [ ] Test "create one for me" (Express) end to end. Payments on Express accounts are created as direct charges; confirm this works in test mode before going live, and tell me if Stripe refuses it (the fix is small).
- [ ] Check which lodge countries Stripe Connect supports (South Africa and India lodges probably keep their own keys), and that each lodge's currency matches its Stripe account.
- [ ] Not built yet, decide later: hold-then-pay-out mode (we hold the money and pay lodges after the event), lodge payout statements, and ledger lines for the commission. Hold mode needs your accountant's and Stripe's agreement first.

## Custom domains for lodge websites (serving and SSL): built in the app, the server still needs Caddy

A lodge saves its domain and adds the DNS record; once Check Now finds it ("Active"), `IdentifyTenantByDomain` serves the lodge's `/site/{slug}` pages at the root of its domain (`lodge.org/about`), `/site/{slug}/...` links on that domain forward to the clean address, and `/login` or `/members` there go to the main address. `/site/{slug}` keeps working unless the lodge ticks "Send visitors to my domain" (Website settings), which forwards it with a 301. Canonical links and the lodge sitemap use the domain once it is Active. `GET /internal/domain-allowed?domain=…` answers Caddy (200 only for an Active domain, and only to callers on the server itself). Decision: **Caddy with on-demand TLS on the Ubuntu droplet** (DigitalOcean, DNS on Cloudflare). Cloudflare for SaaS is the alternative if this grows to hundreds of domains or needs a CDN.

Still to do in the app (not built):

- [ ] Set `CLUB_DOMAIN_TARGET` in each server's `.env` to the hostname lodges point their domain at (defaults to `manager.360fusionhosting.co.uk`).

For now (Forge, a handful of lodges): add each Active lodge domain as an alias on the Forge site (Site → Domains) and issue a Let's Encrypt certificate that includes it (Site → SSL). No Caddy needed. One Let's Encrypt certificate covers all the aliases, so a lodge domain that stops pointing here can make the renewal fail for all of them; move to Caddy or Cloudflare for SaaS before there are many.

Server setup with Caddy (later, outside the code):

- [ ] Install Caddy and PHP-FPM on the droplet; open ports 80 and 443 in the DigitalOcean firewall and `ufw`.
- [ ] Caddyfile: a global `on_demand_tls { ask http://127.0.0.1/internal/domain-allowed }` (so `http://127.0.0.1` needs its own site block running the app, reachable only locally), then a catch-all `https://` block with `tls { on_demand }`, `root * /var/www/<app>/public`, `php_fastcgi unix//run/php/php8.4-fpm.sock`, `file_server`, `encode zstd gzip`. The main domain gets its own normal site block.
- [ ] Static websites without PHP: in the catch-all `https://` block, before `php_fastcgi`, answer pages of lodges that serve their published files straight from disk. The app keeps `storage/app/sites/hosts/<domain>` linked to that lodge's live version only while it is served (`SiteRelease::linkHost`), so anything else falls through to the app as now:
  ```
  @static {
      method GET HEAD
      not query preview=* preview_theme=*
      not path */.*
      file {
          root /var/www/<app>/storage/app/sites/hosts/{host}
          try_files {path}/index.html {path}
      }
  }
  handle @static {
      root * /var/www/<app>/storage/app/sites/hosts/{host}
      rewrite {file_match.relative}
      header /build/* Cache-Control "public, max-age=31536000, immutable"
      # The same headers as StaticSiteBuilder::securityHeaders() (copy them from there if that list changes).
      header {
          Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://plausible.io https://www.googletagmanager.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https://<platform> https://plausible.io https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com; frame-src https:; form-action 'self' https://<platform>; base-uri 'self'; object-src 'none'; frame-ancestors 'self'"
          X-Content-Type-Options nosniff
          X-Frame-Options SAMEORIGIN
          Referrer-Policy strict-origin-when-cross-origin
          Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
          Strict-Transport-Security "max-age=31536000"
      }
      file_server
  }
  ```
  Check after: a page and `/build/...` file come back with no `X-Powered-By: PHP`, `/.htaccess` and `/.clubmanager-manifest.json` are not served, `/events` and the contact form still reach the app.
- [ ] `.env`: `TRUSTED_PROXIES` correct for the setup and `SESSION_SECURE_COOKIE=true`.
- [ ] Cloudflare DNS: the target hostname lodges point at (`manager.360fusionhosting.co.uk` today) must be **DNS only (grey cloud)**, not proxied. If proxied, lodges' CNAMEs hit Cloudflare (error 1014) and the A-record comparison in the DNS check fails. The main domain itself may stay proxied.
- [ ] Test end to end with a spare domain: add the CNAME, press Check Now (Active), open the domain over https (certificate issued on first visit), tick "Send visitors to my domain" and check `/site/<slug>` forwards, send the contact form and make a guest booking on the domain (its session is separate from the main site's), and check `/login` there goes to the main address.

## iOS and Android app (`mobile/`, Capacitor): runs locally, not yet released

- [ ] Server: `php artisan migrate` (adds `push_devices`).
- [ ] Before a store build, in `capacitor.config.json`: set `server.url` to the live https member area and `allowNavigation` to its host, remove `"cleartext": true`, then `npx cap sync`. Remove the `club-manager.test` exception from `mobile/ios/App/App/Info.plist`.
- [ ] iPhone push: an APNs key (.p8) from the Apple developer account, then `APNS_KEY_ID`, `APNS_TEAM_ID`, `APNS_KEY_PATH` (and `APNS_PRODUCTION=true` for App Store builds); set `aps-environment` to `production` in `App.entitlements`.
- [x] Android push: Firebase project `club-manager-7085e` in the Admin Accelerator Google account (ben.kenyon@adminxl.co.uk), Android app `uk.clubmanager.app`, `google-services.json` committed. Service-account key is at `~/.config/club-manager/fcm.json` locally and `/home/forge/keys/fcm-club-manager.json` on staging (both checked with a dry-run send, 20 Nov 2026). Key creation needed the org policies `iam.disableServiceAccountKeyCreation` and `iam.managed.disableServiceAccountKeyCreation` turned off for this project only.
- [ ] Production: copy the same key to the live server and set `FCM_CREDENTIALS_PATH` (the app only asks for push on Android once the server has the key).
- [ ] Android builds made before 20 Nov 2026 use the old 360fusion project `club-manager-5752a` and stop getting push; reinstall on test phones from a new build. Build with Homebrew's JDK (macOS has none on its path): `JAVA_HOME=/opt/homebrew/opt/openjdk@21/libexec/openjdk.jdk/Contents/Home ./gradlew assembleDebug` in `mobile/android`. The old project was shut down on 20 Nov 2026 (restorable from the Google Cloud console as ben@360fusion.co.uk until about 20 Dec 2026); its keys (`fcm-360fusion-old.json` locally and on staging) no longer work and can be deleted.
- [ ] Signing: choose the Apple team in Xcode, and make an Android upload key (keep it safe; it cannot be replaced easily).
- [ ] Store listings, privacy answers and screenshots. Apple may refuse a "website in an app" (guideline 4.2): Face ID unlock and "Add to calendar" would help.

## Later clean-up

- [ ] Drop the unused `event_user` table once the new event pages are verified.
