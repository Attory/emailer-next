# Install Emailer Next 0.9.1 for human acceptance

`0.9.1` supersedes the first `0.9.0` walkthrough build and includes the fixes
recorded during that installation. It is the complete pre-public application
for hands-on installation, browser, accessibility, and operational testing. It is not the final public
`1.0.0` release. Use a disposable or recoverable Debian/Ubuntu server with
systemd; do not use live SMTP or production recipient data.

## 1. Prepare DNS and server access

Choose a hostname such as `mailer.example.com`. For access from another
computer, create its DNS A/AAAA record pointing to the server before opening the
browser. DNS is managed outside Emailer Next and cannot be created by the
installer.

Have root SSH access. The installer automatically checks and, where missing:

- identifies the Apache/Nginx process that owns ports 80/443 and preserves its
  address-specific listeners; unrelated services on other ports do not win;
- installs PHP-FPM 8.4 or 8.5 plus required extensions;
- installs MariaDB 10.11 or 11.8;
- installs and verifies loopback-only Mailpit on port 1025; and
- adds the supported PHP/MariaDB package repositories when required.

It uses noninteractive package installation, retains locally modified package
configuration by default, and permits automatic required service restarts.
`NEEDRESTART-SVC` lines are informational, not an Emailer Next failure.

## 2. Locate and transfer the complete candidate directory

The output directory contains:

```text
emailer-next-0.9.1-candidate/
├── emailer-next-0.9.1.zip
├── emailer-next-0.9.1.zip.manifest.json
├── emailer-next-0.9.1.zip.sha256
├── emailer-next-0.9.1.zip.spdx.json
├── emailer-next-0.9.1.zip.sig
├── emailer-next-0.9.1-release.pub
├── install-emailer-next-0.9.1.sh
├── verify-package.php
├── PackageInspector.php
├── RELEASE-CANDIDATE-RECORD.md
├── CLIENT-INSTALL-0.9.1.md
├── 0.9.0-INSTALLATION-WALKTHROUGH.md
└── accessibility-browser-support.md
```

The four files ending in `.manifest.json`, `.sha256`, `.spdx.json`, and `.sig`
are the signed package sidecars. Do not edit or process them manually. The
installer uses all four automatically; keep the complete directory together.

From the workstation, substitute the actual server address:

```sh
scp -r /path/to/emailer-next-0.9.1-candidate \
  root@SERVER_ADDRESS:/root/
```

Or use resumable transfer:

```sh
rsync -av --progress /path/to/emailer-next-0.9.1-candidate/ \
  root@SERVER_ADDRESS:/root/emailer-next-0.9.1-candidate/
```

## 3. Run the root installer

On the server:

```sh
cd /root/emailer-next-0.9.1-candidate
find . -maxdepth 1 -type f -printf '%f\n' | sort
chmod 0755 install-emailer-next-0.9.1.sh
./install-emailer-next-0.9.1.sh
```

The installer prompts for:

1. **HTTPS hostname** — hostname only, for example `mailer.example.com`; do
   not enter `https://` or `/setup`.
2. **Existing application DATABASE_URL** — press Enter to create a local empty
   database and restricted application user. A pasted URL is hidden because it
   contains a password; it is not a request for the MariaDB root password.
3. **MariaDB administrator credentials**, only when the local database must be
   created and socket/distribution maintenance authentication is unavailable.
   These are used only for database/user creation.
4. **TLS certificate and key paths** — leave both empty for a disposable
   30-day self-signed acceptance certificate, or provide both existing paths.

If the installer creates the database, keep the protected terminal open. At
the end it prints Host, Port, Database, Username, and a generated
**application database password** needed once by browser setup. That password
is not the MariaDB administrator password and not the future Owner password.
It is also protected in `/etc/emailer/runtime.env`.

The installer verifies the archive and sidecars before extraction, publishes a
root-owned immutable release, creates a dedicated `emailer` PHP-FPM pool,
configures the active Apache or Nginx server, installs services/timers, and
prints the exact setup URL.

## 4. Open HTTPS setup

Open:

```text
https://mailer.example.com/setup
```

If the hostname is not found, correct the DNS A/AAAA record. If the server is
found but a disposable self-signed certificate is used, the browser will not
trust it automatically; inspect and deliberately accept it only for this test.
Never use the self-signed certificate for public operation.

The installer configures HTTP to redirect to HTTPS. Secret-bearing setup must
not be completed over HTTP.

## 5. Prove server ownership

The page displays a one-time ownership code. In the same protected root
terminal run:

```sh
emailer-setup-proof
```

Paste the code, wait for `Proof installed for Emailer Next`, return to the same
browser session, and select **I installed the proof; verify it**.

Only one challenge exists at a time. It expires after 15 minutes and cannot be
redisplayed in another session. If the original code is gone, wait for expiry
and reload `/setup` for a new challenge.

## 6. Complete browser setup

Follow the pages in order:

1. **Preflight:** every prerequisite must pass.
2. **Database:** enter the restricted application values printed by the root
   installer. Do not use MariaDB root/admin credentials. This verifies the
   connection and installs the schema.
3. **Organisation:** the canonical base URL is the HTTPS origin only, for
   example `https://mailer.example.com`, with no `/setup` or other path.
