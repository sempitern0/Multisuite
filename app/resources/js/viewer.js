// Mis archivos: image viewer (zoom, drag, pinch, full screen) for the theme's preview
// component. Event-delegated so it works after SPA navigation. View only: it never
// changes the file.

const MAX_SCALE = 8;
const states = new WeakMap();

const stateOf = (viewer) => {
    if (!states.has(viewer)) {
        states.set(viewer, { scale: 1, x: 0, y: 0, pointers: new Map(), pinch: 0 });
    }
    return states.get(viewer);
};

const parts = (viewer) => ({
    stage: viewer.querySelector('[data-viewer-stage]'),
    image: viewer.querySelector('[data-viewer-image]'),
    level: viewer.querySelector('[data-viewer-level]'),
});

// Scale 1 is the image fitted to the stage; "actual" shows one image pixel per screen pixel.
const actualScale = (image) => (image.clientWidth > 0 ? Math.max(1, image.naturalWidth / image.clientWidth) : 1);

const render = (viewer) => {
    const state = stateOf(viewer);
    const { stage, image, level } = parts(viewer);
    if (!stage || !image) {
        return;
    }
    // Keep the picture from being dragged out of sight.
    const limitX = Math.max(0, (image.clientWidth * state.scale - stage.clientWidth) / 2);
    const limitY = Math.max(0, (image.clientHeight * state.scale - stage.clientHeight) / 2);
    state.x = Math.min(limitX, Math.max(-limitX, state.x));
    state.y = Math.min(limitY, Math.max(-limitY, state.y));
    image.style.transform = `translate(${state.x}px, ${state.y}px) scale(${state.scale})`;
    if (level) {
        const percent = `${Math.round((state.scale / actualScale(image)) * 100)} %`;
        level.textContent = state.scale === 1 ? `Ajustada · ${percent}` : percent;
    }
};

// Zooms keeping the point under (clientX, clientY) still; defaults to the centre.
const zoomTo = (viewer, scale, clientX, clientY) => {
    const state = stateOf(viewer);
    const { stage, image } = parts(viewer);
    if (!stage || !image) {
        return;
    }
    const next = Math.min(Math.max(MAX_SCALE, actualScale(image)), Math.max(1, scale));
    const box = stage.getBoundingClientRect();
    const offsetX = (clientX ?? box.left + box.width / 2) - (box.left + box.width / 2);
    const offsetY = (clientY ?? box.top + box.height / 2) - (box.top + box.height / 2);
    const ratio = next / state.scale;
    state.x = offsetX - (offsetX - state.x) * ratio;
    state.y = offsetY - (offsetY - state.y) * ratio;
    state.scale = next;
    render(viewer);
};

const viewerOf = (target) => (target instanceof Element ? target.closest('[data-viewer]') : null);
const onStage = (event) => (event.target instanceof Element && event.target.closest('[data-viewer-stage]') ? viewerOf(event.target) : null);

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-viewer-action]') : null;
    const viewer = viewerOf(button);
    if (!button || !viewer) {
        return;
    }
    const state = stateOf(viewer);
    const { image } = parts(viewer);
    switch (button.dataset.viewerAction) {
        case 'in': zoomTo(viewer, state.scale * 1.5); break;
        case 'out': zoomTo(viewer, state.scale / 1.5); break;
        case 'fit': zoomTo(viewer, 1); break;
        case 'actual': zoomTo(viewer, actualScale(image)); break;
        case 'fullscreen':
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else {
                viewer.requestFullscreen?.().catch(() => {});
            }
            break;
    }
});

document.addEventListener('dblclick', (event) => {
    const viewer = onStage(event);
    if (viewer) {
        zoomTo(viewer, stateOf(viewer).scale > 1 ? 1 : Math.max(2, actualScale(parts(viewer).image)), event.clientX, event.clientY);
    }
});

document.addEventListener('wheel', (event) => {
    const viewer = onStage(event);
    // At the fitted size, scrolling down keeps scrolling the page instead of trapping it.
    if (!viewer || (stateOf(viewer).scale === 1 && event.deltaY > 0)) {
        return;
    }
    event.preventDefault();
    zoomTo(viewer, stateOf(viewer).scale * (event.deltaY < 0 ? 1.2 : 1 / 1.2), event.clientX, event.clientY);
}, { passive: false });

document.addEventListener('keydown', (event) => {
    const viewer = onStage(event);
    if (!viewer || event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }
    const state = stateOf(viewer);
    const step = 60;
    const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
    if (event.key === '+' || event.key === '=') {
        zoomTo(viewer, state.scale * 1.5);
    } else if (event.key === '-') {
        zoomTo(viewer, state.scale / 1.5);
    } else if (event.key === '0') {
        zoomTo(viewer, 1);
    } else if (moves[event.key] && state.scale > 1) {
        state.x += moves[event.key][0];
        state.y += moves[event.key][1];
        render(viewer);
    } else {
        return;
    }
    event.preventDefault();
});

const spread = (pointers) => {
    const [a, b] = [...pointers.values()];
    return Math.hypot(a.x - b.x, a.y - b.y);
};

document.addEventListener('pointerdown', (event) => {
    const viewer = onStage(event);
    if (!viewer || event.button > 0) {
        return;
    }
    const state = stateOf(viewer);
    const { stage } = parts(viewer);
    stage.setPointerCapture?.(event.pointerId);
    state.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    state.pinch = state.pointers.size === 2 ? spread(state.pointers) : 0;
    stage.toggleAttribute('data-dragging', true);
});

document.addEventListener('pointermove', (event) => {
    const viewer = onStage(event);
    const state = viewer ? stateOf(viewer) : null;
    const previous = state?.pointers.get(event.pointerId);
    if (!viewer || !previous) {
        return;
    }
    state.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (state.pointers.size === 2 && state.pinch > 0) {
        const distance = spread(state.pointers);
        const [a, b] = [...state.pointers.values()];
        zoomTo(viewer, state.scale * (distance / state.pinch), (a.x + b.x) / 2, (a.y + b.y) / 2);
        state.pinch = distance;
    } else if (state.pointers.size === 1) {
        state.x += event.clientX - previous.x;
        state.y += event.clientY - previous.y;
        render(viewer);
    }
});

const release = (event) => {
    const viewer = onStage(event);
    if (!viewer) {
        return;
    }
    const state = stateOf(viewer);
    state.pointers.delete(event.pointerId);
    state.pinch = 0;
    if (state.pointers.size === 0) {
        parts(viewer).stage.removeAttribute('data-dragging');
    }
};
document.addEventListener('pointerup', release);
document.addEventListener('pointercancel', release);

// The level shows the real percentage as soon as the picture has its size.
document.addEventListener('load', (event) => {
    const viewer = event.target instanceof HTMLImageElement ? viewerOf(event.target) : null;
    if (viewer) {
        render(viewer);
    }
}, true);
document.querySelectorAll('[data-viewer]').forEach(render);
document.addEventListener('livewire:navigated', () => document.querySelectorAll('[data-viewer]').forEach(render));

window.addEventListener('resize', () => document.querySelectorAll('[data-viewer]').forEach(render));
document.addEventListener('fullscreenchange', () => document.querySelectorAll('[data-viewer]').forEach(render));

// Video: the browser's own player; only explain a format it cannot decode.
document.addEventListener('error', (event) => {
    const player = event.target instanceof HTMLVideoElement ? event.target.closest('[data-player]') : null;
    if (player) {
        event.target.hidden = true;
        player.querySelector('[data-player-error]')?.removeAttribute('hidden');
    }
}, true);
