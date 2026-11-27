// «Volver a Multisuite» inside Jellyfin's own header, next to its back and home buttons. It
// follows the header: hidden while a video plays full screen, like Jellyfin's own controls.
if (typeof document !== 'undefined' && window.top === window.self) {
    const link = document.createElement('a');
    link.className = 'ms-video-return';
    link.href = '/_multisuite/portal';
    link.title = 'Volver a Multisuite';
    link.setAttribute('aria-label', link.title);
    const arrow = document.createElement('span');
    arrow.textContent = '←';
    arrow.setAttribute('aria-hidden', 'true');
    const label = document.createElement('span');
    label.className = 'ms-video-label';
    label.textContent = 'Multisuite';
    link.append(arrow, label);

    let pending = false;
    function place() {
        pending = false;
        const header = document.querySelector('.skinHeader .headerLeft');
        if (header && link.parentElement !== header) header.prepend(link);
    }
    new MutationObserver(() => {
        if (pending) return;
        pending = true;
        requestAnimationFrame(place);
    }).observe(document.body, { childList: true, subtree: true });
    place();
}
