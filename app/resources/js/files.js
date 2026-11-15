// Mis archivos: two-step private upload (reserve + register, then raw PUT).
// The server verifies size, type and quota; these checks only give early feedback.

const formatBytes = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let index = 0;
    while (value >= 1024 && index < units.length - 1) {
        value /= 1024;
        index++;
    }
    return `${value.toLocaleString('es-ES', { maximumFractionDigits: index === 0 ? 0 : 1 })} ${units[index]}`;
};

const uuid = () => {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }
    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

const firstError = async (response) => {
    try {
        const body = await response.json();
        const errors = body.errors ? Object.values(body.errors).flat() : [];
        return errors[0] || body.message || 'No se pudo completar la subida.';
    } catch {
        return response.status === 413 ? 'El archivo es demasiado grande para el servidor.' : 'No se pudo completar la subida.';
    }
};

// Files travel in 8 MiB chunks: every request stays under the web server and PHP body
// limits whatever the file size. Must stay <= PHP post_max_size (8M by default).
const CHUNK_BYTES = 8 * 1024 * 1024;
const RETRIES = 3;

class UploadRefused extends Error {}

const putChunk = (url, blob, csrf, onProgress) =>
    new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url);
        xhr.setRequestHeader('Content-Type', 'application/octet-stream');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded);
            }
        });
        xhr.addEventListener('load', () => {
            let body = {};
            try {
                body = JSON.parse(xhr.responseText);
            } catch {
                // Non-JSON error page.
            }
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve({ received: null });
                return;
            }
            // The server already has a different amount (lost response, retry): continue from there.
            if (xhr.status === 409 && Number.isInteger(body.received)) {
                resolve({ received: body.received });
                return;
            }
            const message = (body.errors && Object.values(body.errors).flat()[0])
                || (xhr.status === 413 ? 'El servidor rechazó el fragmento por tamaño.' : 'El servidor rechazó el archivo (tipo no permitido o sin espacio).');
            // 4xx are decisions, not glitches: retrying cannot help (except rate limiting).
            const Failure = xhr.status >= 400 && xhr.status < 500 && xhr.status !== 429 ? UploadRefused : Error;
            reject(new Failure(message));
        });
        xhr.addEventListener('error', () => reject(new Error('Se perdió la conexión durante la subida.')));
        xhr.send(blob);
    });

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const putBody = async (url, file, csrf, onProgress) => {
    let offset = 0;
    let failures = 0;
    while (offset < file.size) {
        const end = Math.min(file.size, offset + CHUNK_BYTES);
        try {
            const { received } = await putChunk(`${url}?offset=${offset}`, file.slice(offset, end), csrf, (loaded) => onProgress((offset + loaded) / file.size));
            offset = received ?? end;
            failures = 0;
        } catch (error) {
            failures++;
            if (error instanceof UploadRefused || failures > RETRIES) {
                throw error;
            }
            await wait(1000 * failures);
            // Ask where the server stands before resending (the chunk may have arrived).
            const status = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => (r.ok ? r.json() : null)).catch(() => null);
            if (status?.state === 'ready') {
                break;
            }
            if (Number.isInteger(status?.received)) {
                offset = status.received;
            }
        }
        onProgress(offset / file.size);
    }
};

const uploadOne = async (root, file, ui) => {
    const { storeUrl, uploadUrl, folder, maxBytes, csrf } = root.dataset;
    if (file.size === 0) {
        throw new Error('El archivo está vacío.');
    }
    if (file.size > Number(maxBytes)) {
        throw new Error(`Supera el máximo de ${formatBytes(Number(maxBytes))}.`);
    }
    const key = uuid();
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf };
    const response = await fetch(storeUrl, {
        method: 'POST',
        headers,
        credentials: 'same-origin',
        body: JSON.stringify({ key, bytes: file.size, name: file.name, folder: folder ? Number(folder) : null }),
    });
    if (!response.ok) {
        throw new Error(await firstError(response));
    }
    const entry = await response.json();
    try {
        await putBody(`${uploadUrl}/${key}`, file, csrf, ui.progress);
    } catch (error) {
        // Free the reservation now instead of waiting for the expiry cleanup.
        await fetch(`${uploadUrl}/${key}`, { method: 'DELETE', headers, credentials: 'same-origin' }).catch(() => {});
        throw error;
    }
    return entry.id;
};

