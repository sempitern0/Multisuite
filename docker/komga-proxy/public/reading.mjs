// Uses Komga's own session. No passwords or authentication tokens are copied.
export function createSync({ request, revision, report, storage }) {
    let busy = false;
    let lastRevision;
    return async function sync() {
        if (busy) return;
        busy = true;
        try {
            const user = await request('/api/v2/users/me');
            if (!user.roles?.includes('ADMIN')) return;
            const current = await revision();
            if (current === lastRevision) return;
            const key = 'multisuite-reading:' + user.id;
            let saved;
            try { saved = storage?.getItem(key); } catch { /* Storage can be disabled. */ }
            if (saved === current) { lastRevision = current; return; }
            report('Actualizando tu biblioteca…');
            let libraries = (await request('/api/v1/libraries'))
                .filter(library => library.root.replace(/\/$/, '') === '/data'
                    || library.root.startsWith('/data/'));
            // Existing libraries and their permissions remain untouched.
            if (!libraries.length) {
                libraries = [await request('/api/v1/libraries', {
                    name: 'Lectura', root: '/data', scanOnStartup: true,
                    scanEpub: true, scanPdf: true, scanCbx: true,
                    repairExtensions: false, convertToCbz: false,
                })];
            }
            for (const library of libraries) {
                await request('/api/v1/libraries/' + encodeURIComponent(library.id) + '/scan', {});
            }
            // Mark only after every request succeeded; failures retry on the next tick.
            lastRevision = current;
            try { storage?.setItem(key, current); } catch { /* Keep in-memory deduplication. */ }
            report('');
        } catch (error) {
            if (error.status !== 401 && error.status !== 403) {
                report('No se ha podido actualizar la biblioteca. Lo reintentaremos automáticamente.');
            } else report('');
        } finally { busy = false; }
    };
}

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

    async function request(path, data) {
        const response = await fetch(path, {
            credentials: 'same-origin',
            method: data === undefined ? 'GET' : 'POST',
            headers: data === undefined ? {} : { 'Content-Type': 'application/json' },
            body: data === undefined ? undefined : JSON.stringify(data),
        });
        if (!response.ok) throw Object.assign(new Error('Request failed'), { status: response.status });
        if (response.status === 204) return null;
        const text = await response.text();
        return text ? JSON.parse(text) : null;
    }
    let storage;
    try { storage = window.localStorage; } catch { /* Optional cache. */ }
    const sync = createSync({
        request, storage,
        revision: async () => {
            const response = await fetch('/_multisuite/revision', { credentials: 'same-origin', cache: 'no-store' });
            if (response.status === 404) return 'initial';
            if (!response.ok) throw Object.assign(new Error('Revision unavailable'), { status: response.status });
            return response.text();
        },
        report: message => { status.textContent = message; status.hidden = !message; },
    });
    const update = () => { if (!document.hidden) void sync(); };
    update();
    setInterval(update, 15000);
    document.addEventListener('visibilitychange', update);
}
