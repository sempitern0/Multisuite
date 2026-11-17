// Attach to native controls so the link follows drawer and reader visibility.
(() => {
  if (window.top !== window.self) return;
  const reading = !!document.querySelector('app-root');
  const readerMenus = '.side-nav-container .side-nav, .top-action-bar, #toolbarViewerLeft, app-manga-reader .fixed-top.overlay > div:has(button)';
  const style = document.createElement('style');
  style.textContent = `
    a[data-multisuite-return] {
      box-sizing: border-box; display: flex; align-items: center; gap: .45rem;
      width: max-content; max-width: calc(100% - 8px); min-height: 44px;
      margin: 4px; padding: 0 10px; border: 1px solid #aeba56; border-radius: 10px;
      background: #27301c; color: #dfec70; font: 650 14px/1.2 system-ui, sans-serif;
      text-decoration: none; white-space: nowrap; overflow: hidden; flex: 0 0 auto;
    }
    a[data-multisuite-return]:hover, a[data-multisuite-return]:focus-visible {
      background: #dfec70; color: #192211;
    }
    a[data-multisuite-return]:focus-visible { outline: 3px solid currentColor; outline-offset: -4px; }
    a[data-multisuite-return] > span:first-child { flex: none; font-size: 20px; }
    a[data-multisuite-return][data-compact] > span:last-child { display: none; }
  `;
  document.head.append(style);
  const sizes = new ResizeObserver(entries => {
    for (const { target, contentRect } of entries) {
      target.querySelector(':scope > [data-multisuite-return]')?.toggleAttribute('data-compact', contentRect.width < 125);
    }
  });
  function attach() {
    // Use the parent of a native top-level resource row, below the app bar's spacer.
    // Material UI may keep separate mobile and desktop drawers mounted.
    const menus = reading ? document.querySelectorAll(readerMenus) :
      Array.from(document.querySelectorAll('.MuiDrawer-paper'), drawer =>
        Array.from(drawer.querySelectorAll('a[href]')).find(row =>
          !row.hasAttribute('data-multisuite-return') && !row.closest('.MuiCollapse-root'))?.parentElement);
    for (const menu of menus) {
      if (!menu || menu.querySelector(':scope > [data-multisuite-return]')) continue;
      const link = document.createElement('a');
      link.href = '/_multisuite/portal';
      link.dataset.multisuiteReturn = '';
      link.title = 'Volver a Multisuite';
      link.setAttribute('aria-label', link.title);
      const arrow = document.createElement('span');
      arrow.textContent = '←';
      arrow.setAttribute('aria-hidden', 'true');
      const label = document.createElement('span');
      label.textContent = 'Multisuite';
      link.append(arrow, label);
      menu.prepend(link);
      sizes.observe(menu);
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
