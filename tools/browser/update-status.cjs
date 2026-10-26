const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.EMAILER_PLAYWRIGHT_MODULE || 'playwright-core');
const config = JSON.parse(fs.readFileSync(process.env.EMAILER_BROWSER_CONFIG, 'utf8'));
const credentials = JSON.parse(fs.readFileSync(config.credentials, 'utf8'));
assert.match(config.maintenanceContainer, /^emailer-editor-[a-f0-9]{12}-web$/);
const result = { status: 'RUNNING', cases: [], edition: 'ripe', production_accessed: false };
let browser, phase = 'fixture';
const responses = [];
const runtimeErrors = [];
const fixture = (action, input = {}, root = false) => execFileSync('docker', ['exec', '-i', ...(root ? ['--user', '0:0'] : []), config.maintenanceContainer, 'php', 'tools/browser/update-fixture.php'], { input: JSON.stringify({ action, ...input }), encoding: 'utf8' });
const record = name => result.cases.push({ name, status: 'PASS' });
const banner = page => page.locator('[data-update-status]');
const waitText = async (page, pattern) => { await page.bringToFront(); await banner(page).filter({ hasText: pattern }).waitFor({ timeout: 40000 }); };
const login = async context => {
    const page = await context.newPage();
    page.on('pageerror', error => runtimeErrors.push(error.name));
    page.on('response', async response => {
        if (new URL(response.url()).pathname === '/admin/update-status') {
            try { const value = await response.json(); responses.push({status: response.status(), phase: value.phase, blocked: value.blocked, message: value.message, paused: value.paused_campaign_ids?.length}); } catch { responses.push({status: response.status(), json: false}); }
        }
    });
    await page.goto(config.url + '/');
    await page.waitForURL('**/login');
    await page.locator('[name=login_identifier]').fill(credentials.email);
    await page.locator('[name=password]').fill(credentials.password);
    await page.locator('form button[type=submit]').click();
    await page.waitForURL('**/admin');
    return page;
};
(async () => {
 try {
    const ids = JSON.parse(fixture('seed')).ids;
    const args = ['--no-proxy-server'];
    if (config.port) args.push('--host-resolver-rules=MAP ' + new URL(config.url).hostname + ' 127.0.0.1:' + config.port);
    if (config.spki) args.push('--ignore-certificate-errors-spki-list=' + config.spki);
    browser = await chromium.launch({ executablePath: process.env.EMAILER_CHROMIUM_EXECUTABLE, headless: true, args });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1100 }, reducedMotion: 'reduce' });
    const page = await login(context);
    await page.goto(config.url + '/'); await page.waitForURL('**/admin');
    record('Anonymous root reaches login and authenticated root reaches the permitted dashboard');
    await page.goto(config.url + '/admin/campaigns/' + ids.approved);
    const send = page.getByRole('button', { name: 'Send campaign', exact: true });
    assert.equal(await send.isDisabled(), false);
    const list = await context.newPage(); await list.goto(config.url + '/admin/campaigns');
    const editor = await context.newPage(); await editor.goto(config.url + '/admin/campaigns');
    await editor.locator('[data-campaign-enhanced=true]').waitFor();
    await editor.locator('[data-campaign-editor] [name=name]').fill('Unsaved during update');
    await editor.locator('[data-campaign-editor] [name=messages_per_minute]').fill('17');
    await editor.locator('[data-campaign-editor] [name=recipient_csv]').setInputFiles({ name: 'retained.csv', mimeType: 'text/csv', buffer: Buffer.from('email\nretained@example.test\n') });
    phase = 'open pages receive the real root-owned update hold';
    fixture('hold', {}, true);
    await waitText(page, /paused during update/i);
    assert.equal(await send.isDisabled(), true);
    assert.equal(await send.getAttribute('aria-disabled'), 'true');
    assert(Number(await send.evaluate(node => getComputedStyle(node).opacity)) <= 0.6);
    await waitText(editor, /update/i);
    assert.equal(await editor.locator('[name=name]').inputValue(), 'Unsaved during update');
    assert.equal(await editor.locator('[name=messages_per_minute]').inputValue(), '17');
    assert.equal(await editor.locator('[name=recipient_csv]').evaluate(node => node.files[0].name), 'retained.csv');
    record('Already-open pages announce maintenance and dim and disable Send campaign without reloading unsaved fields or CSV');
    await send.locator('..').screenshot({ path: path.join(config.output, 'emailer-ripe-update-active.png') });
    const sendingBadge = list.locator('[data-update-campaign="' + ids.sending + '"]');
    await list.bringToFront();
    await sendingBadge.waitFor({ timeout: 40000 });
    assert.equal(await list.locator('[data-update-campaign="' + ids.paused + '"]').isHidden(), true);
    assert.equal(await list.locator('[data-update-campaign="' + ids.approved + '"]').isHidden(), true);
    record('Only a previously sending campaign is marked Paused during update; existing pauses and approved campaigns are not relabelled');
    phase = 'direct request is blocked server-side';
    const form = send.locator('..');
    const target = await form.getAttribute('action');
    const token = await form.locator('[name=_token]').inputValue();
    const response = await page.evaluate(async ({target, token}) => {
        const result = await fetch(target, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({_token: token})});
        const document = new DOMParser().parseFromString(await result.text(), 'text/html');
        return {status: result.status, errors: [...document.querySelectorAll('p.alert[role=alert]')].map(node => node.textContent), disabled: document.querySelector('[data-update-send]')?.disabled};
    }, {target, token});
    assert.equal(response.status, 200);
    assert(response.errors.some(message => /update/i.test(message)));
    assert.equal(response.disabled, true);
    record('A valid direct Send POST is rejected by the server with the maintenance reason');
    phase = 'last in-flight campaign completion clears only its badge';
    fixture('completed', {}, true);
    await list.bringToFront();
    await sendingBadge.waitFor({ state: 'hidden', timeout: 40000 });
    assert.equal(await banner(list).isVisible(), true);
    record('An already-open campaign list removes a completed campaign pause badge while maintenance continues');
    phase = 'failed readiness and mobile layout';
    fixture('blocked', {}, true);
    await waitText(page, /SMTP TLS certificate validation failed/);
    await page.setViewportSize({ width: 390, height: 844 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await banner(page).screenshot({ path: path.join(config.output, 'emailer-ripe-update-blocked-mobile.png') });
    record('Failed readiness keeps controls disabled and explains the actual correction on desktop and mobile');
    phase = 'temporarily unavailable status does not lose a form';
    await editor.route('**/admin/update-status', route => route.fulfill({ status: 503, body: 'Scoped maintenance' }));
    await waitText(editor, /Current update state is unavailable/);
    await editor.unroute('**/admin/update-status');
    await editor.route('**/admin/update-status', route => route.fulfill({ status: 503, headers: {'X-Emailer-Update-State': 'in-progress'}, body: 'Scoped maintenance' }));
    await waitText(editor, /Update in progress/);
    assert.equal(await editor.locator('[name=name]').inputValue(), 'Unsaved during update');
    await editor.unroute('**/admin/update-status');
    await editor.route('**/admin/update-status', route => route.fulfill({status: 503, headers: {'X-Emailer-Update-State': 'failed', 'X-Emailer-Update-Reason': 'host_services'}, body: 'Setup stopped'}));
    await waitText(editor, /dedicated Emailer services could not start/);
    assert.equal(await editor.locator('[name=name]').inputValue(), 'Unsaved during update');
    await editor.unroute('**/admin/update-status');
    await editor.route('**/admin/update-status', route => route.fulfill({status: 503, headers: {'X-Emailer-Update-State': 'failed', 'X-Emailer-Update-Reason': 'host_storage'}, body: 'Setup stopped'}));
    await waitText(editor, /host files or storage could not be accessed/);
    assert.equal(await editor.locator('[name=name]').inputValue(), 'Unsaved during update');
    await editor.unroute('**/admin/update-status');
    await waitText(editor, /certificate validation failed/);
    record('Scoped host-service and storage failures remain accurately blocked on already-open pages without losing unsaved fields');
    record('Only the scoped installer header identifies an update; generic HTTP503 stays unknown, preserves work and recovers without false completion');
    phase = 'each permitted role and auditor access';
    for (const role of ['ROLE_OPERATOR', 'ROLE_ADMINISTRATOR', 'ROLE_OWNER', 'ROLE_AUDITOR']) {
        fixture('role', { role });
        const roleContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const rolePage = await login(roleContext);
        await waitText(rolePage, /certificate validation failed/);
        await rolePage.goto(config.url + '/admin/campaigns/' + ids.approved);
        const control = rolePage.getByRole('button', { name: 'Send campaign', exact: true });
        if (role === 'ROLE_AUDITOR') assert.equal(await control.count(), 0);
        else assert.equal(await control.isDisabled(), true);
        const status = await rolePage.evaluate(async () => { const response = await fetch('/admin/update-status', {credentials: 'same-origin'}); return {status: response.status, cache: response.headers.get('cache-control'), value: await response.json()}; });
        assert.equal(status.status, 200);
        assert.match(status.cache, /no-store/);
        assert.equal(status.value.blocked, true);
        await roleContext.close();
    }
    record('Owner Administrator and Operator receive dashboard and campaign notices; Auditor gains no sending control; status is authenticated and no-store');
    fixture('role', { role: 'ROLE_OWNER' });
    // Existing editor sessions remain intact in the DOM even if role changes rotate authority.
    assert.equal(await editor.locator('[name=name]').inputValue(), 'Unsaved during update');
    const lastContext = await browser.newContext(); const lastPage = await login(lastContext);
    await lastPage.goto(config.url + '/admin/campaigns/' + ids.approved);
    fixture('complete', {}, true);
    await banner(lastPage).waitFor({ state: 'hidden', timeout: 40000 });
    assert.equal(await lastPage.getByRole('button', { name: 'Send campaign', exact: true }).isDisabled(), false);
    record('A confirmed completion removes the update notice and restores only controls originally enabled');
    phase = 'expired background polling does not become the next login destination';
    const expiredContext = await browser.newContext();
    const expiredEditor = await login(expiredContext);
    const statusRequest = expiredEditor.waitForRequest(request => new URL(request.url()).pathname === '/admin/update-status');
    await expiredEditor.goto(config.url + '/admin/campaigns');
    const observedStatus = await statusRequest;
    assert.equal(observedStatus.headers()['x-requested-with'], 'XMLHttpRequest');
    await expiredEditor.locator('[data-campaign-enhanced=true]').waitFor();
    await expiredEditor.locator('[name=name]').fill('Retained across expired polling');
    await expiredEditor.locator('[name=recipient_csv]').setInputFiles({name: 'still-retained.csv', mimeType: 'text/csv', buffer: Buffer.from('email\nretained@example.test\n')});
    const expiredSummary = await expiredContext.newPage();
    const progressRequest = expiredSummary.waitForRequest(request => new URL(request.url()).pathname.endsWith('/progress'));
    await expiredSummary.goto(config.url + '/admin/campaigns/' + ids.approved);
    const observedProgress = await progressRequest;
    assert.equal(observedProgress.headers()['x-requested-with'], 'XMLHttpRequest');
    await expiredContext.clearCookies();
    // Reproduce both real poll requests after expiry, with the headers actually
    // emitted by the application. Their redirects must not save a JSON target.
    for (const observed of [observedStatus, observedProgress]) {
        const response = await expiredSummary.evaluate(async ({url, requestedWith}) => {
            const result = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json', 'X-Requested-With': requestedWith}});
            return {redirected: result.redirected, path: new URL(result.url).pathname};
        }, {url: observed.url(), requestedWith: observed.headers()['x-requested-with']});
        assert.equal(response.redirected, true); assert.equal(response.path, '/login');
    }
    const renewed = await login(expiredContext);
    assert.equal(new URL(renewed.url()).pathname, '/admin');
    assert.equal(await expiredEditor.locator('[name=name]').inputValue(), 'Retained across expired polling');
    assert.equal(await expiredEditor.locator('[name=recipient_csv]').evaluate(node => node.files[0].name), 'still-retained.csv');
    await expiredEditor.bringToFront(); await banner(expiredEditor).waitFor({state: 'hidden', timeout: 40000});
    await expiredContext.close();
    record('Expired update and campaign-progress polls cannot become the post-login destination; dashboard login and unsaved fields CSV recover without reload');
    const noSend = JSON.parse(fixture('verify'));
    assert.equal(noSend.no_unintended_send, true); assert.equal(noSend.blocked_requests_created_no_work, true);
    result.no_send = noSend;
    result.status = 'PASS';
    console.log('Ripe live update visibility PASS (' + result.cases.length + ' cases).');
 } catch (error) {
    result.status = 'FAIL'; result.failedPhase = phase; result.errorClass = error.name;
    result.statusResponses = responses.slice(-12); result.runtimeErrors = runtimeErrors;
    if (browser) result.pageStates = await Promise.all(browser.contexts().flatMap(context => context.pages()).map(async page => ({url: new URL(page.url()).pathname, banner: await page.locator('[data-update-status]').textContent().catch(() => null)})));
    console.error('Ripe update visibility failed at ' + phase + ' (' + error.name + ').'); process.exitCode = 1;
 } finally {
    if (browser) await browser.close();
    fs.writeFileSync(path.join(config.output, 'update-browser-result.json'), JSON.stringify(result, null, 2), { mode: 0o600 });
 }
})();
