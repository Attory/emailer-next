# Browser support and accessibility acceptance

**Version:** Emailer Next `1.0.2`

## Product support decision

Emailer Next uses server-rendered HTML, progressive enhancement, and web
features classified as Baseline Widely Available. Production has no Node.js or
SPA requirement. The supported browser policy is:

| Client | Supported versions | Acceptance coverage |
| --- | --- | --- |
| Chrome desktop | Current and previous stable major | Core administration, setup, tables, forms, keyboard |
| Edge desktop | Current and previous stable major | Core administration, setup, tables, forms, keyboard |
| Firefox desktop | Current and previous stable major | Core administration, setup, tables, forms, keyboard |
| Safari on macOS | Current and previous major shipped by supported macOS | Core administration, setup, tables, forms, keyboard |
| Safari on iOS/iPadOS | Current and previous major | Recipient actions and responsive administrative review |
| Chrome on Android | Current stable major | Recipient actions and responsive administrative review |

Internet Explorer, obsolete browser majors, embedded webviews, text-only
browsers, and browsers with required security features disabled are not
supported. Security and durable state never depend on JavaScript, so a failed
progressive enhancement cannot authorize a mutation or define campaign state.

The policy is version-relative so it remains meaningful after browser release
dates. A client with a long-lived frozen browser must obtain a separately
reviewed compatibility decision.

## Accessibility target

The product targets WCAG 2.2 Level AA for the implemented administration,
setup, and public recipient surfaces. Relevant controls include:

- semantic landmarks, headings, labels, fieldsets, table-header scope, and
  status/alert roles;
- full keyboard operation and a skip link before repeated admin navigation;
- a high-visibility three-pixel focus indicator and no focus-triggered mutation;
- minimum pointer target sizing for ordinary controls;
- no colour-only success, failure, campaign, or Doctor state;
- text resize and responsive table overflow without loss of operations;
- password-manager/paste compatible authentication without cognitive puzzles;
- reduced-motion preference that suppresses non-essential transitions; and
- explicit irreversible-action and accepted-versus-delivered wording.

Automated checks cover deterministic source/response properties, but cannot
establish accessibility by themselves. Human browser and assistive-technology
acceptance is therefore a release sign-off item, consistent with W3C guidance.

## Blocking automated evidence

`composer check` verifies:

- every Twig table header has explicit row/column scope;
- file inputs and ordinary form controls retain visible labels;
- page language, viewport, landmarks, alert/status roles, and campaign approval
  cues are present;
- focus, responsive, target-size, reduced-motion, and skip-link CSS contracts;
- authorization, CSRF, cache, cookie, public-route, and safe-method behavior;
- server-rendered behavior without relying on client JavaScript.

## Human acceptance checklist

Complete this checklist on the exact release-candidate head with synthetic data
only. Record browser/OS/assistive-technology versions and pass/fail results in
the protected release review system, not in Git when the record identifies a
client or person.

1. In Chrome, Edge, Firefox, and Safari desktop, zoom to 200%. Complete setup,
   login/MFA, contact/list/import preview, template preview, campaign approval,
   Doctor, backup metadata, retention preview, and logout without clipped or
   unreachable controls.
2. Navigate every workflow by keyboard only. Confirm the admin skip link is the
   first focus target, focus is never obscured, order is logical, and every
   action is operable without drag or pointer-only gestures.
3. With VoiceOver on Safari and NVDA or another approved screen reader on a
   supported desktop browser, confirm page/heading/landmark names, form labels,
   validation errors, table headers, status updates, critical Doctor banner,
   and confirmation language.
4. Test forced/high-contrast mode and common colour-vision simulation. Confirm
   success, warning, critical, campaign, and form state remain understandable
   without colour.
5. On iOS Safari and Android Chrome, complete unsubscribe and RSVP; inspect the
   admin dashboard and campaign approval summary in portrait and landscape.
6. Disable JavaScript. Confirm core setup/admin navigation and every security-
   sensitive form still works; no authorization or durable progress is lost.
7. Enable reduced motion. Confirm no required meaning or completion depends on
   animation.
8. Confirm password-manager fill and paste work for login, setup Owner, reset,
   re-authentication, and MFA/recovery entry.

Any critical workflow failure, missing accessible name, keyboard trap,
unreadable error, content loss at 200%, or security behavior that depends on
JavaScript blocks the `1.0.2` candidate. Minor non-blocking findings require an
owner, severity, workaround, and removal target; critical/high findings cannot
be waived.

## Normative references

- W3C WCAG 2.2: https://www.w3.org/TR/WCAG22/
- W3C accessibility evaluation guidance:
  https://www.w3.org/WAI/test-evaluate/
- MDN Baseline browser definition:
  https://developer.mozilla.org/en-US/docs/Glossary/Baseline/Compatibility

## 1.0.2 development evidence

Controlled Chromium checks cover desktop and 390-pixel mobile composition,
draft editing, confirmation cancellation/Escape, visible keyboard focus, live
progress and the no-JavaScript fallback. Polling announces meaningful changes
without repeating timestamps; failures preserve confirmed counts. A synthetic
50-recipient ledger demonstrates 25 processed and 50% progress with fake
transport only. No external browser requests occurred. This evidence does not
substitute for the human browser/assistive-technology checklist above.