4. **Owner:** create an individual Owner password.
5. **MFA:** add the provisioning URI or seed to an authenticator app. Enter the
   current six-digit code generated by that app and store the one-time recovery
   codes outside the server.
6. **Mail:** use local Mailpit host `127.0.0.1`, port `1025`, and a synthetic
   sender identity. This captures one setup message locally; it is not
   production SMTP.
7. **Policy:** keep the safe defaults for initial acceptance. Saving them sends
   no campaign.
8. **Final preflight:** complete and permanently close setup.

After completion, `/setup` returns Not Found by design. Continue at:

```text
https://mailer.example.com/login
```

## 7. Refresh Doctor evidence

Sign in as the Owner, open **Doctor**, and select **Run full Doctor now**. Login
uses a lightweight cached check; the full run clears pre-setup findings that
are no longer true.

Expected go-live findings may remain until configured: a recent backup,
external recovery/backup custody, and authenticated peer-verified production
SMTP. These do not mean that the completed Owner or database disappeared.

## 8. Create the first local encrypted backup

Open **Backups** and select **Confirm your identity before creating a backup or
changing backup policy**. Enter:

- the current Owner account password; and
- the current authenticator code or one unused recovery code.

This does not create a new password. Confirmation is purpose-bound to backup
management, lasts five minutes, and applies only to this browser session. After
success the application returns directly to Backups.

For acceptance, leave Destination **Disabled**, scheduled backup unchecked,
and external transfer unchecked. Select **Create and verify local backup**.
Then run the full Doctor again.

The protected CLI alternative is:

```sh
PHP_BIN="$(command -v php8.5 || command -v php8.4)"
systemd-run --quiet --wait --collect --pty \
  --property=User=emailer \
  --property=Group=emailer \
  --property=WorkingDirectory=/srv/emailer/releases/current \
  --property=EnvironmentFile=/etc/emailer/runtime.env \
  "$PHP_BIN" \
  /srv/emailer/releases/current/bin/console \
  emailer:backup:create
```

## 9. Continue human acceptance

On a host where Apache and Nginx both own ports 80/443, the installer stops
instead of guessing. Review which server serves the approved hostname and set
`EMAILER_WEB_SERVER=apache` or `EMAILER_WEB_SERVER=nginx` before rerunning. The
selected server must own both ports when both servers are public. Unidentified
or unsupported port owners also stop provisioning; inspect the host first.

The Database wizard initializes migration metadata after verifying the submitted
restricted credentials. A fresh database needs no manual metadata-sync command.
Incorrect credentials and permanently locked setup cannot initialize or migrate.

After a real isolated restore, use the authenticated receipt procedure in
[backup-restore.md](backup-restore.md) to record its evidence on the originating
installation. A successful archive verification alone does not count as a drill,
and acknowledgement never changes readiness. Plaintext local Mailpit remains an
expected SMTP-critical acceptance configuration and cannot authorize sending.

Work through `accessibility-browser-support.md` in the candidate directory.
Use only synthetic contacts and local Mailpit until production SMTP, DNS,
backup/recovery custody, and the final go-live decision are separately ready.

## Troubleshooting map

- **Package configuration question appears:** stop and retain the existing
  local configuration; use the repaired noninteractive installer on the next
  clean rehearsal.
- **MariaDB administrator authentication failed:** verify the server's actual
  socket/panel authentication or supply an independently created restricted
  application `DATABASE_URL`; do not guess passwords repeatedly.
- **MariaDB system-table definition errors:** the repaired installer preserves
  an already supported MariaDB version and, when it must upgrade an older
  installation, runs `mariadb-upgrade` through the authenticated administrator
  channel before creating the application database. If that step still fails,
  complete the distribution's MariaDB maintenance before production use.
- **Signed package verifier missing/unreadable:** confirm every candidate file
  listed in step 2 is present and use the repaired installer that stages the
  verifier outside root-only home storage.
- **Server/hostname not found:** correct DNS first.
- **`/setup` Not Found before completion:** confirm the hostname reaches the
  web server selected by the installer and that its document root is exactly
  `/srv/emailer/releases/current/public`.
- **Challenge already active:** use the original browser/code or wait 15
  minutes for a new challenge.
- **Ownership proof not accepted:** run `emailer-setup-proof` again with the
  still-visible code and retry from the same browser session.
- **Database step fails:** use the generated restricted application password,
  not MariaDB root or the Owner password.
- **Master-key step fails:** inspect the protected key-directory ownership and
  permissions; do not create or copy a key through the browser.
- **Backup action requests confirmation:** use the Backups re-authentication
  link and complete the action in the same browser within five minutes.

The detailed redacted history behind these entries is in the bundled
`0.9.0-INSTALLATION-WALKTHROUGH.md`; it records the first walkthrough that
produced the `0.9.1` repairs.

### Apache site safety

The acceptance installer joins existing virtual-host groups rather than deriving
hosts from socket addresses. The enabled Apache link is
`/etc/apache2/sites-enabled/zzzz-emailer-next.conf`; the available configuration
remains `/etc/apache2/sites-available/emailer-next.conf`. Retirement must remove
that enabled link before moving the available file. Validation refuses changed
existing default hosts and removes its own new site on failure. Mixed wildcard
and IP groups, wildcard-port groups, scoped or unmatched bindings require
operator review before installation.