// Upload tray: a single floating summary (total progress, counts) with an expandable
// list that puts errors first. The theme owns the markup (<template data-tray-row>);
// this script only sets data-state, texts and widths.
const PARALLEL = 3;

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

class Tray {
    constructor(root) {
        this.root = root;
        this.el = root.querySelector('[data-tray]');
        this.list = root.querySelector('[data-tray-list]');
        this.template = root.querySelector('template[data-tray-row]');
        this.items = [];
        this.fresh = [];
        this.refreshTimer = null;
        this.userOpened = false;
        this.el.querySelector('[data-tray-toggle]').addEventListener('click', () => {
            this.userOpened = this.list.hidden;
            this.expand(this.userOpened);
        });
        this.el.querySelector('[data-tray-close]').addEventListener('click', () => this.close());
    }

    get running() {
        return this.items.some((item) => item.state === 'queued' || item.state === 'uploading');
    }

    add(file) {
        if (!this.running) {
            // A new batch after a finished one starts from a clean summary.
            this.items = [];
            this.list.replaceChildren();
            this.userOpened = false;
            this.expand(false);
        }
        const node = this.template.content.firstElementChild.cloneNode(true);
        node.querySelector('[data-row-name]').textContent = file.name;
        node.querySelector('[data-row-name]').title = file.name;
        node.querySelector('[data-row-meta]').textContent = formatBytes(file.size);
        this.list.append(node);
        const item = { file, node, state: 'queued', loaded: 0 };
        this.items.push(item);
        this.el.hidden = false;
        this.el.querySelector('[data-tray-close]').hidden = true;
        this.render();
        return item;
    }

    set(item, state, error = '') {
        item.state = state;
        item.node.dataset.state = state;
        item.node.querySelector('[data-row-error]').textContent = error;
        if (state === 'ok') {
            item.loaded = item.file.size;
        }
        if (state === 'bad') {
            // Errors rise to the top of the list, where they cannot be missed.
            this.list.prepend(item.node);
        }
        this.render();
    }

    progress(item, ratio) {
        item.loaded = Math.round(item.file.size * Math.min(1, ratio));
        item.node.querySelector('[data-row-bar]').style.width = `${Math.round(ratio * 100)}%`;
        item.node.querySelector('[data-row-meta]').textContent = `${Math.round(ratio * 100)} %`;
        this.render();
    }

    uploaded(item, id) {
        this.set(item, 'ok');
        item.node.querySelector('[data-row-meta]').textContent = formatBytes(item.file.size);
        this.fresh.push(id);
        // Files appear in the listing as they finish, a few at a time.
        clearTimeout(this.refreshTimer);
        this.refreshTimer = setTimeout(() => this.refresh(), this.running ? 700 : 0);
    }

    refresh() {
        if (this.fresh.length > 0) {
            window.Livewire?.dispatch('files-uploaded', { ids: this.fresh });
            this.fresh = [];
        }
    }

    render() {
        const total = this.items.reduce((sum, item) => sum + item.file.size, 0);
        const loaded = this.items.reduce((sum, item) => sum + item.loaded, 0);
        const ok = this.items.filter((item) => item.state === 'ok').length;
        const bad = this.items.filter((item) => item.state === 'bad').length;
        const count = this.items.length;
        const finished = !this.running;
        const title = this.el.querySelector('[data-tray-title]');
        const detail = this.el.querySelector('[data-tray-detail]');
        this.el.dataset.state = finished ? (bad > 0 ? 'errors' : 'done') : 'running';
        this.el.querySelector('[data-tray-bar]').style.width = `${total > 0 ? Math.round((loaded / total) * 100) : 0}%`;
        if (!finished) {
            title.textContent = `Subiendo ${plural(count, 'archivo', 'archivos')}`;
            detail.textContent = `${ok + bad} de ${count} · ${formatBytes(loaded)} de ${formatBytes(total)}${bad > 0 ? ` · ${plural(bad, 'error', 'errores')}` : ''}`;
            return;
        }
        title.textContent = bad === 0
            ? (ok === 1 ? '1 archivo subido' : `${ok} archivos subidos`)
            : `${plural(ok, 'subido', 'subidos')}, ${plural(bad, 'con error', 'con error')}`;
        detail.textContent = bad === 0 ? `${formatBytes(total)} en total. Ya están en la lista.` : 'Revisa los errores abajo y vuelve a intentarlo.';
        this.el.querySelector('[data-tray-close]').hidden = false;
        // Errors need reading; a clean batch folds itself away.
        this.expand(bad > 0 || this.userOpened);
    }

