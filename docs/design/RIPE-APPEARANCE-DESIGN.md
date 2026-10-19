# Ripe administration appearance: design record

Owner: design (Claude, delegated by Anton). Backend, security, integration and
release: Codex. Branch `codex/ripe-dashboard-skins`, base `9887fd0`.

## Scope

Four skins for every authenticated administration screen that extends
`admin/layout.html.twig`: **Classic** (unchanged `app.css`, fallback and default
for existing users), **Studio** (default for fresh installations, chosen by the
backend), **Warm**, and **Website**. Identity, setup and recipient pages extend
`base.html.twig` directly and stay Classic.

| File | Role |
| --- | --- |
| `assets/styles/skins.css` | Tokens per skin, one shared component layer, responsive shell, motion, appearance control |
| `assets/appearance.js` | Dialog controller: preview, revert, save, shared branding refresh/correction |
| `templates/admin/_appearance.html.twig` | Trigger, dialog markup, CSRF/endpoint data attributes |
| `templates/admin/layout.html.twig` | `body_attributes`, `shell_assets`, logo image, includes the control |
| `templates/base.html.twig` | New empty `shell_assets` and `body_attributes` blocks |

## Architecture decisions

1. **Classic is untouched.** No rule targets `body[data-skin=classic]` except
   the appearance control. Unknown or missing values fall back to Classic in
   Twig and JS.
2. **One shell, three token sets.** Studio, Warm and Website set `--sk-*`
   tokens; a single shared layer maps them onto the existing `app.css`
   variables (`--ink`, `--accent`, …) and restyles shared components (header,
   nav, tools menu, panels, tables, forms, buttons, alerts, status pills,
   progress, dialogs, empty states). Signature details per skin are small
   blocks after that layer. No page template was changed.
3. **No cascade layers.** `app.css` is unlayered, so skins win on
   specificity (`body[data-skin]` prefix) and source order.
4. **Assets load from a dedicated `shell_assets` block**, not
   `stylesheets`/`javascripts`. `campaigns`, `campaign`, `users`,
   `mail-settings` and `delivery-settings` replace `javascripts` without
   `parent()`; a script there would vanish on those pages. Load order is
   `app.css` → `skins.css` → token CSS → page blocks.
5. **Email canvas exclusion.** Element-level skin rules carry
   `:where(:not([data-email-canvas] *, [aria-label="Sanitized synthetic HTML preview"] *))`
   (zero specificity). The canvas container resets to Classic variables, font
   and white background. Textareas, which hold newsletter HTML and plain text,
   keep a neutral white surface and the Classic font in every skin; only their
   frame and focus ring follow the skin.
6. **CSP is unchanged.** Only same-origin stylesheet/module links. JS changes
   only `data-skin`/`data-motion` and CSSOM custom properties on `<body>`
   (`style.setProperty`, allowed under `style-src 'self'`). No `innerHTML`,
   no inline `<style>`/`<script>`, no remote fonts or images. The logo is an
   `<img>` rendered only for a local path (`/…`, not `//…`), checked in both
   Twig and JS.

## Skins

**Studio**: restrained, console-like clarity. System UI stack, 600-weight
headings with tight tracking, blue `#0b57d0` (6.4:1 on white), cool grey
surfaces, 1-dp elevation on cards, pill buttons, sentence-case table headers
with tabular numerals and row hover. At 1100px and wider the header becomes a
sticky side rail with an inset active indicator, and the tools menu expands in
place. Motion is limited to 160ms state transitions, the dialog fade/rise and
the tools-menu drop.

**Warm**: welcoming and layered. Terracotta `#a8441c` (6.0:1 on white) with
saffron highlights, cream surfaces, soft radial page gradients, 18–24px radii,
pill controls, a floating translucent header bar, and serif display headings
(local Palatino/Iowan/Georgia) over a rounded system body font. Motion: content
sections rise once on arrival (380ms, stagger capped at 160ms; never blocks
input), alerts and feedback rise into view, cards lift slightly on hover, and
nav items give press feedback. Nothing loops.

**Website**: derives everything from six validated tokens. Links mix primary
toward text for contrast; muted text and lines mix text into surface; status
colours mix fixed hues toward text so they stay distinct on light *and* dark
sites. The header carries a 4px primary→accent strip; the logo (when the
backend provides a local copy) replaces the "en" mark at up to 8rem × 36px with
`object-fit: contain`. Headings use the site font class (sans or serif); body
text stays system sans for data density. The focus ring uses the text colour
so it always contrasts with the background. No pixel-perfect promise is made
in the UI copy.

Status colours in all modern skins: success (green), info (blue), warning
(amber), danger (red), each AA on its tint. Pills always carry text, so state
is never colour-only. `failed` is red and `paused` amber (Classic shows both
amber).

## Appearance control

* "Appearance" pill button in the header on every admin screen. It is hidden
  until the module loads (`hidden`, removed by JS), so no broken control shows
  without JS. Skins still render server-side without JS.
* Native modal `<dialog>` labelled by its heading. It holds four radio cards
  with a CSS-only thumbnail, name and description (arrow keys move between
  them), and motion radios ("Follow my device setting", which shows the live
  device state, or "Reduce motion").
* A choice previews instantly. **Save appearance** is enabled only when the
  preview differs from the saved choice. **Revert preview** restores the saved
  skin, motion and branding colours. **Cancel**, the × button and Escape all
  revert and close. Focus returns to the trigger. Saves are announced in a
  page-level live region.
