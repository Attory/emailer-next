# Distribution and dependency license policy

**Version:** Emailer Next `1.0.0`

Emailer Next is declared `proprietary` in `composer.json`. Possession of a
release candidate does not by itself grant redistribution rights; the client
distribution agreement and release approval remain operator-controlled.

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
