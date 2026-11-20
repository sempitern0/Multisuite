if (typeof document !== 'undefined') {
    const link = document.createElement('a');
    link.className = 'ms-reading-return';
    link.href = '/_multisuite/portal';
    link.setAttribute('aria-label', 'Volver a Multisuite');
    link.title = 'Volver a Multisuite';
    link.innerHTML = '<span aria-hidden="true">←</span><span class="ms-reading-label">&nbsp; Multisuite</span>';
    const status = document.createElement('div');
    status.className = 'ms-reading-status';
    status.setAttribute('role', 'status');
    status.hidden = true;

    function place() {
        // The native reader hides its toolbar while reading. Follow that behaviour.
        const toolbar = document.querySelector('.v-app-bar .v-toolbar__content, .settings .v-toolbar__content');
        const drawer = document.querySelector('.v-navigation-drawer__content');
        const target = toolbar || drawer;
        if (target && link.parentElement !== target) target.prepend(link);
        if (drawer && status.parentElement !== drawer) drawer.prepend(status);
    }
    new MutationObserver(place).observe(document.body, { subtree: true, childList: true });
    place();

}
