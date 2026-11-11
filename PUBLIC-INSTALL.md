# Emailer Ripe 1.3.12 public fresh-install distribution

This distribution packages runtime **1.3.12** from its explicitly signed source
commit under AGPL-3.0-or-later. The release tag `v1.3.12-public.1` identifies new
public packaging; it does not replace the historical `v1.3.12` runtime assets.

Download `emailer-ripe-1.3.12-public-setup.run` from that official GitHub release.
Obtain the Ripe public signing-key fingerprint through an independent trusted
Soular channel and verify the signed `SHA256SUMS` before running the download.
The installer's embedded key alone is not an independent trust channel.

Use a fresh supported amd64 VPS with systemd: Debian 13 with Nginx, or Ubuntu
22.04/24.04/26.04 with Apache. Follow the [beginner guide](docs/guides/emailer-ripe-install.md)
for DNS, server access, permissions, HTTPS and recovery custody, using this
release's filename in place of the historical filename shown there. Upload this
one file and open an interactive server terminal:

```sh
sudo sh emailer-ripe-1.3.12-public-setup.run
```

This public package deliberately excludes historical application packages. It is
for a fresh installation and resuming that same exact fresh-install operation.
It refuses existing installations or journals belonging to a different package.
It does not provide Light conversion or upgrades of existing Ripe installations.
Retain their original packages, backups and matching upgrade procedures. Do not
delete state or database files to get past a refusal.

SMTP is optional during setup. Emailer needs a separately configured SMTP
delivery service to send campaigns; it does not guarantee inbox placement.

## License, source and verification

The release and installer contain `LICENSE`, `THIRD_PARTY_NOTICES.md`, and the
exact `emailer-next-1.3.12-source.zip`. The signed `installer.json` binds every
installer member, runtime package and source archive. `SOURCE-OFFER.json` gives
the full source commit, GitHub tree URL and source-archive digest. The runtime
manifest records the same source commit; its footer links to that exact tree.
Build/install scripts and the Composer lockfile are included in the source.

The existing Ed25519 Ripe signing identity authenticates the new runtime and
installer; historical signed assets have not been changed. Independently verify
the release's signed checksums, runtime signature, complete installer inventory,
source-archive identity, and matching runtime/installer source before use.

AGPL-3.0-or-later applies to first-party code and cleared first-party materials
in this licensed revision. Third-party components keep their own terms. This
does not relicense all historical versions or amend previous client agreements.
If you modify the program and operate it over a network, provide users access
to your corresponding source as required by AGPL section 13.
