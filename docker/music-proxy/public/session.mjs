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
      credentials: 'omit',
      cache: 'no-store',
      redirect: 'error',
      signal: controller.signal,
    });
    if (!response.ok) throw new Error('Verification failed');
    const identity = await response.json();
    const destination = validateDestination(identity, browser.location.origin);
    reconcile(browser.localStorage, identity);
    browser.location.replace(destination);
  } finally {
    clearTimeout(timeout);
  }
}

if (typeof window !== 'undefined') {
  launch(window).catch(() => {
    // Fail closed: do not open an unverified, potentially different musical account.
    document.getElementById('status').textContent =
      'No hemos podido comprobar la sesión. Vuelve a Multisuite y abre Música de nuevo.';
  });
}
