import { test } from 'node:test';
import assert from 'node:assert/strict';
import { authenticationKeys, reconcile, validateDestination, launch, openManual } from '../docker/music-proxy/public/session.mjs';

const identity = { id: 'remote-b', username: 'bruce', destination: 'http://localhost:4533/music/app/#/album' };
function storage(values = {}) {
  const valuesMap = new Map(Object.entries(values));
  return {
    getItem: key => valuesMap.get(key) ?? null,
    removeItem: key => valuesMap.delete(key),
    valuesMap,
  };
}
test('same remote identity keeps session, player queue and preferences', () => {
  const store = storage({ userId: identity.id, username: identity.username, token: 'secret', state: 'queue' });
  assert.equal(reconcile(store, identity), false);
  assert.equal(store.getItem('token'), 'secret');
  assert.equal(store.getItem('state'), 'queue');
});
test('different or incomplete identity removes only musical auth and persisted queue', () => {
  for (const old of [{ userId: 'remote-a', username: 'tony' }, { userId: identity.id, username: 'old-name' }, {}]) {
    const store = storage({ ...Object.fromEntries(authenticationKeys.map(k => [k, 'old'])), ...old, state: 'old queue', locale: 'es', unrelated: 'keep' });
    assert.equal(reconcile(store, identity), true);
    for (const key of [...authenticationKeys, 'state']) assert.equal(store.getItem(key), null);
    assert.equal(store.getItem('locale'), 'es');
    assert.equal(store.getItem('unrelated'), 'keep');
  }
});
test('destination is same-origin, preserves BaseUrl and rejects executable/foreign/bridge URLs', () => {
  assert.equal(validateDestination(identity, 'http://localhost:4533'), identity.destination);
  for (const destination of ['https://evil.test', 'javascript:alert(1)', 'http://user:pass@localhost:4533', 'http://localhost:4533/_multisuite/session']) {
    assert.throws(() => validateDestination({ ...identity, destination }, 'http://localhost:4533'));
  }
});
function browser(store = storage({ userId: 'remote-a', token: 'secret' })) {
  const events = [];
  return {
    events, localStorage: store,
    location: { hash: '#ticket=encrypted-pass', pathname: '/_multisuite/session', origin: 'http://localhost:4533', replace: value => events.push(['navigate', value]) },
    history: { replaceState: (...args) => events.push(['history', ...args]) },
  };
}
test('clears URL fragment first, exchanges the ticket with a same-origin POST, then clears stale session', async () => {
  const b = browser();
  await launch(b, async (url, options) => {
    assert.equal(b.events[0][0], 'history');
    assert.equal(url, '/_multisuite/verify');
    assert.equal(options.method, 'POST');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.redirect, 'error');
    assert.deepEqual(JSON.parse(options.body), { ticket: 'encrypted-pass' });
    assert.ok(!options.body.includes('secret'));
    return { ok: true, json: async () => identity };
  });
  assert.equal(b.localStorage.getItem('token'), null);
  assert.deepEqual(b.events.at(-1), ['navigate', identity.destination]);
});
test('invalid pass, network failure, wrong origin or unavailable storage never opens stale session', async () => {
  const requests = [
    async () => ({ ok: false }),
    async () => { throw new Error('Offline'); },
    async () => ({ ok: true, json: async () => ({ ...identity, destination: 'https://evil.test' }) }),
  ];
  for (const request of requests) {
    const b = browser();
    await assert.rejects(launch(b, request));
    assert.ok(!b.events.some(e => e[0] === 'navigate'));
    assert.equal(b.localStorage.getItem('token'), 'secret');
  }
  const b = browser({ getItem() { throw new Error('Storage disabled'); } });
  await assert.rejects(launch(b, async () => ({ ok: true, json: async () => identity })));
  assert.ok(!b.events.some(e => e[0] === 'navigate'));
});
test('missing pass never contacts verifier or touches storage', async () => {
  const b = browser();
  b.location.hash = '';
  await assert.rejects(launch(b, async () => assert.fail('unexpected request')));
  assert.equal(b.localStorage.getItem('token'), 'secret');
});

