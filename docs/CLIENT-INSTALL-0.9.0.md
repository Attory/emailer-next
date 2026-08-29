# Install the Emailer Next 0.9.0 acceptance candidate

`0.9.0` contains the complete Phase 13 application for hands-on installation,
browser, accessibility, and operational testing. It is not the public `1.0.0`
release.

## Server prerequisites

- A dedicated Debian/Ubuntu-style Linux server with systemd.
- Nginx, PHP-FPM 8.4 or 8.5, and the PHP extensions checked by the installer.
- MariaDB 10.11 or 11.8 with an empty database and restricted application user.
- A local Mailpit instance on `127.0.0.1:1025` for the setup message.
- DNS for the chosen hostname when testing from another computer.

## Install

Transfer the complete candidate directory to the server. From that directory,
run as root:

```sh
./install-emailer-next-0.9.0.sh
```

The installer prompts for the HTTPS hostname and the protected MariaDB URL. It
accepts existing TLS certificate/key paths or creates a 30-day self-signed test
certificate when both are left blank. It then verifies the checksum and signed
package, installs an immutable release, writes protected runtime configuration,
configures a dedicated PHP-FPM pool and Nginx site, installs the worker and
timers, and starts the application.

Open the printed `/setup` URL. The setup ownership page displays a one-time
code. In the still-protected root terminal run `emailer-setup-proof`, paste that
code, and continue in the same browser session. This one terminal hand-off is
the existing protection against an internet visitor claiming a new server; all
organisation, Owner, MFA, policy, and application setup remains in the browser.

The browser wizard requires the same database details represented by the
protected runtime URL and the local Mailpit endpoint. After completion, sign in
as the Owner, finish MFA, configure production SMTP from the encrypted admin
settings, and work through `docs/accessibility-browser-support.md` from the
release documentation.

Do not use the self-signed test certificate or Mailpit for public operation.
The final `1.0.0` release procedure remains
`CLIENT-INSTALL-1.0.0.md`.

