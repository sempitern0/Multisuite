// Navidrome 0.64.2 authProvider.js + store/persistState.js contract.
// Never send or read back a musical token from the portal.
export const authenticationKeys = [
  'token', 'userId', 'name', 'username', 'avatar', 'role',
  'subsonic-salt', 'subsonic-token', 'is-authenticated',
];

export function reconcile(storage, identity) {
  const sameAccount = storage.getItem('userId') === identity.id
    && storage.getItem('username') === identity.username;
  if (sameAccount) return false;
  for (const key of authenticationKeys) storage.removeItem(key);
  // Contains the previous user's queue and library selection. Keep locale/theme keys elsewhere.
  storage.removeItem('state');
  return true;
}

export function validateDestination(identity, origin) {
  if (!identity || typeof identity.id !== 'string' || !identity.id
      || typeof identity.username !== 'string' || !identity.username
      || typeof identity.destination !== 'string') throw new Error('Invalid identity');
  const target = new URL(identity.destination);
  if (target.origin !== origin || !['http:', 'https:'].includes(target.protocol)
      || target.username || target.password || target.pathname.startsWith('/_multisuite/')) {
    throw new Error('Invalid destination');
  }
  return target.href;
}

class LaunchError extends Error {}

export async function launch(browser, request = fetch) {
  const ticket = new URLSearchParams(browser.location.hash.slice(1)).get('ticket');
  // Remove the pass before any request; it is never sent in a URL or Referer.
  browser.history.replaceState(null, '', browser.location.pathname);
  if (!ticket || ticket.length > 4096) throw new Error('Missing ticket');
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 15000);
  try {
    const response = await request('/_multisuite/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ticket }),
      credentials: 'same-origin',
      cache: 'no-store',
      redirect: 'error',
      signal: controller.signal,
    });
    if (!response.ok) {
      const messages = {
        403: 'El pase ha caducado o ya no tienes acceso desde el portal.',
        409: 'La cuenta vinculada no coincide con la cuenta que devuelve Navidrome.',
        429: 'Hay demasiados intentos de acceso seguidos.',
        503: 'El portal no ha podido consultar tu cuenta en Navidrome.',
      };
      throw new LaunchError(messages[response.status] || 'El portal no ha podido completar la conexión.');
    }
    const identity = await response.json();
    if (typeof identity.confirm === 'string') {
      // Only the portal can supply this callback, from its original encrypted pass.
      const portal = new URL(identity.confirm);
      if (!['http:', 'https:'].includes(portal.protocol) || portal.username || portal.password) {
        throw new Error('Invalid portal');
      }
      browser.location.replace(portal.href);
      return;
    }
    const destination = validateDestination(identity, browser.location.origin);
    if (identity.autologin) {
      const active = await request('/_multisuite/authorize', {
        credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
      });
      if (!active.ok || active.headers.get('X-Music-User') !== identity.username) {
        throw new Error('Music cookie unavailable');
      }
    }
    reconcile(browser.localStorage, identity);
    // A native JWT takes priority over proxy auth in Navidrome 0.64.
    if (identity.autologin) {
      browser.localStorage.removeItem('token');
    } else if (!browser.localStorage.getItem('token')) {
      for (const key of authenticationKeys) browser.localStorage.removeItem(key);
    }
    browser.location.replace(destination);
  } finally {
    clearTimeout(timeout);
  }
}

export async function openManual(browser, request = fetch) {
  const options = { credentials: 'same-origin', cache: 'no-store', redirect: 'error' };
  const reset = await request('/_multisuite/manual', { ...options, method: 'POST' });
  if (!reset.ok) throw new Error('reset');
  const storage = browser.localStorage;
  for (const key of [...authenticationKeys, 'state']) storage.removeItem(key);
  const ready = await request('/_multisuite/native-ready', options);
  if (!ready.ok) throw new Error('cookie');
  // Navidrome's root redirect preserves its configured BaseURL.
  browser.location.replace('/#/login');
}

if (typeof window !== 'undefined') {
  const title = document.getElementById('title');
  const status = document.getElementById('status');
  const manual = document.getElementById('manual');
  let timer;
  const settled = (heading, message) => {
    document.body.dataset.settled = '';
    title.textContent = heading;
    status.textContent = message;
    manual.hidden = false;
  };
  const fallback = async () => {
    clearTimeout(timer);
    if (manual.disabled) return;
    manual.disabled = true;
    try {
      await openManual(window);
    } catch {
      settled('Acceso manual pendiente', 'No se pudo limpiar la sesión anterior. Comprueba las cookies y el almacenamiento de Música y vuelve a intentarlo.');
      manual.disabled = false;
    }
  };
  manual.addEventListener('click', fallback);
  window.addEventListener('pagehide', () => clearTimeout(timer));
  if (window.location.pathname.endsWith('/logout')) {
    settled('Sesión musical cerrada', 'Puedes volver al portal o entrar con otra cuenta.');
  } else {
    launch(window).catch(error => {
      const detail = error instanceof LaunchError
        ? error.message : 'No se ha podido completar el acceso automático.';
      settled('Acceso manual', detail + ' Abriendo el formulario de Navidrome…');
      timer = setTimeout(fallback, 3000);
    });
  }
}
