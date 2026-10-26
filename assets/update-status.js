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