* No navigation, reload or form submission happens, and the dialog sits
  outside every page form, so unsaved page input survives.
* Errors use `role=alert` inside the dialog, with the backend `{error}` text
  when present, otherwise plain status-based text. Redirects (expired session)
  and network failures are reported as *not confirmed*, never as success.
  The busy button keeps focus and shows a spinner via `aria-busy`.

### Shared website branding (Website skin selected)

* Shows source URL and last refreshed time (`<time datetime>`, local format)
  to every user. Editing requires `ROLE_ADMINISTRATOR` (Twig `is_granted`; the
  backend enforces it again).
* URL field is blank with an `https://www.example.org` placeholder and copy
  telling the administrator not to use the dashboard address. Nothing is
  fetched until the explicit **Fetch from website and save for everyone**
  button is pressed.
* **Manual corrections**: five colour pickers with hex readouts plus a heading
  font choice. These preview on the page only, with a live WCAG readability
  check. They become shared only through **Save corrected branding for
  everyone**.
* The personal skin preview is kept separately. A branding refresh, save or
  error never resets it. Saving a personal appearance while colour
  corrections are unsaved discards them and says so.

## Accessibility and responsive checks (by inspection)

* Focus: 3px ring with 3px offset in every skin. Skin cards show the ring on the
  card when the radio has focus. Skip link stays first and is restyled for
  contrast. `scroll-padding-top` keeps focused items clear of sticky headers.
* Targets: nav, summary, close and trigger are at least 40px tall (44px on
  mobile); form controls keep `app.css`'s 44px.
* ≤760px: the header is no longer sticky, nav wraps into chip rows, and the
  tools menu opens as a full-width sheet so nothing is clipped. ≤480px: the
  dialog becomes a bottom sheet, actions stretch, and the trigger collapses to
  its swatch (label kept for assistive tech). Tables keep `app.css` horizontal
  scrolling; skins never set `overflow` or `display` on tables.
* `prefers-reduced-motion: reduce` (in `app.css`) and `data-motion=reduced`
  (any skin, including Classic) both remove animations and transitions. All
  skin animation is also wrapped in `prefers-reduced-motion: no-preference`.
* `forced-colors`: selected skin card gets a `Highlight` outline, and pills
  gain borders. Print resets the Studio grid and hides the control.

## Requests for Codex (integration)

1. **Routes must exist before merge.** `path('admin_appearance')`,
   `admin_appearance_branding` and `admin_appearance_tokens` are referenced
   by the layout. Twig fails on every admin page until they exist.
2. **`appearance` request attribute** on every admin request. Missing values
   fall back to Classic. For fresh installations, default the skin to
   `studio`; for existing users, `classic`.
3. **Token stylesheet**: scope to `body[data-skin="website"]` and emit:
   `--website-primary|accent|surface|background|text` (validated `#rrggbb`);
   `--website-font` as one of the exact stacks in `assets/appearance.js`
   (`FONT_STACKS`); and, so CSS matches the JS preview,
   `--website-on-primary` (`#ffffff` or `#111111`, whichever has higher WCAG
   contrast on primary) and `--website-scheme` (`dark` when background
   relative luminance < 0.2, else `light`). `--website-logo` is not needed:
   the layout renders `logo_url` as an `<img>`. Serve as `text/css` with the
   admin no-store headers.
4. **Branding response** must contain valid tokens for every colour key. The
   UI treats anything else as "not confirmed". The appearance POST response
   should echo `skin` and `motion` (and may include `branding`).
5. **Session expiry** on JSON endpoints: a 401/403 JSON body is preferable to
   a login redirect. The UI handles both.
6. **Canvas marker (optional, recommended)**: add `data-email-canvas` to the
   `<section aria-label="Sanitized synthetic HTML preview">` in
   `templates/admin/template-preview.html.twig` and to any future HTML
   preview container. The aria-label selector then becomes a fallback.
7. Optional source-contract tests: `skins.css` contains
   `prefers-reduced-motion`, `data-motion=reduced`, `:focus-visible` and
   `outline: 3px`, and `layout.html.twig` contains `shell_assets` and
   `data-skin=`.

## Representative preview

Use synthetic data only (`composer` fixtures / Mailpit dev stack).

1. Start the dev stack and sign in as a synthetic Administrator and a
   synthetic Operator.
2. Open Overview (dashboard grid, quick start, critical Doctor banner if
   seeded), Campaigns (composer stepper, list choices, file input, HTML
   textarea), a campaign (progress, status pills, approval dialog), Contacts
   or Audit (wide table, empty row), Mail settings (fieldsets, Owner dialog),
   and Template preview (email canvas must look identical in every skin).
3. On each, open **Appearance**, cycle all four skins and both motion modes,
   then check: unsaved page inputs are retained; Cancel and Escape restore the
   saved skin; Save persists after reload; an Operator sees branding read-only.
4. As Administrator, select Website with no branding (neutral fallback), then
   enter a test site URL, press Fetch, correct a colour to a low-contrast
   value (readability warning appears), save, and confirm source and refreshed
   time update. Simulate 400/403/429/503 and confirm inputs are kept and the
   error is announced.
5. Repeat at 390px, 768px, 1280px and 200% zoom, keyboard-only, with OS
   reduced motion on and off, and in forced-colours mode.

Rendered screenshots were not produced in the authoring session: the sandbox
denied Node and browser execution. The steps above remain required acceptance.