    expand(open) {
        this.list.hidden = !open;
        this.el.querySelector('[data-tray-toggle]').setAttribute('aria-expanded', String(open));
    }

    close() {
        if (!this.running) {
            this.el.hidden = true;
            this.items = [];
            this.list.replaceChildren();
        }
    }
}

const trays = new WeakMap();
const trayOf = (root) => {
    if (!trays.has(root)) {
        trays.set(root, new Tray(root));
    }
    return trays.get(root);
};

// Up to PARALLEL files at a time; each one is still sent in ordered chunks.
const enqueue = (root, files) => {
    const tray = trayOf(root);
    const queue = files.map((file) => tray.add(file));
    const worker = async () => {
        while (queue.length > 0) {
            const item = queue.shift();
            tray.set(item, 'uploading');
            try {
                const id = await uploadOne(root, item.file, { progress: (ratio) => tray.progress(item, ratio) });
                tray.uploaded(item, id);
            } catch (error) {
                tray.set(item, 'bad', error.message);
            }
        }
    };
    return Promise.all(Array.from({ length: Math.min(PARALLEL, queue.length) }, worker)).then(() => tray.refresh());
};

const uploading = () => [...document.querySelectorAll('[data-uploader]')].some((root) => trays.get(root)?.running);

// Delegated: uploaders can appear later (SPA navigation, Livewire updates).
document.addEventListener('change', (event) => {
    const input = event.target instanceof HTMLInputElement && event.target.matches('[data-uploader-input]') ? event.target : null;
    const root = input?.closest('[data-uploader]');
    if (!input || !root || input.files.length === 0) {
        return;
    }
    enqueue(root, [...input.files]);
    input.value = '';
});

// Drag and drop anywhere on a page that has an uploader.
let dragDepth = 0;
const dropRoot = () => document.querySelector('[data-uploader]');
const carriesFiles = (event) => [...(event.dataTransfer?.types ?? [])].includes('Files');
const showDrop = (visible) => {
    const zone = dropRoot()?.querySelector('[data-uploader-drop]');
    if (zone) {
        zone.hidden = !visible;
    }
};
document.addEventListener('dragenter', (event) => {
    if (dropRoot() && carriesFiles(event)) {
        dragDepth++;
        showDrop(true);
    }
});
document.addEventListener('dragleave', (event) => {
    if (dropRoot() && carriesFiles(event) && --dragDepth <= 0) {
        dragDepth = 0;
        showDrop(false);
    }
});
document.addEventListener('dragover', (event) => {
    if (dropRoot() && carriesFiles(event)) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    }
});
document.addEventListener('drop', (event) => {
    const root = dropRoot();
    if (!root || !carriesFiles(event)) {
        return;
    }
    event.preventDefault();
    dragDepth = 0;
    showDrop(false);
    // Folders dropped from the desktop arrive as zero-byte entries; they are skipped with a message.
    const files = [...event.dataTransfer.files];
    if (files.length > 0) {
        enqueue(root, files);
    }
});

// Leaving the page would stop the uploads that are still running.
window.addEventListener('beforeunload', (event) => {
    if (uploading()) {
        event.preventDefault();
    }
});
document.addEventListener('livewire:navigate', (event) => {
    if (uploading() && !window.confirm('Hay archivos subiéndose. Si sales de la página se cancelarán. ¿Salir igualmente?')) {
        event.preventDefault();
    }
});
