const form = document.querySelector('[data-newsletter-composer]');
if (form) {
    form.noValidate = true;
    let current = 0;
    const panels = [...form.querySelectorAll('[data-composer-panel]')];
    const steps = [...form.querySelectorAll('[data-composer-step]')];
    const back = form.querySelector('[data-composer-back]');
    const next = form.querySelector('[data-composer-next]');
    const error = form.querySelector('[data-composer-error]');
    const field = (name) => form.elements.namedItem(name);
    const modes = () => {
        form.querySelectorAll('[data-mode-fields]').forEach((group) => {
            const [name, value] = group.dataset.modeFields.split(':');
            const active = field(`${name}_mode`).value === value;
            group.hidden = !active;
            group.querySelectorAll('input,select,textarea').forEach((input) => {
                input.disabled = !active;
                if (input.hasAttribute('data-required-when-visible')) input.required = active;
            });
        });
        const audience = field('audience_mode').value === 'upload'
            ? `Upload: ${field('list_name').value || 'new list'}`
            : [...form.querySelectorAll('input[name="lists[]"]:checked')].map((input) => input.dataset.listName).join(', ') || 'No lists selected';
        form.querySelector('[data-review-audience]').textContent = audience;
        const selection = field('template_version').selectedOptions[0];
        form.querySelector('[data-review-content]').textContent = field('template_mode').value === 'upload'
            ? field('subject').value || 'New HTML newsletter'
            : selection?.dataset.templateName || 'No template selected';
        const rateLabel = form.querySelector('[data-review-rate]');
        const ceiling = Number(rateLabel.dataset.globalRate);
        const requested = Number(field('messages_per_minute').value) || ceiling;
        rateLabel.textContent = `Up to ${Math.min(ceiling, requested)} messages per minute`;
        const preview = form.querySelector('[data-template-preview]');
        const version = field('template_version').value;
        if (/^[a-f0-9-]{36}$/.test(version)) preview.href = preview.dataset.previewPattern.replace('00000000-0000-0000-0000-000000000000', version);
        else preview.href = preview.dataset.fallbackUrl;
    };
    const valid = (index) => {
        for (const input of panels[index].querySelectorAll('input,select,textarea')) {
            if (!input.disabled && !input.checkValidity()) { show(index); input.reportValidity(); return false; }
        }
        error.hidden = true;
        if (index === 0 && field('audience_mode').value === 'existing' && !form.querySelector('input[name="lists[]"]:checked')) {
            error.textContent = 'Choose at least one recipient list or upload a CSV.';
            error.hidden = false;
            return false;
        }
        if (index === 1 && field('template_mode').value === 'upload' && !field('template_file').files.length && !field('html').value.trim()) {
            error.textContent = 'Upload an HTML file or paste your HTML.';
            error.hidden = false;
            return false;
        }
        return true;
    };
    const show = (index, focus = false) => {
        current = index;
        panels.forEach((panel, i) => panel.hidden = i !== index);
        steps.forEach((step, i) => i === index ? step.setAttribute('aria-current', 'step') : step.removeAttribute('aria-current'));
        back.disabled = index === 0;
        next.hidden = index === panels.length - 1;
        form.querySelector('[data-composer-position]').textContent = `${index + 1} of ${panels.length}`;
        if (focus) {
            panels[index].setAttribute('tabindex', '-1');
            panels[index].focus();
        }
        modes();
    };
    form.querySelector('[data-composer-navigation]').hidden = false;
    form.querySelector('[data-composer-controls]').hidden = false;
    next.addEventListener('click', () => { if (valid(current)) show(current + 1, true); });
    back.addEventListener('click', () => show(Math.max(0, current - 1), true));
    steps.forEach((step, index) => step.addEventListener('click', () => {
        for (let i = 0; i < index; i++) if (!valid(i)) { show(i, true); return; }
        show(index, true);
    }));
    form.addEventListener('input', modes);
    form.addEventListener('change', modes);
    form.addEventListener('submit', (event) => {
        for (let i = 0; i < panels.length; i++) if (!valid(i)) { event.preventDefault(); show(i, true); return; }
    });
    show(0);
}
