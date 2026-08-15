# Emailer Next repository instructions

## Authoritative documentation

Before implementing a development phase, read:

- docs/spec/EMAILER-NEXT-MASTER-SPEC.md
- docs/spec/PHASE-JOBS.md
- docs/spec/CLEAN-ROOM-CHECKLIST.md
- the preceding phase completion report
- relevant ADRs, architecture, threat-model, security and operations documents

The current user instruction authorizes the phase. Repository documentation
defines its bounded implementation scope. Stop and report any material conflict.

## Phase boundary

Implement only the explicitly authorized phase.

Do not introduce routes, business logic, database structures, workers,
integrations or behavior assigned to a later phase.

Every later phase requires separate authorization after the preceding phase is
merged and main is verified clean and synchronized.

## Starting-state verification

Before editing:

1. Fetch origin with pruning.
2. Verify the expected base commit exists locally.
3. Verify it is the current origin/main unless an exact repair base was
   explicitly authorized.
4. Verify the working tree is clean.
5. Create the phase branch from the verified commit, not from a stale local ref.

Stop on any SHA, ancestry, branch or cleanliness mismatch.

## Development autonomy

Within an authorized phase, Codex may autonomously:

- inspect repository files and history;
- edit files within the phase scope;
- install project dependencies using the approved toolchain;
- run tests, static analysis, security scans and Docker Compose validation;
- commit and push the phase branch;
- create and update one draft pull request;
- diagnose CI failures;
- perform bounded repair cycles on the same pull request.

Do not ask Anton to relay routine diagnostic commands or intermediate repair
instructions. Continue through ordinary implementation and CI defects until
checks are terminal and passing or a genuine scope, authority, credential,
infrastructure or security blocker is reached.

## Git and worktree safety

- Never force-push or rewrite published history.
- Never commit directly to main.
- Never discard uncommitted work without explicit authorization.
- Do not remove a worktree until its commit is confirmed on the intended remote
  branch.
- A repair must start from the exact current PR head.
- A changed PR head invalidates any approval for an earlier SHA.

## Security and privacy

- Never use or commit real SMTP credentials, production secrets or recipient
  data.
- Use Mailpit or another explicitly approved fake SMTP service in development
  and CI.
- Preserve secret scanning, private-data scanning, network isolation and
  production-artifact checks.
- Do not weaken a security control merely to make a test pass.
- Do not expose credentials in logs, prompts, reports or command output.

## Quality gates

Run every check required by the current phase and the canonical specifications,
including relevant:

- formatting and whitespace validation;
- static analysis;
- architectural boundary checks;
- unit and integration tests;
- migration and Doctrine mapping checks;
- fake-mail isolation;
- secret and private-data scans;
- artifact and public-path inspection;
- GitHub CI matrix.

Document any check that cannot run and the exact reason.

## Pull requests and repairs

Use one pull request for the authorized phase.

CI repairs must remain within the same phase and PR. Confirm the current PR head
before each repair and monitor only the newest head.

Codex may create, update and repair the draft PR without further approval.

## Merge boundary

Never merge without Anton’s explicit approval of the exact:

- repository;
- phase;
- PR number;
- full current head SHA.

After approval, verify that the approved SHA is still current and all required
checks are passing before merging.

After merge, verify the PR is MERGED and local main is clean, fast-forwarded and
equal to origin/main. Do not begin the next phase automatically.

## Completion record

Before requesting merge approval, report:

- phase number and title;
- branch and base;
- PR number and URL;
- full current head SHA;
- commits and changed files;
- dependencies and migrations;
- local and GitHub CI results;
- security and privacy evidence;
- manual actions;
- unresolved risks;
- confirmation that no later phase was implemented.
