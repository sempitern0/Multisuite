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

// Rows carry only state (data-state); the active theme decides how they look.
const row = (list, name) => {
    const item = document.createElement('li');
    const label = document.createElement('p');
    label.textContent = name;
    const bar = document.createElement('progress');
    bar.max = 100;
    bar.value = 0;
    bar.setAttribute('aria-label', `Progreso de ${name}`);
    item.append(label, bar);
    list.append(item);
    return {
        progress: (ratio) => {
            bar.value = Math.round(ratio * 100);
        },
        done: (text, ok) => {
            bar.remove();
            label.textContent = `${name}: ${text}`;
            item.dataset.state = ok ? 'ok' : 'bad';
            if (!ok) {
                label.setAttribute('role', 'alert');
            }
        },
    };
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
    try {
        await putBody(`${uploadUrl}/${key}`, file, csrf, ui.progress);
    } catch (error) {
        // Free the reservation now instead of waiting for the expiry cleanup.
        await fetch(`${uploadUrl}/${key}`, { method: 'DELETE', headers, credentials: 'same-origin' }).catch(() => {});
        throw error;
    }
};

// Delegated: uploaders can appear later (SPA navigation, Livewire updates).
document.addEventListener('change', async (event) => {
    const input = event.target instanceof HTMLInputElement && event.target.matches('[data-uploader-input]') ? event.target : null;
    const root = input?.closest('[data-uploader]');
    const list = root?.querySelector('[data-uploader-list]');
    if (!input || !root || !list) {
        return;
    }
    const files = [...input.files];
    input.disabled = true;
    let succeeded = 0;
    for (const file of files) {
        const ui = row(list, file.name);
        try {
            await uploadOne(root, file, ui);
            ui.done('subido', true);
            succeeded++;
        } catch (error) {
            ui.done(error.message, false);
        }
    }
    input.value = '';
    input.disabled = false;
    if (succeeded > 0) {
        // The page refreshes its listing in place; failed rows stay visible.
        window.Livewire?.dispatch('files-uploaded');
    }
});
