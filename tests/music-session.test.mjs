import { test } from 'node:test';
import assert from 'node:assert/strict';
import { authenticationKeys, reconcile, validateDestination, launch } from '../docker/music-proxy/public/session.mjs';

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
test('clears URL fragment first, verifies using POST without cookies, then clears stale session', async () => {
  const b = browser();
  await launch(b, async (url, options) => {
    assert.equal(b.events[0][0], 'history');
    assert.equal(url, '/_multisuite/verify');
    assert.equal(options.method, 'POST');
    assert.equal(options.credentials, 'omit');
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
