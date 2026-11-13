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

const putBody = (url, file, csrf, onProgress) =>
    new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url);
        xhr.setRequestHeader('Content-Type', 'application/octet-stream');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded / event.total);
            }
        });
        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve();
                return;
            }
            let message = xhr.status === 413 ? 'El archivo es demasiado grande para el servidor.' : 'El servidor rechazó el archivo (tipo no permitido o sin espacio).';
            try {
                const body = JSON.parse(xhr.responseText);
                message = (body.errors && Object.values(body.errors).flat()[0]) || message;
            } catch {
                // Keep the generic message.
            }
            reject(new Error(message));
        });
        xhr.addEventListener('error', () => reject(new Error('Se perdió la conexión durante la subida.')));
        xhr.send(file);
    });

const row = (list, name) => {
    const item = document.createElement('li');
    item.className = 'rounded-lg border border-zinc-200 p-3 dark:border-zinc-700';
    const label = document.createElement('p');
    label.className = 'text-sm';
    label.textContent = name;
    const bar = document.createElement('progress');
    bar.max = 100;
    bar.value = 0;
    bar.className = 'mt-2 w-full';
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
            label.className = ok ? 'text-sm text-teal-800 dark:text-teal-300' : 'text-sm text-red-700 dark:text-red-400';
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

document.querySelectorAll('[data-uploader]').forEach((root) => {
    const input = root.querySelector('[data-uploader-input]');
    const list = document.querySelector('[data-uploader-list]');
    input?.addEventListener('change', async () => {
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
        if (succeeded > 0 && succeeded === files.length) {
            window.location.reload();
        }
    });
});
