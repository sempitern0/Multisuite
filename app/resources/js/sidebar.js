// Collapse button of the side menu: switches <html data-sidebar> at once and remembers
// the choice for the person (PUT settings/sidebar). The theme decides what compact looks like.
document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-sidebar-toggle]') : null;
    if (!button) {
        return;
    }
    const root = document.documentElement;
    const next = root.dataset.sidebar === 'compact' ? 'full' : 'compact';
    root.dataset.sidebar = next;
    button.setAttribute('aria-expanded', String(next === 'full'));
    button.title = next === 'full' ? 'Contraer el menú' : 'Expandir el menú';
    fetch(button.dataset.save, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': button.dataset.csrf ?? '' },
        body: JSON.stringify({ sidebar: next }),
    }).catch(() => {
        // Not remembered this time; the menu still changed for this page.
    });
});
