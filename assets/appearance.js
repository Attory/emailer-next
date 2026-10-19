// Personal skin preview and shared website branding for the admin shell.
// Never navigates or reloads: page forms keep whatever the administrator typed.
const SKINS = ['classic', 'website', 'studio', 'warm'];
const SKIN_NAMES = { classic: 'Classic', website: 'Website', studio: 'Studio', warm: 'Warm' };
const COLOUR_TOKENS = ['primary', 'accent', 'surface', 'background', 'text'];
const HEX = /^#[0-9a-f]{6}$/i;
const FONT_STACKS = {
    system: 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
    serif: '"Iowan Old Style", "Palatino Linotype", Palatino, Georgia, "Times New Roman", serif',
};

const normaliseTokens = (tokens) => {
    if (!tokens || typeof tokens !== 'object') return null;
    const result = {};
    for (const key of COLOUR_TOKENS) {
        if (typeof tokens[key] !== 'string' || !HEX.test(tokens[key])) return null;
        result[key] = tokens[key].toLowerCase();
    }
    result.font = tokens.font === 'serif' ? 'serif' : 'system';
    return result;
};

const localPath = (value) => typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.includes('\\') ? value : null;

const normaliseBranding = (branding) => ({
    website_url: typeof branding?.website_url === 'string' ? branding.website_url : '',
    refreshed_at: typeof branding?.refreshed_at === 'string' && branding.refreshed_at !== '' ? branding.refreshed_at : null,
    tokens: normaliseTokens(branding?.tokens),
    logo_url: localPath(branding?.logo_url),
});

const isBranding = (value) => value !== null && typeof value === 'object' && typeof value.website_url === 'string' && normaliseTokens(value.tokens) !== null;
const isAppearance = (value) => value !== null && typeof value === 'object' && SKINS.includes(value.skin) && ['system', 'reduced'].includes(value.motion);

const luminance = (hex) => {
    const channels = [1, 3, 5].map((index) => parseInt(hex.slice(index, index + 2), 16) / 255)
        .map((value) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
};
const contrast = (first, second) => {
    const [light, dark] = [luminance(first), luminance(second)].sort((a, b) => b - a);
    return (light + 0.05) / (dark + 0.05);
};
// Prefer recognisable status hues; the validated body text is the safe fallback.
const STATUS_COLOURS = {
    success: ['#146c2e', '#b7efc5'], info: ['#0b57d0', '#cadcff'],
    warning: ['#7a4f01', '#ffe3a3'], danger: ['#b3261e', '#ffd4ce'],
};
const statusColour = (candidates, tokens) => candidates.find((colour) => Math.min(contrast(colour, tokens.surface), contrast(colour, tokens.background)) >= 4.5) ?? tokens.text;
const onColour = (hex) => (contrast(hex, '#ffffff') >= contrast(hex, '#000000') ? '#ffffff' : '#000000');

// CSSOM custom properties only; values are validated hex or fixed stacks, never fetched text.
const applyTokens = (tokens) => {
    const style = document.body.style;
    const names = ['primary', 'accent', 'surface', 'background', 'text', 'font', 'on-primary', 'scheme', 'link', ...Object.keys(STATUS_COLOURS)];
    if (!tokens) {
        names.forEach((name) => style.removeProperty(`--website-${name}`));
        return;
    }
    COLOUR_TOKENS.forEach((key) => style.setProperty(`--website-${key}`, tokens[key]));
    Object.entries(STATUS_COLOURS).forEach(([name, candidates]) => style.setProperty(`--website-${name}`, statusColour(candidates, tokens)));
    style.setProperty('--website-font', FONT_STACKS[tokens.font]);
    style.setProperty('--website-on-primary', onColour(tokens.primary));
    style.setProperty('--website-link', Math.min(contrast(tokens.primary, tokens.surface), contrast(tokens.primary, tokens.background)) >= 4.5 ? tokens.primary : tokens.text);
    style.setProperty('--website-scheme', luminance(tokens.background) < 0.2 ? 'dark' : 'light');
};

const applyLogo = (logoUrl) => {
    const brand = document.querySelector('.admin-header .brand');
    let image = brand?.querySelector('[data-brand-logo-image]');
    if (logoUrl && brand) {
        if (!image) {
            image = document.createElement('img');
            image.className = 'brand-logo';
            image.alt = '';
            image.width = 32;
            image.height = 32;
            image.dataset.brandLogoImage = '';
            brand.prepend(image);
        }
        if (image.getAttribute('src') !== logoUrl) image.src = logoUrl;
        document.body.dataset.brandLogo = 'present';
    } else {
        image?.remove();
        delete document.body.dataset.brandLogo;
    }
};

const formatTime = (value) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
};

