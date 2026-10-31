// Standalone asset: no import-map bootstrap is required on administration pages.
// Keep the editor tab (including selected files) intact while signing in separately.
async function authenticationExpired(response) {
    const target = new URL(response.url || location.href, location.href);
    const loginRedirect = response.redirected && target.origin === location.origin && target.pathname.endsWith('/login');
    if (response.status !== 401 && !loginRedirect) return false;
    let reason = target.searchParams.get('expiry');
    let login = new URL('/login', location.origin);
    if (loginRedirect) login = target;
    if (response.status === 401) {
        try {
            const body = await response.clone().json();
            reason = body.expiry;
            const supplied = new URL(body.login_url, location.href);
            if (supplied.origin === location.origin && supplied.pathname.endsWith('/login')) login = supplied;
        } catch { /* An unstructured 401 still requires sign-in, never a delivery diagnosis. */ }
    }
    let notice = document.querySelector('[data-authentication-expired]');
    if (!notice) {
        notice = document.createElement('section');
        notice.dataset.authenticationExpired = 'true';
        notice.className = 'alert';
        notice.setAttribute('role', 'alert');
        (document.querySelector('main') || document.body).prepend(notice);
    }
    const message = reason === 'idle'
        ? 'You’ve been signed out due to inactivity. Sign in again to continue.'
        : reason === 'absolute'
            ? 'Your session reached its 12-hour limit. Sign in again to continue.'
            : 'Your session is no longer authenticated. Sign in again to continue.';
    login.searchParams.set('return', location.pathname + location.search);
    const link = document.createElement('a');
    link.href = login.href; link.target = '_blank'; link.rel = 'noopener';
    link.textContent = 'Sign in again';
    const campaignRefresh = document.querySelector('[data-campaign-refresh-security]');
    if (campaignRefresh) campaignRefresh.hidden = false;
    const recovery = campaignRefresh
        ? ' in another tab, then return here and choose Refresh security check. Your fields and selected CSV stay here.'
        : document.querySelector('[data-template-editor]')
            ? ' in another tab, then return here. Keep this tab open; after sign-in, reload and choose Restore draft to recover your unsaved template.'
            : ' in another tab, then return here to continue.';
    notice.replaceChildren(document.createTextNode(message + ' '), link, document.createTextNode(recovery));
    return true;
}

function authenticationRestored() {
    document.querySelector('[data-authentication-expired]')?.remove();
}


// An expired background poll must not replace Symfony's post-login page target.
// Poll only bounded, authenticated status. Never reload a form or replace its contents.
const banner = document.querySelector('[data-update-status]');
if (banner) {
    let timer;
    let busy = false;
    let stopped = false;
    let failures = 0;
    let last = {blocked: banner.dataset.blocked === 'true', message: banner.textContent.trim(), paused_campaign_ids: []};
    const originals = new WeakMap();
    const apply = status => {
        last = status;
        banner.hidden = !status.blocked;
        if (banner.textContent !== status.message) banner.textContent = status.message;
        document.querySelectorAll('[data-update-send]').forEach(button => {
            if (!originals.has(button)) originals.set(button, button.dataset.updateOriginalDisabled === 'true' || (button.disabled && button.dataset.updateOriginalDisabled !== 'false'));
            button.disabled = status.blocked || originals.get(button);
            button.classList.toggle('update-disabled', status.blocked);
            button.setAttribute('aria-disabled', String(button.disabled));
            button.setAttribute('aria-describedby', 'update-send-explanation');
        });
        document.querySelectorAll('[data-update-send-explanation]').forEach(node => {
            node.hidden = !status.blocked;
            if (node.textContent !== status.message) node.textContent = status.message;
        });
        document.querySelectorAll('[data-update-campaign]').forEach(node => {
            const state = node.parentElement.querySelector('[data-campaign-state], [data-state]')?.dataset.state;
            node.hidden = !status.blocked || state !== 'sending' || !status.paused_campaign_ids.includes(node.dataset.updateCampaign);
        });
    };
    const unknown = {blocked: true, phase: 'unknown', message: 'Current update state is unavailable. Sending is disabled until it can be verified; your unsaved work is retained.', paused_campaign_ids: []};
    const poll = async () => {
        if (busy || stopped || document.hidden) return;
        busy = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 7000);
        try {
            const response = await fetch(banner.dataset.statusUrl, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal});
            if (await authenticationExpired(response)) return;
            if (response.status === 503 && response.headers.get('X-Emailer-Update-State') === 'in-progress') {
                apply({blocked: true, phase: 'in_progress', message: 'Update in progress; sending temporarily unavailable. Your unsaved work is retained.', paused_campaign_ids: []});
            } else if (response.status === 503 && response.headers.get('X-Emailer-Update-State') === 'failed') {
                const reasons = {
                    host_services: 'Update stopped because the dedicated Emailer services could not start.',
                    host_storage: 'Update stopped because required host files or storage could not be accessed.',
                    verification: 'Update stopped during setup verification.',
                };
                const reason = reasons[response.headers.get('X-Emailer-Update-Reason')] || 'Update stopped before setup completed.';
                apply({blocked: true, phase: 'failed', message: reason + ' Sending remains blocked. Start the same setup file and use Finish or Recover as indicated. Your unsaved work is retained.', paused_campaign_ids: []});
            } else {
                if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('unavailable');
                const status = await response.json();
                if (typeof status.blocked !== 'boolean' || typeof status.message !== 'string' || !Array.isArray(status.paused_campaign_ids) || !status.paused_campaign_ids.every(id => typeof id === 'string')) throw new Error('invalid');
                authenticationRestored();
                apply(status);
            }
            failures = 0;
        } catch {
            failures = Math.min(failures + 1, 4);
            apply(unknown);
        } finally {
            clearTimeout(timeout);
            busy = false;
            if (!stopped) timer = setTimeout(poll, Math.min(30000, 5000 * (failures + 1)));
        }
    };
    apply(last);
    poll();
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden) poll();
    });
    window.addEventListener('pageshow', () => { stopped = false; clearTimeout(timer); poll(); });
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
    // Existing progress polling can confirm that the in-flight last message completed.
    const progress = document.querySelector('[data-campaign-state]');
    if (progress) new MutationObserver(() => apply(last)).observe(progress, {attributes: true, attributeFilter: ['data-state']});
}