test('proxy login removes the native JWT even for the same listener and keeps their queue', async () => {
  const store = storage({ userId: identity.id, username: identity.username, token: 'native', state: 'queue' });
  const b = browser(store);
  await launch(b, async url => url.endsWith('/authorize')
    ? { ok: true, headers: new Headers({ 'X-Music-User': identity.username }) }
    : { ok: true, json: async () => ({ ...identity, autologin: true }) });
  assert.equal(store.getItem('token'), null);
  assert.equal(store.getItem('state'), 'queue');
  assert.deepEqual(b.events.at(-1), ['navigate', identity.destination]);
});

test('browser binding revisits the portal before changing the musical session', async () => {
  const b = browser();
  const confirm = 'https://multisuite.test/services/music?music_challenge=abc';
  await launch(b, async () => ({ ok: true, json: async () => ({ confirm }) }));
  assert.deepEqual(b.events.at(-1), ['navigate', confirm]);
  assert.equal(b.localStorage.getItem('token'), 'secret');
});

test('blocked music cookie prevents opening the previous listener', async () => {
  const b = browser();
  await assert.rejects(launch(b, async url => url.endsWith('/authorize')
    ? { ok: false }
    : { ok: true, json: async () => ({ ...identity, autologin: true }) }));
  assert.ok(!b.events.some(e => e[0] === 'navigate'));
});

test('manual fallback clears both credentials and queue before opening native login', async () => {
  const b = browser(storage({ token: 'old', state: 'old queue', locale: 'es' }));
  const paths = [];
  await openManual(b, async (path, options) => {
    paths.push([path, options.method ?? 'GET']);
    return { ok: true };
  });
  assert.deepEqual(paths, [['/_multisuite/manual', 'POST'], ['/_multisuite/native-ready', 'GET']]);
  assert.equal(b.localStorage.getItem('token'), null);
  assert.equal(b.localStorage.getItem('state'), null);
  assert.equal(b.localStorage.getItem('locale'), 'es');
  assert.deepEqual(b.events.at(-1), ['navigate', '/#/login']);
});
test('manual fallback stops if the previous gateway cookie is still active', async () => {
  const b = browser();
  await assert.rejects(openManual(b, async path => ({ ok: path.endsWith('/manual') })));
  assert.ok(!b.events.some(e => e[0] === 'navigate'));
});

async function enterReading(account, destination = 'https://reading.test/library') {
  const { readFile } = await import('node:fs/promises');
  const { runInNewContext } = await import('node:vm');
  const source = await readFile(new URL('../docker/reading-proxy/enter.js', import.meta.url), 'utf8');
  const values = new Map([['kavita-user', 'previous-session'], ['kavita-lastlogin', 'previous'], ['theme', 'dark']]);
  const dataset = { user: JSON.stringify(account), destination };
  let opened;
  runInNewContext(source, {
    URL, document: { body: { dataset, removeAttribute() { delete dataset.user; } } },
    location: { origin: 'https://reading.test', replace(url) { opened = url; } },
    localStorage: {
      setItem(key, value) { values.set(key, value); },
      removeItem(key) { values.delete(key); },
    },
  });
  assert.equal(dataset.user, undefined);
  return { values, opened };
}

test('Kavita opens the linked reader and replaces the previous account on a shared browser', async () => {
  const reader = { username: 'bea', token: 'fixture' };
  const { values, opened } = await enterReading(reader);
  assert.deepEqual(JSON.parse(values.get('kavita-user')), reader);
  assert.equal(values.get('kavita-lastlogin'), 'bea');
  assert.equal(values.get('theme'), 'dark');
  assert.equal(opened, 'https://reading.test/library');
});

test('Kavita manual login starts without the previous reader identity', async () => {
  const { values, opened } = await enterReading(null);
  assert.equal(values.has('kavita-user'), false);
  assert.equal(values.has('kavita-lastlogin'), false);
  assert.equal(opened, 'https://reading.test/library');
});

test('Kavita refuses foreign destinations before touching the local session', async () => {
  const { values, opened } = await enterReading({ username: 'bea' }, 'https://other.test/library');
  assert.equal(opened, undefined);
  assert.equal(values.get('kavita-user'), 'previous-session');
});
