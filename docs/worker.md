# Delivery worker

**Status:** Phase 13 Version-1 acceptance, including readiness gates,
maintenance/key-rotation quiescence, and bounded analytics/RSVP housekeeping.

The worker is a CLI-only Symfony Messenger consumer. No HTTP endpoint can start
delivery. Campaign state survives browser closure and process restart because
the MariaDB ledger, not the worker or browser, is authoritative.

## Preferred profile

A long-running worker under systemd or Supervisor:

- consumes only the asynchronous delivery transport;
- has explicit process user, environment, working directory, and restart policy;
- handles SIGTERM gracefully;
- observes bounded memory/time and configured concurrency;
- records a fixed-interval heartbeat;
- applies global and per-domain limits plus circuit breaker;
- routes exhausted failures to an observable dead-letter state;
- never exposes credentials in command line, environment diagnostics, or logs.

Phase 6 provides tested commands and reviewed service examples. Setup records
whether `PHP_BINARY` and `bin/console` are available for the selected
supervised/cron profile; it does not itself start a worker.

## Restricted cron profile

Where supervision is unavailable, cron may invoke a bounded worker that exits
before the next interval. A lock prevents overlap. This mode has lower
throughput and scheduled delivery may be delayed by up to the cron interval.
Doctor records the mode and gates new sends when heartbeat/scheduler health is
critical.

HTTP/URL cron is prohibited.

## Claim and delivery sequence

1. Claim an eligible identifier-only message.
2. Load its ledger record and conditionally enter processing state.
3. Verify campaign state and immediate global suppression.
4. Load/safely decrypt transport configuration at the adapter boundary.
5. Construct one recipient message with deterministic `Message-ID`.
6. Issue/reuse the scoped unsubscribe token, render its mandatory body/header
   link, and add the RFC 8058 header only for a snapshotted DKIM attestation.
7. Apply rate/circuit controls and recheck suppression immediately before send.
8. Classify result as accepted, transient, permanent, ambiguous, suppressed, or
   cancelled and commit durable state/audit evidence.
9. Retry only eligible transient outcomes within bounded backoff/jitter.

Queue payloads contain no raw address, rendered content, credential, token,
secret, or Doctrine entity.

## Operational semantics

- Pause stops new claims; an in-flight attempt may complete.
- Resume operates only on eligible pending/retry records.
- Cancel marks unclaimed work cancelled and cannot retract accepted mail.
- A transport timeout/disconnect after possible acceptance becomes ambiguous
  according to policy, not silently success or blind retry.
- Dashboard counts are derived from ledger states.

## Health and recovery

Doctor checks worker/scheduler heartbeat, queue age, stuck processing, dead
letters, disk, transport/TLS, rate settings, and circuit state. Worker restart,
concurrent claim, SIGTERM, database loss, provider failure, and ambiguous SMTP
outcomes require fake-service tests before Phase 6 completes.
# Phase 6 runtime commands

The preferred production topology is one or more supervised Messenger workers
plus one scheduler invocation per minute. Messenger messages contain only a
ledger or test-delivery identifier and an operation version. The database is the
source of truth; queued payloads never contain recipient addresses, template
content, credentials, or profile data.

The scheduler also deletes at most 100 recipient-token rows per run after their
365-day validity plus 30-day grace has elapsed. This maintenance never deletes
or weakens global suppression.

```sh
php bin/console messenger:consume async --keepalive=5 --time-limit=3600 --memory-limit=256M --failure-limit=10
php bin/console emailer:scheduler:run
```

The worker handles one recipient per message. Run more processes only up to the
configured concurrency limit. Delivery also enforces a database-backed global
and per-domain rate window across all workers and campaigns. Five consecutive
transport failures open a five-minute circuit. Transient failures use bounded
exponential retry with deterministic jitter; permanent and ambiguous outcomes
are recorded and dead-lettered. “Accepted” means SMTP transport acceptance, not
inbox placement.

Before maintenance, prevent new claims:

```sh
php bin/console emailer:delivery:quiesce
```

Keep the scheduler available for interrupted-claim recovery until
`active_claims=0`, then stop the scheduler and supervised workers gracefully, perform the
maintenance, verify the application expects delivery schema version `1`, then:

```sh
php bin/console emailer:delivery:resume
```

## systemd example

```ini
[Unit]
Description=Emailer Next delivery worker
After=network-online.target mariadb.service

[Service]
Type=simple
User=emailer
WorkingDirectory=/srv/emailer/releases/current
EnvironmentFile=/etc/emailer/runtime.env
ExecStart=/usr/bin/php /srv/emailer/releases/current/bin/console messenger:consume async --keepalive=5 --time-limit=3600 --memory-limit=256M --failure-limit=10
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=120
PrivateTmp=true
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
```

## Supervisor example

```ini
[program:emailer-next-worker]
directory=/srv/emailer/releases/current
command=/usr/bin/php bin/console messenger:consume async --keepalive=5 --time-limit=3600 --memory-limit=256M --failure-limit=10
user=emailer
numprocs=1
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/log/emailer-next-worker.log
```

## Bounded cron fallback

Cron fallback is intended for installations that cannot supervise a process.
The lock-protected scheduler and consumer must both be bounded:

```cron
* * * * * emailer cd /srv/emailer/releases/current && /usr/bin/php bin/console emailer:scheduler:run
* * * * * emailer cd /srv/emailer/releases/current && /usr/bin/php bin/console emailer:operations:run
* * * * * emailer cd /srv/emailer/releases/current && /usr/bin/php bin/console messenger:consume async --keepalive=5 --time-limit=50 --limit=100 --memory-limit=256M --failure-limit=10
```

Use an OS process supervisor where available. Do not invoke delivery from HTTP,
do not run workers as root, and do not point development or CI at a live SMTP
provider.
