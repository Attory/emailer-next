# Administration UX maintenance

Campaign tests show the signed-in user's actual destination before submission.
The POST retains CSRF and Operator authorization, creates one durable test and
waits at most two seconds for the worker result. A pending response is not an
SMTP success. The page reads this specific actor-owned test automatically and
shows waiting, processing, accepted or a safe actionable failure. Acceptance
means the SMTP transport accepted the message; it does not guarantee inbox
placement. Delivery remains CLI-worker only. Existing readiness, hold,
suppression, draft-revision and no-automatic-retry rules continue to apply.

Do not click again when acceptance is uncertain. Keep the page open to recover
its status, sign in in another tab if asked, or have the operator trace the
original test. A per-attempt ID survives a lost response and reload. Replayed
requests and already-pending requests reuse the existing row without dispatching
again; the server serializes creation using the existing campaign row lock.
Status reads require the requesting actor and never enqueue mail. No new schema
or test-address override is introduced. Legacy failures without a specific safe
reason retain honest generic guidance rather than invented SMTP detail.

Settings & tools uses its native summary for pointer, Enter and Space toggles.
Clicking outside closes it; links inside retain their normal behavior. Escape
closes the menu and returns focus to its summary.

Create new is immediately before the Lists heading. Its dialog accepts a name,
an optional CSV (headered or headerless), and zero or more source lists. Use
Ctrl-click on Windows/Linux or Cmd-click on macOS, the native mobile picker or
the synchronized checkbox alternative. Sources are bounded to 100 lists.
Overlapping memberships are copied once into the new list; sources and global
unsubscribe state are not modified. CSV upload opens the existing column mapping,
preview counts and row-numbered rejection report. Review these before committing
CSV rows. The new list can remain empty, contain copied memberships only, receive
CSV rows only, or combine both. Cancel before submission makes no change.

A list name opens its management screen, which provides Rename list, Add
contacts and Remove from list, plus the existing Send to, Duplicate, permission-
gated Download CSV and Archive actions. CSV download authorization and recent
confirmation requirements are unchanged. Headings omit decorative trailing
periods; CSV import/Upload labels omit redundant privacy adjectives. Masked
address and export-permission explanations retain their actual distinctions.

Focused verification uses synthetic in-process SQLite fixtures (MariaDB-specific
lock/INSERT/time syntax adapted only in the test wrapper), real Twig rendering,
and the existing local Chrome against stubbed HTTP responses. It verifies
combined overlapping sources plus mapped CSV, duplicates, suppression, invalid
and rejected rows, accepted/pending/failed test results, replay protection,
actor/CSRF boundaries, session-cookie poll protection, menu keyboard/outside
click, dialog selection/focus and lost-response recovery. This is not a MariaDB
concurrency, release, production-mail or broad browser-suite acceptance claim.
