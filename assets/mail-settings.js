class MailSettings {
    constructor(form) {
        this.element = form;
        this.confirmed = false;
        this.confirmationExpiresAtValue = Number(form.dataset.mailSettingsConfirmationExpiresAtValue);
        this.dialogTarget = form.querySelector('[data-mail-settings-target="dialog"]');
        this.passwordTarget = form.querySelector('[data-mail-settings-target="password"]');
        this.codeTarget = form.querySelector('[data-mail-settings-target="code"]');
        if (typeof this.dialogTarget.showModal !== 'function') return;
        this.dialogTarget.prepend(form.querySelector('[data-mail-settings-target="confirmation"]'));
        this.passwordTarget.disabled = true;
        this.codeTarget.disabled = true;
        form.addEventListener('submit', event => this.submit(event));
        this.dialogTarget.addEventListener('cancel', event => this.cancel(event));
        this.dialogTarget.querySelector('[data-action="mail-settings#confirm"]').addEventListener('click', () => this.confirm());
        this.dialogTarget.querySelector('[data-action="mail-settings#cancel"]').addEventListener('click', event => this.cancel(event));
    }

    submit(event) {
        if (event.submitter?.hasAttribute('data-mail-connection-test')) return;
        if (Math.floor(Date.now() / 1000) < this.confirmationExpiresAtValue || this.confirmed || typeof this.dialogTarget.showModal !== 'function') return;
        event.preventDefault();
        this.passwordTarget.disabled = false;
        this.codeTarget.disabled = false;
        this.passwordTarget.required = true;
        if (this.dialogTarget.open) {
            this.confirm();
            return;
        }
        this.dialogTarget.showModal();
        this.passwordTarget.focus();
    }

    confirm() {
        if (!this.element.reportValidity()) return;
        this.confirmed = true;
        this.dialogTarget.close();
        this.element.requestSubmit();
    }

    cancel(event) {
        event?.preventDefault();
        this.dialogTarget.close();
        this.passwordTarget.value = '';
        this.codeTarget.value = '';
        this.passwordTarget.disabled = true;
        this.codeTarget.disabled = true;
    }
}

document.querySelectorAll('form[data-mail-settings-confirmation-expires-at-value]').forEach(form => new MailSettings(form));
