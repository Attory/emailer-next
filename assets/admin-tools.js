// Native summary keeps pointer, Enter and Space toggling and normal link navigation.
for (const menu of document.querySelectorAll('[data-tools-menu]')) {
    document.addEventListener('click', event => {
        if (menu.open && !menu.contains(event.target)) menu.open = false;
    }, true);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menu.open) {
            menu.open = false;
            menu.querySelector('summary').focus();
        }
    });
}
