# Incident response

**Status:** Phase 0 baseline; deployment-specific contacts and authority remain
operator-controlled outside this repository.

## Priorities

1. Protect people and stop unauthorized/unwanted sending.
2. Preserve suppression and durable campaign evidence.
3. Contain access without destroying logs or ambiguous delivery state.
4. Preserve redacted, time-bounded evidence.
5. Recover from verified artifacts/backups and document decisions.

Do not paste credentials, tokens, raw addresses/lists, message bodies, private
templates, backups, key material, or unredacted logs into prompts, chat, issues,
screenshots, or CI output.

## General response

1. Record UTC start time, reporter, coarse symptom, affected installation and
   correlation IDs.
2. Stop new campaign queueing; pause affected work through the documented safe
   control when available. Do not assume accepted mail can be retracted.
3. Preserve database/ledger/audit/log and artifact metadata with least access.
4. Classify possible credential, personal-data, integrity, availability, and
   external-provider impact.
5. Apply the smallest reversible containment. Credential rotation, provider
   account action, DNS change, deployment, or public communication requires the
   operator with that authority.
6. Eradicate, recover, run Doctor/quality checks, and monitor.
7. Write a redacted timeline, impact, root cause, recovery proof, and follow-up.

## Specific playbooks

### Secret or private data in Git/artifact/log

- Block merge/release/deployment and further distribution.
- Record paths/commits/artifact identifiers without copying the value.
- Determine whether it was merely present or externally accessible.
- Treat a real credential as compromised; an authorized operator rotates it at
  its provider and replaces the application record.
- Rebuild from clean source and verify full reachable history/artifact/log
  scans. History rewriting or repository deletion is a separately authorized
  destructive action.

### Public path exposure

- Remove public access or take the affected virtual host out of service.
- Preserve server/config/access evidence without collecting recipient content.
- Check `.git`, environments, config, dependencies, logs, backups, private
  storage, and key-path exposure.
- Rotate affected credentials through authorized channels and deploy only a
  verified `public/`-root configuration.

### Unexpected, duplicate, or ambiguous sending

- Pause new claims; preserve in-flight state.
- Check campaign approval, suppression integrity, worker count, ledger
  uniqueness, retries, circuit state, and provider responses.
- Never bulk mark unknown work accepted/failed or requeue ambiguous outcomes.
- Notify decision owners using masked counts and provider identifiers.

### Master-key loss or suspected theft

- Missing/unreadable key: fail closed; do not overwrite it or create a new one
  over existing ciphertext. Locate and verify the separately protected recovery
  package.
- Suspected theft: contain host access and have an authorized Owner rotate the
  master key plus downstream reversible credentials. Database/backup exposure
  changes the scope.

### Suppression integrity failure

- Block all new sends.
- Preserve suppression and delivery tables, recent imports/actions, audit, and
  migrations.
- Restore/repair only through a reviewed procedure that proves suppressions are
  preserved before sending resumes.

## Evidence handling

Use random incident identifiers and masked subjects. Store evidence outside
`public/` with restrictive access/retention. Hash artifacts where appropriate.
Record who accessed/exported evidence. Diagnostics must be redacted before
sharing, even internally.

Deployment-specific notification thresholds, contacts, legal timelines, and
provider procedures are operator policy and must not introduce personal or
secret values into this repository.
