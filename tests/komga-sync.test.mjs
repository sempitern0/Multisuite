import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createSync } from '../docker/komga-proxy/public/reading.mjs';

function fixture({ roles = ['ADMIN'], libraries = [{ id: 'books', root: '/data' }] } = {}) {
    const calls = [];
    const reports = [];
    let revision = 'one';
    let fail = false;
    const sync = createSync({
        request: async (path, data) => {
            calls.push([path, data]);
            if (path.endsWith('/me')) return { id: 'admin', roles };
            if (path === '/api/v1/libraries') {
                if (data) return { id: 'new', root: '/data' };
                return libraries;
            }
            if (fail) throw new Error('Service unavailable');
        },
        revision: async () => revision,
        report: message => reports.push(message),
    });
    return { calls, reports, sync, change: value => { revision = value; }, fail: value => { fail = value; } };
}

test('scans existing libraries once per content revision, without modifying them', async () => {
    const f = fixture();
    await f.sync(); await f.sync();
    assert.equal(f.calls.filter(([p]) => p.endsWith('/scan')).length, 1);
    f.change('two'); await f.sync();
    assert.equal(f.calls.filter(([p]) => p.endsWith('/scan')).length, 2);
    assert.equal(f.calls.filter(([p, d]) => p === '/api/v1/libraries' && d).length, 0);
});
test('creates the library on first entry, with destructive conversions disabled', async () => {
    const f = fixture({ libraries: [] });
    await f.sync();
    const creation = f.calls.find(([p, d]) => p === '/api/v1/libraries' && d)[1];
    assert.equal(creation.root, '/data');
    assert.equal(creation.convertToCbz, false);
    assert.equal(creation.repairExtensions, false);
    assert.ok(f.calls.some(([p]) => p === '/api/v1/libraries/new/scan'));
});
test('does not inspect or mutate libraries for an ordinary user', async () => {
    const f = fixture({ roles: ['USER'] }); await f.sync();
    assert.equal(f.calls.length, 1);
});
test('failed scans retry the same revision and clear the error on success', async () => {
    const f = fixture(); f.fail(true); await f.sync();
    assert.match(f.reports.at(-1), /reintentaremos/);
    f.fail(false); await f.sync();
    assert.equal(f.calls.filter(([p]) => p.endsWith('/scan')).length, 2);
    assert.equal(f.reports.at(-1), '');
});
test('overlapping ticks do not enqueue duplicate scans', async () => {
    const f = fixture();
    await Promise.all([f.sync(), f.sync(), f.sync()]);
    assert.equal(f.calls.filter(([p]) => p.endsWith('/scan')).length, 1);
});
test('an existing browser cache avoids a scan after navigation', async () => {
    const writes = new Map();
    const calls = [];
    const options = {
        request: async (path) => {
            calls.push(path);
            if (path.endsWith('/me')) return { id: 'admin', roles: ['ADMIN'] };
            if (path.endsWith('/libraries')) return [{ id: 'one', root: '/data' }];
        },
        revision: async () => 'same',
        report: () => {},
        storage: { getItem: key => writes.get(key), setItem: (key, value) => writes.set(key, value) },
    };
    await createSync(options)();
    await createSync(options)();
    assert.equal(calls.filter(p => p.endsWith('/scan')).length, 1);
});