const failureMessage = (status) => ({
    400: 'The change was not accepted. Check the values and try again.',
    403: 'You are not allowed to make this change, or this page’s security token has expired. Finish any other work on this page, then reload it and try again.',
    429: 'Too many attempts in a short time. Wait a minute and try again.',
    503: 'This is temporarily unavailable. Nothing was changed; try again shortly.',
}[status] ?? `The change was not saved (error ${status}).`);

const postJson = async (url, payload) => {
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'manual',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
    } catch {
        throw new Error('Emailer Next could not be reached, so the change was not confirmed. Your choices are still here; try again.');
    }
    if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400)) {
        throw new Error('Your session may have ended, so the change was not saved. Sign in again in another tab, then try again here.');
    }
    let data = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }
    if (!response.ok) {
        throw new Error(typeof data?.error === 'string' && data.error.trim() !== '' ? data.error : failureMessage(response.status));
    }
    return data;
};

class Appearance {
    constructor(dialog, trigger) {
        this.dialog = dialog;
        this.trigger = trigger;
        this.token = dialog.dataset.csrfToken ?? '';
        this.canEditBranding = dialog.dataset.canEditBranding === 'true';
        let initial = {};
        try {
            initial = JSON.parse(dialog.dataset.appearanceState || '{}') ?? {};
        } catch {
            initial = {};
        }
        this.saved = {
            skin: SKINS.includes(initial.skin) ? initial.skin : (SKINS.includes(document.body.dataset.skin) ? document.body.dataset.skin : 'classic'),
            motion: initial.motion === 'reduced' ? 'reduced' : 'system',
        };
        this.preview = { ...this.saved };
        this.branding = normaliseBranding(initial.branding);
        this.busy = false;

        this.skinInputs = [...dialog.querySelectorAll('[data-appearance-skin]')];
        this.motionInputs = [...dialog.querySelectorAll('[data-appearance-motion]')];
        this.saveButton = dialog.querySelector('[data-appearance-save]');
        this.revertButton = dialog.querySelector('[data-appearance-revert]');
        this.status = dialog.querySelector('[data-appearance-status]');
        this.error = dialog.querySelector('[data-appearance-error]');
        this.announcer = document.querySelector('[data-appearance-announcer]');
        this.brandingPanel = dialog.querySelector('[data-branding-panel]');
        this.sourceOutput = dialog.querySelector('[data-branding-source]');
        this.refreshedOutput = dialog.querySelector('[data-branding-refreshed]');
        this.urlInput = dialog.querySelector('[data-branding-url]');
        this.tokenInputs = [...dialog.querySelectorAll('[data-branding-token]')];
        this.contrastHint = dialog.querySelector('[data-branding-contrast]');
        this.brandingStatus = dialog.querySelector('[data-branding-status]');
        this.brandingError = dialog.querySelector('[data-branding-error]');
        this.refreshButton = dialog.querySelector('[data-branding-refresh]');
        this.brandingSaveButton = dialog.querySelector('[data-branding-save]');
        this.suggestion = dialog.querySelector('[data-branding-suggestion]');
        this.suggestionButton = dialog.querySelector('[data-branding-use-suggestion]');
        // A proposal only. No request or shared change occurs until an explicit refresh/save.
        const suggestedHost = window.location.hostname.replace(/^(mailer|email|newsletter)\./i, '');
        this.suggestedUrl = /^[a-z][a-z0-9.-]*\.[a-z]{2,}$/i.test(suggestedHost) ? `https://${suggestedHost}/` : '';
        if (this.suggestionButton && this.suggestedUrl) {
            this.suggestionButton.textContent = this.suggestedUrl;
            this.suggestionButton.addEventListener('click', () => {
                this.urlInput.value = this.suggestedUrl;
                this.urlInput.focus();
                this.updateDirty();
            });
        }

        applyTokens(this.branding.tokens);
        this.describeDeviceMotion();

        trigger.addEventListener('click', () => this.open());
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.cancel();
        });
        dialog.querySelectorAll('[data-appearance-cancel]').forEach((button) => button.addEventListener('click', () => this.cancel()));
        this.skinInputs.forEach((input) => input.addEventListener('change', () => this.choose({ skin: input.value })));
        this.motionInputs.forEach((input) => input.addEventListener('change', () => this.choose({ motion: input.value })));
        this.saveButton.addEventListener('click', () => this.save());
        this.revertButton.addEventListener('click', () => this.revert(true));
        if (this.canEditBranding && this.urlInput) {
            this.tokenInputs.forEach((input) => input.addEventListener('input', () => this.previewBranding()));
            this.urlInput.addEventListener('input', () => this.updateDirty());
            this.refreshButton.addEventListener('click', () => this.submitBranding('refresh'));
            this.brandingSaveButton.addEventListener('click', () => this.submitBranding('save'));
        }
        trigger.hidden = false;
    }

    describeDeviceMotion() {
        const hint = this.dialog.querySelector('[data-motion-device-hint]');
        if (!hint || typeof window.matchMedia !== 'function') return;
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const update = () => {
            hint.textContent = query.matches ? 'Your device currently asks for reduced motion.' : 'Your device currently allows animation.';
        };
        update();
        query.addEventListener?.('change', update);
    }

    open() {
        this.clearMessages();
        this.syncInputs();
        this.fillBranding();
        this.updateDirty();
        this.dialog.showModal();
        (this.skinInputs.find((input) => input.checked) ?? this.skinInputs[0])?.focus();
    }

    close(message = '') {
        if (this.dialog.open) {
            this.dialog.close();
            this.trigger.focus();
        }
        if (message && this.announcer) {
            this.announcer.textContent = '';
            window.setTimeout(() => { this.announcer.textContent = message; }, 50);
        }
    }

    apply() {
        document.body.dataset.skin = this.preview.skin;
        document.body.dataset.motion = this.preview.motion;
        if (this.brandingPanel) this.brandingPanel.hidden = this.preview.skin !== 'website';
    }

    syncInputs() {
        this.skinInputs.forEach((input) => { input.checked = input.value === this.preview.skin; });
        this.motionInputs.forEach((input) => { input.checked = input.value === this.preview.motion; });
        if (this.brandingPanel) this.brandingPanel.hidden = this.preview.skin !== 'website';
    }

    choose(change) {
        this.preview = { ...this.preview, ...change };
        this.apply();
        this.error.textContent = '';
        const dirty = this.personalDirty();
        this.status.textContent = dirty
            ? `Previewing ${SKIN_NAMES[this.preview.skin]}${this.preview.motion === 'reduced' ? ' with reduced motion' : ''}. Not saved yet.`
            : 'This is your saved appearance.';
        this.updateDirty();
    }

    personalDirty() {
        return this.preview.skin !== this.saved.skin || this.preview.motion !== this.saved.motion;
    }

    brandingDirty() {
        if (!this.canEditBranding || !this.urlInput) return false;
        const edited = this.readTokens();
        const saved = this.branding.tokens;
        const tokensChanged = edited !== null && (saved === null || [...COLOUR_TOKENS, 'font'].some((key) => edited[key] !== saved[key]));
        return tokensChanged || this.urlInput.value.trim() !== this.branding.website_url;
    }

    updateDirty() {
        const personal = this.personalDirty();
        this.saveButton.disabled = !personal;
        this.revertButton.disabled = !personal && !this.brandingDirty();
    }

    revert(announce = false) {
        this.preview = { ...this.saved };
        this.apply();
        this.syncInputs();
        this.fillBranding();
        applyTokens(this.branding.tokens);
        this.clearMessages();
        if (announce) this.status.textContent = 'Preview reverted to your saved appearance.';
        this.updateDirty();
    }

    cancel() {
        this.revert();
        this.close();
    }

    clearMessages() {
        [this.status, this.error, this.brandingStatus, this.brandingError].forEach((element) => {
            if (element) element.textContent = '';
        });
    }

    // The busy button stays enabled so keyboard focus is not lost; this.busy blocks repeat submissions.
    setBusy(button, busy) {
        this.busy = busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
        [this.refreshButton, this.brandingSaveButton].forEach((control) => {
            if (control && control !== button) control.disabled = busy;
        });
    }

    async save() {
        if (this.busy || !this.personalDirty()) return;
        this.error.textContent = '';
        this.status.textContent = 'Saving your appearance…';
        this.setBusy(this.saveButton, true);
        try {
            const result = await postJson(this.dialog.dataset.appearanceEndpoint, { skin: this.preview.skin, motion: this.preview.motion, _token: this.token });
            if (!isAppearance(result)) throw new Error('Emailer Next returned an unexpected response, so the change was not confirmed.');
            const discarded = this.brandingDirty() ? ' Unsaved website colour corrections were discarded.' : '';
            this.saved = { skin: result.skin, motion: result.motion };
            this.preview = { ...this.saved };
            if (isBranding(result.branding)) this.branding = normaliseBranding(result.branding);
            this.setBusy(this.saveButton, false);
            this.revert();
            this.close(`Appearance saved: ${SKIN_NAMES[this.saved.skin]}${this.saved.motion === 'reduced' ? ', reduced motion' : ''}.${discarded}`);
        } catch (failure) {
            this.setBusy(this.saveButton, false);
            this.status.textContent = '';
            this.error.textContent = `${failure.message} Your preview is still showing and has not been saved.`;
        }
    }

    // Shared branding --------------------------------------------------------

    fillBranding() {
        const { website_url: url, refreshed_at: refreshedAt, tokens } = this.branding;
        if (this.sourceOutput) this.sourceOutput.textContent = url !== '' ? url : 'Not set yet';
        if (this.refreshedOutput) {
            this.refreshedOutput.textContent = refreshedAt ? formatTime(refreshedAt) : 'Never';
            if (refreshedAt) this.refreshedOutput.dateTime = refreshedAt;
            else this.refreshedOutput.removeAttribute('datetime');
        }
        applyLogo(this.branding.logo_url);
        if (!this.canEditBranding || !this.urlInput) return;
        this.urlInput.value = url;
        if (this.suggestion) this.suggestion.hidden = url !== '' || this.suggestedUrl === '';
        const fallback = { primary: '#2f5d9a', accent: '#2f5d9a', surface: '#ffffff', background: '#f5f6f8', text: '#1d232b', font: 'system' };
        this.tokenInputs.forEach((input) => {
            input.value = (tokens ?? fallback)[input.dataset.brandingToken];
        });
        this.describeTokens();
    }

    readTokens() {
        const tokens = {};
        this.tokenInputs.forEach((input) => { tokens[input.dataset.brandingToken] = input.value; });
        return normaliseTokens(tokens);
    }

    describeTokens() {
        this.tokenInputs.forEach((input) => {
            const output = this.dialog.querySelector(`[data-branding-hex="${input.dataset.brandingToken}"]`);
            if (output) output.textContent = input.value.toUpperCase();
        });
        const tokens = this.readTokens();
        if (!this.contrastHint || !tokens) return;
        const warnings = [];
        const check = (label, first, second, minimum) => {
            const ratio = contrast(first, second);
            if (ratio < minimum) warnings.push(`${label} contrast is ${ratio.toFixed(1)}:1 (aim for at least ${minimum}:1)`);
        };
        check('Text on page background', tokens.text, tokens.background, 4.5);
        check('Text on panels', tokens.text, tokens.surface, 4.5);
        check('Button text on primary', onColour(tokens.primary), tokens.primary, 4.5);
        this.contrastHint.hidden = warnings.length === 0;
        this.contrastHint.textContent = warnings.length ? `Readability check: ${warnings.join('; ')}.` : '';
    }

    previewBranding() {
        this.describeTokens();
        const tokens = this.readTokens();
        const readable = tokens && Math.min(contrast(tokens.text, tokens.surface), contrast(tokens.text, tokens.background)) >= 4.5;
        if (readable) applyTokens(tokens);
        this.brandingError.textContent = '';
        this.brandingStatus.textContent = !readable ? 'These colours would be hard to read. The last readable preview is still showing.' : (this.brandingDirty() ? 'Previewing unsaved colour corrections on this page only.' : '');
        this.updateDirty();
    }

    async submitBranding(action) {
        if (this.busy) return;
        const button = action === 'refresh' ? this.refreshButton : this.brandingSaveButton;
        this.brandingError.textContent = '';
        const url = this.urlInput.value.trim();
        let parsed = null;
        try {
            parsed = url !== '' ? new URL(url) : null;
        } catch {
            parsed = null;
        }
        if (url !== '' && (!parsed || parsed.protocol !== 'https:')) {
            this.brandingError.textContent = 'Enter the full website address, starting with https://.';
            this.urlInput.focus();
            return;
        }
        if (action === 'refresh' && url === '') {
            this.brandingError.textContent = 'Enter your organisation’s public website address before fetching.';
            this.urlInput.focus();
            return;
        }
        const tokens = this.readTokens();
        if (action === 'save' && !tokens) {
            this.brandingError.textContent = 'Every colour needs a value before the branding can be saved.';
            return;
        }
        this.brandingStatus.textContent = action === 'refresh' ? `Fetching branding from ${url}…` : 'Saving corrected branding for everyone…';
        this.setBusy(button, true);
        try {
            const result = await postJson(this.dialog.dataset.brandingEndpoint, {
                action,
                website_url: url,
                tokens: tokens ?? this.branding.tokens ?? {},
                _token: this.token,
            });
            if (!isBranding(result)) throw new Error('Emailer Next returned an unexpected response, so the branding change was not confirmed.');
            this.branding = normaliseBranding(result);
            this.setBusy(button, false);
            this.fillBranding();
            applyTokens(this.branding.tokens);
            this.brandingStatus.textContent = action === 'refresh'
                ? `Branding fetched from ${this.branding.website_url} and saved for everyone using the Website skin. You can correct any colour below.`
                : 'Corrected branding saved for everyone using the Website skin.';
            this.updateDirty();
        } catch (failure) {
            this.setBusy(button, false);
            this.brandingStatus.textContent = '';
            this.brandingError.textContent = `${failure.message} Your entries are kept.`;
        }
    }
}

const dialog = document.querySelector('dialog[data-appearance]');
const trigger = document.querySelector('[data-appearance-open]');
if (dialog && trigger && typeof dialog.showModal === 'function') {
    new Appearance(dialog, trigger);
}
