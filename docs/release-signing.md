# Release-signing custody and trust

**Status:** Phase 12 signing evidence is accepted for the unpublished `0.4.0`
candidate. Phase 13 does not reuse that authorization: building an
operator-signed `1.0.0` candidate requires an exact reviewed PR head, unchanged
green required CI, an externally held private key, an independently verified
public key, an empty external output directory, and separate Anton approval.

## Custody boundary

The Ed25519 signing private key is generated and retained on an operator-controlled offline signing workstation or hardware-backed equivalent. It is never generated or stored on a client server, CI runner, web host, database, repository path, release directory, ordinary backup, prompt, ticket, or package. The key file is a regular single-link file with mode `0600`; its encrypted offline recovery copy is kept in a separate protection domain with a tested recovery procedure.

The repository does not create or escrow the production signing private key. `tools/release/build-0.1.0-candidate.sh` accepts an operator-mounted external key, performs two byte-identical builds, verifies the detached signature against the separately supplied public key, and copies only public release outputs. The key value and path are never printed.

## Public-key distribution and pinning

Before installing a package, the client operator obtains the Ed25519 public key and its SHA-256 fingerprint through two independent operator-controlled channels. The operator verifies the fingerprint, stores the key outside every release directory with root/operator ownership and no group/world write permission, and configures `EMAILER_RELEASE_PUBLIC_KEY_FILE` to that exact path. A public key shipped beside an untrusted package is not trusted merely because the two arrived together.

The candidate output includes a convenience copy of the already verified public key for transfer, but trust still comes from the independent fingerprint comparison. Package inspection must succeed before extraction, migration, or pointer switching.

## Rotation and loss

Version 0.1 pins one public key at a time and has no unattended rotation protocol. Planned rotation requires:

1. a bridge release signed by the currently trusted key that announces the next public-key fingerprint;
2. independent operator verification of the new fingerprint;
3. maintenance mode and explicit replacement of the external pinned public-key file;
4. verification of a package signed by the new key before any update; and
5. retention of the old public key and release evidence for the supported rollback window.

If the private key is lost or suspected compromised, stop signing, publishing, and updating. Do not silently replace the client pin. Recovery uses the separately protected signing-key backup; otherwise every affected operator must perform an explicit out-of-band re-trust ceremony. Application master-key recovery is unrelated and cannot recover a release-signing key.

## Supported update window

- `0.1.0` has no earlier production release. Phase 9 rehearses a signed `0.1.0-rc.1` to `0.1.0` transition solely to validate the updater; client installation of the first published release is a clean install.
- `0.2.x` accepts the latest supported `0.1.x` release.
- `0.3.x` accepts the latest supported `0.2.x` release.
- `0.4.x` accepts the latest supported `0.3.x` release.
- `1.0.0` accepts exactly `0.1.0`, `0.2.0`, `0.3.0`, and `0.4.0`; the signed
  manifest carries that explicit allowlist rather than accepting arbitrary
  patch releases within those lines.

An update from an older or unlisted line is rejected until the operator applies each supported intermediate release or follows a separately reviewed migration plan. Downgrades and same-version updates remain prohibited.
