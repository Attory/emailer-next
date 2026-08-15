const field = document.querySelector('[data-reset-token]');

if (field instanceof HTMLInputElement) {
    const parameters = new URLSearchParams(window.location.hash.slice(1));
    const token = parameters.get('token');
    if (token !== null) {
        field.value = token;
        window.history.replaceState(null, '', window.location.pathname);
    }
}
