# ADR 0017 — Ripe administration skins and cached public website branding

Status: implemented for the authorised Ripe 1.3.0 release candidate; acceptance
and publication are separate recorded gates.

Ripe keeps Symfony 7.4, Twig and AssetMapper. Three opt-in presentation token
sets (Studio, Warm, Website) share existing administration components. Classic
is the recovery fallback and remains the default for existing installations.
Fresh finalization initializes Studio without rewriting existing preferences.
The Appearance dialog changes CSS state without navigation or form replacement.
Neither newsletter content nor the sanitized email preview follows shell skins.

Configuration owns private filesystem presentation preferences and branding.
This avoids unnecessary changes to Identity tables or the established Light
schema-transition contract. Only opaque actor IDs select personal preference
files; filenames use fixed hashes. Shared branding changes follow existing
Administrator/Owner authorization, CSRF and audit conventions. No route mutates
campaign state, delivery holds, SMTP settings or security level.

The public website reader stores allowlisted hex tokens, a fixed font class and
an optional normalized raster icon. It does not embed source HTML, CSS, SVG,
JavaScript or remote fonts. DNS and every redirect are checked before pinned
TLS connections, with no ambient proxy/credentials. Secondary assets stay on
origin, byte/request/time limits bound extraction, and a separate killable PHP
CLI helper bounds native DNS. Cached configuration serves every dashboard view;
only deliberate Refresh contacts the site. Failure retains the saved theme.

Atomic 0600 configuration writes and directory publication are synchronized with
the global maintenance lock. Network I/O releases that lock, and generation
checking refuses stale refresh publication. An optional BackupContributor
contract marks private configuration roots: encrypted backups preserve 0700/0600
modes and trusted application ownership, including root-run recovery. Restoring
a historical native backup without the appearance prefix clears current
preferences and returns to Classic. Existing template assets retain their public
asset policy. No database migration, general proxy or edition downgrade is added.

Visual design and frontend styling were delegated to the configured local Claude
Code CLI, actual model `claude-opus-5-5`, in a separate worktree from the installer
candidate. Codex owns integration, functional checks and release coordination.
The design record and release acceptance report identify actual screenshots,
review findings and completed checks without treating mockups as acceptance.
