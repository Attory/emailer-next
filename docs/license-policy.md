# Distribution and dependency license policy

## First-party Ripe licensing

This licensed revision of Emailer Ripe first-party code is offered under
**GNU Affero General Public License, version 3 or (at your option) any later
version** (`AGPL-3.0-or-later`), matching Emailer Light. See [LICENSE](../LICENSE).
The grant covers first-party code, installer/build scripts and first-party
materials in this licensed revision for which Soular holds the necessary rights.
Third-party components, font notices and other separately identified materials
retain their own terms; see [THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md).

This is a prospective grant for this revision and subsequent licensed revisions.
It does not relicense all historical versions, alter a previous client agreement,
remove third-party conditions, or grant rights to client data, customer branding
or material outside the licensed source. Making historical source or an unchanged
signed attachment readable on GitHub does not change its original license.

Anton confirmed on 11 November 2026 that Soular controls the necessary rights
for first-party code and bundled materials. Public distribution remains gated
on preservation of third-party permissions and review
of every surface exposed by repository visibility: all history/refs, issues/PRs,
Actions logs/artifacts and releases/attachments. A clean main tree is insufficient.
Any unresolved credential, private-data or redistribution finding blocks visibility.

## Public binary and installer obligations

Use a new release and new signed assets when an existing installer's license,
notices or corresponding source do not match. Never overwrite or re-sign a
historical asset. Use the existing Ripe signing identity and exact committed
source, with the existing signature and digest verification controls.

A public distribution must include LICENSE, THIRD_PARTY_NOTICES.md and an exact
source reference/archive for the runtime and installer scripts, including the
scripts needed to build/install them. Every bundled third-party or historical
package must retain its own notices and applicable source availability. Identify
historical proprietary packages separately; do not imply that this grant applies
to them. Preserve the original font notice alongside documentation.

Provide users interacting remotely with a modified AGPL version a prominent
opportunity to obtain that version's corresponding source, as required by
section 13. Redistributors and service operators must keep their source offer
matched to their deployed changes; the upstream main branch alone is not an
exact source offer for a modified deployment.

## Locked dependencies

The locked production dependency set is accepted only when every package has a
non-empty SPDX-style declaration in the reviewed permissive allowlist:

- `MIT`;
- `BSD-2-Clause`;
- `BSD-3-Clause`;
- `Apache-2.0`;
- `ISC`; or
- `MPL-2.0`.

`tools/quality/check-license-policy.php` parses the production `packages`
section of `composer.lock` and blocks an absent, unknown, or unreviewed license.
Development dependencies are not shipped in the runtime archive. The signed
SPDX 2.3 SBOM lists every locked production package/version/license, and package
inspection rejects `NOASSERTION` license declarations.

Adding or changing a dependency is a release-review event. A new license needs
legal/product approval and an explicit policy change; it must not be silently
accepted by weakening the gate. License acceptance does not replace upstream
notices, attribution obligations, export controls, or the client's contract
review.
