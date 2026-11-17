// Attach to native controls so the link follows drawer and reader visibility.
(() => {
  if (window.top !== window.self) return;
  const reading = !!document.querySelector('app-root');
  const selectors = reading
    ? ['.side-nav-container .side-nav', '.top-action-bar', '#toolbarViewerLeft', 'app-manga-reader .fixed-top.overlay > div:has(button)']
    : ['.MuiDrawer-paper'];
  function attach() {
    for (const selector of selectors) {
      const menu = document.querySelector(selector);
      if (!menu || menu.querySelector('[data-multisuite-return]')) continue;
      const link = document.createElement('a');
      link.href = '/_multisuite/portal';
      link.dataset.multisuiteReturn = '';
      link.textContent = '← Multisuite';
      link.title = 'Volver a Multisuite';
      link.setAttribute('aria-label', link.title);
      Object.assign(link.style, { display: 'inline-flex', alignItems: 'center', minHeight: '44px',
        padding: '0 .65rem', color: 'inherit', whiteSpace: 'nowrap', overflow: 'hidden', flexShrink: '0' });
      menu.prepend(link);
    }
  }
  let pending = false;
  new MutationObserver(() => {
    if (pending) return;
    pending = true;
    requestAnimationFrame(() => { pending = false; attach(); });
  }).observe(document.body, { childList: true, subtree: true });
  attach();
})();
