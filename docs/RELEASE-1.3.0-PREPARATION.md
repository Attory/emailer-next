# Ripe 1.3.0 release preparation

This candidate combines the preserved Ripe Apache address-group implementation
with the separately authorized administrator Appearance feature. Ripe 1.2.3 was
an unpublished development candidate; it is not a native update source. Public
Light 1.2.3 remains immutable and is the newest supported Light source.

## Fixed release contract

- Target product: `emailer-ripe`, version `1.3.0`.
- Schema remains `20261015000000`; no database migration is introduced by the
  release-identity change or the filesystem-backed Appearance settings.
- Native Ripe sources: exact authenticated 1.0.6, 1.1.0, 1.2.1 and 1.2.2 packages.
- Light sources: original pinned 1.0.0 and signed 1.1.0, 1.2.0, 1.2.1, 1.2.2 and
  1.2.3 packages under Light's unchanged signing identity.
- No general downgrade and no native source named 1.2.3 or 1.3.0.
- `build-1.3.0-candidate.sh` creates runtime, setup, source and documentation
  artifacts from the eventual reviewed merge commit using the existing external
  Ripe key. Historical candidate builders remain available.
- `combine-1.3.0-guides.php` authenticates the published Light 1.2.3 documentation
  and runtime before combining it with Ripe's separately verified guides. The
  combined archive is private; no Ripe source or documentation goes into Light.

## Release gates still required

Earlier installer-candidate results are recorded in
`RELEASE-1.2.3-IMPLEMENTATION.md`; they do not establish acceptance of this new
integrated candidate. Complete the Appearance implementation, review and actual
screenshots; rerun the required application and installer CI matrix; then build
and test the exact merged original-key artifacts. Native hosting, interruption,
recovery, backups, source-version upgrades and no-unintended-send checks must use
those final packages. Publish no immutable tag before these checks pass.

No production host, application, DNS or mail transport is used for this work.
