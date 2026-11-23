// One attempt ID survives retries and reloads; status reads never submit mail.
const form = document.querySelector('[data-campaign-test]');
if (form) {
    const button = form.querySelector('button');
    const feedback = form.querySelector('[data-test-feedback]');
    const requestId = form.querySelector('[name="request_id"]');
    const storageKey = `emailer-campaign-test:${form.dataset.campaignId}:${form.dataset.actorId}`;
    const uuid = /^[0-9a-f-]{36}$/;
    let pending = form.dataset.pending === 'true';
    let busy = false;
    let timer;
    let active = true;
    const remember = value => { try { value ? sessionStorage.setItem(storageKey, value) : sessionStorage.removeItem(storageKey); } catch { /* Durable server state remains authoritative. */ } };
    const setPending = value => {
        pending = value;
        form.dataset.pending = String(value);
        form.setAttribute('aria-busy', String(value));
        button.disabled = value || button.dataset.updateOriginalDisabled === 'true' || document.querySelector('[data-update-status]')?.dataset.blocked === 'true';
        button.textContent = value ? 'Sending…' : 'Send a test to myself';
    };
    const statusUrl = () => form.dataset.statusUrl.replace(/[0-9a-f-]{36}$/, requestId.value);
    const schedule = () => {
        clearTimeout(timer);
        if (pending && active && !document.hidden) timer = setTimeout(poll, 2000);
    };
    const show = data => {
        if (!uuid.test(data.id) || !['pending', 'processing', 'accepted', 'failed'].includes(data.state) || typeof data.message !== 'string' || typeof data.destination !== 'string' || data.terminal !== ['accepted', 'failed'].includes(data.state)) throw new Error('invalid-status');
        requestId.value = data.id;
        feedback.textContent = data.message;
        form.querySelector('[data-test-destination]').textContent = data.destination;
        setPending(!data.terminal);
        remember(data.terminal ? null : data.id);
        if (data.terminal) {
            // Deliberate next click is a new test; a lost-response retry retains the original ID until confirmed.
            requestId.value = crypto.randomUUID();
        }
    };
    const unavailable = response => {
        if (response?.status === 401 || response?.redirected) {
            feedback.textContent = 'Sign in again in another tab, then return here. This test’s status will update automatically; do not submit it again while its outcome is unknown.';
        } else {
            feedback.textContent = 'The test result is temporarily unavailable. This page will check again automatically. Keep it open; do not resend while the outcome is unknown.';
        }
    };
    const read = async (url, options = {}) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(url, {...options, signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            if (response.redirected || response.status === 401 || !response.headers.get('content-type')?.includes('application/json')) { unavailable(response); return; }
            const data = await response.json();
            if (response.ok) { show(data); return; }
            // Only a known pre-dispatch rejection permits a fresh submission. A 503 is unconfirmed.
            if (options.method === 'POST' && [400, 409].includes(response.status) && typeof data.message === 'string') {
                feedback.textContent = data.message;
                remember(null);
                setPending(false);
            } else { unavailable(response); }
        } finally { clearTimeout(timeout); }
    };
    async function poll() {
        if (busy || !pending || document.hidden || !active) return;
        busy = true;
        try { await read(statusUrl()); } catch { unavailable(); } finally { busy = false; schedule(); }
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending || busy) return;
        const body = new FormData(form);
        setPending(true);
        busy = true;
        remember(requestId.value);
        feedback.textContent = 'Sending…';
        try { await read(form.action, {method: 'POST', body}); } catch { unavailable(); } finally { busy = false; schedule(); }
    });
    let retained;
    try { retained = sessionStorage.getItem(storageKey); } catch { /* No secret or address stored in browser storage. */ }
    if (retained && uuid.test(retained)) { requestId.value = retained; pending = true; }
    setPending(pending);
    if (pending) { remember(requestId.value); poll(); }
    document.addEventListener('visibilitychange', () => { clearTimeout(timer); if (!document.hidden) poll(); });
    window.addEventListener('pagehide', () => { active = false; clearTimeout(timer); });
}
