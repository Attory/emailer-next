const dialog = document.querySelector('[data-list-create-dialog]');
const opener = document.querySelector('[data-list-create-open]');
if (dialog && opener) {
    dialog.close();
    opener.hidden = false;
    opener.addEventListener('click', () => dialog.showModal());
    dialog.querySelector('[data-list-create-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => opener.focus());
    const sources = dialog.querySelector('[data-list-sources]');
    const alternatives = dialog.querySelector('[data-source-checkboxes]');
    alternatives.hidden = false;
    const choices = [...alternatives.querySelectorAll('[data-source-choice]')];
    sources.addEventListener('change', () => choices.forEach(choice => {
        choice.checked = [...sources.selectedOptions].some(option => option.value === choice.value);
    }));
    choices.forEach(choice => choice.addEventListener('change', () => {
        [...sources.options].find(option => option.value === choice.value).selected = choice.checked;
    }));
    const file = dialog.querySelector('[name="csv_file"]');
    dialog.querySelectorAll('button[type="submit"]').forEach(button => button.addEventListener('click', () => {
        file.setCustomValidity('');
        file.required = button.value === 'upload';
    }));
    dialog.querySelector('[name="csv_file"]').addEventListener('change', event => event.target.setCustomValidity(''));
}
