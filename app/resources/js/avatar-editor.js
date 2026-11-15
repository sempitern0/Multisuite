// Profile photo editor (x-ui::avatar-editor): frame a photo inside a circle and upload a
// 512 px square. Only convenience: the server decodes, crops and re-encodes whatever arrives.

const OUTPUT = 512;
const MAX_ZOOM = 4;
const MAX_INPUT = 40 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

const avatarEditor = ({ model, save }) => ({
    editing: false,
    busy: false,
    progress: 0,
    problem: '',
    zoom: 1,
    x: 0,
    y: 0,
    url: null,
    pointers: new Map(),
    pinch: 0,

    choose(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) {
            return;
        }
        if (!TYPES.includes(file.type)) {
            this.problem = 'Elige una foto en JPG, PNG, WebP o GIF.';
            return;
        }
        if (file.size > MAX_INPUT) {
            this.problem = 'La foto pesa demasiado (más de 40 MB).';
            return;
        }
        this.release();
        this.url = URL.createObjectURL(file);
        const image = this.$refs.image;
        image.onload = () => {
            this.problem = '';
            this.zoom = 1;
            this.x = 0;
            this.y = 0;
            this.editing = true;
            this.$nextTick(() => {
                this.render();
                this.$refs.stage.focus();
            });
        };
        image.onerror = () => {
            this.problem = 'No se ha podido abrir la foto. Prueba con otra.';
        };
        image.src = this.url;
    },

    // Scale at zoom 1: the picture just covers the circle.
    base() {
        const image = this.$refs.image;
        return this.$refs.stage.clientWidth / Math.min(image.naturalWidth || 1, image.naturalHeight || 1);
    },

    render() {
        const image = this.$refs.image;
        const stage = this.$refs.stage;
        if (!image.naturalWidth || !stage.clientWidth) {
            return;
        }
        const scale = this.base() * this.zoom;
        const limitX = Math.max(0, (image.naturalWidth * scale - stage.clientWidth) / 2);
        const limitY = Math.max(0, (image.naturalHeight * scale - stage.clientHeight) / 2);
        this.x = Math.min(limitX, Math.max(-limitX, this.x));
        this.y = Math.min(limitY, Math.max(-limitY, this.y));
        image.style.width = `${image.naturalWidth * this.base()}px`;
        image.style.height = `${image.naturalHeight * this.base()}px`;
        image.style.transform = `translate(-50%, -50%) translate(${this.x}px, ${this.y}px) scale(${this.zoom})`;
    },

    setZoom(value) {
        this.zoom = Math.min(MAX_ZOOM, Math.max(1, value));
        this.render();
    },

    press(event) {
        this.$refs.stage.setPointerCapture(event.pointerId);
        this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        this.pinch = this.pointers.size === 2 ? this.spread() : 0;
    },

    drag(event) {
        const last = this.pointers.get(event.pointerId);
        if (!last) {
            return;
        }
        const now = { x: event.clientX, y: event.clientY };
        this.pointers.set(event.pointerId, now);
        if (this.pointers.size === 2) {
            const spread = this.spread();
            if (this.pinch > 0) {
                this.setZoom(this.zoom * (spread / this.pinch));
            }
            this.pinch = spread;
            return;
        }
        this.x += now.x - last.x;
        this.y += now.y - last.y;
        this.render();
    },

    release(event) {
        if (event) {
            this.pointers.delete(event.pointerId);
        } else {
            this.pointers.clear();
        }
        this.pinch = 0;
    },

    spread() {
        const [a, b] = [...this.pointers.values()];
        return Math.hypot(a.x - b.x, a.y - b.y);
    },

    // Same rule as the image viewer: the wheel alone keeps scrolling the page.
    wheel(event) {
        if (!event.ctrlKey && !event.metaKey) {
            return;
        }
        event.preventDefault();
        this.setZoom(this.zoom * (event.deltaY < 0 ? 1.08 : 1 / 1.08));
    },

    key(event) {
        const step = event.shiftKey ? 40 : 10;
        const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
        if (moves[event.key]) {
            event.preventDefault();
            this.x += moves[event.key][0];
            this.y += moves[event.key][1];
            this.render();
        } else if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            this.setZoom(this.zoom + 0.1);
        } else if (event.key === '-') {
            event.preventDefault();
            this.setZoom(this.zoom - 0.1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            this.upload();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.cancel();
        }
    },

    // The visible circle, in picture pixels, drawn onto a 512 px square.
    crop() {
        const image = this.$refs.image;
        const stage = this.$refs.stage;
        const scale = this.base() * this.zoom;
        const side = stage.clientWidth / scale;
        const left = image.naturalWidth / 2 - this.x / scale - side / 2;
        const top = image.naturalHeight / 2 - this.y / scale - side / 2;
        const canvas = document.createElement('canvas');
        canvas.width = OUTPUT;
        canvas.height = OUTPUT;
        canvas.getContext('2d').drawImage(image, left, top, side, side, 0, 0, OUTPUT, OUTPUT);
        return new Promise((resolve) => {
            canvas.toBlob((blob) => {
                if (blob && blob.type === 'image/webp') {
                    resolve(blob);
                } else {
                    canvas.toBlob(resolve, 'image/jpeg', 0.92);
                }
            }, 'image/webp', 0.92);
        });
    },

    async upload() {
        if (this.busy || !this.$refs.image.naturalWidth) {
            return;
        }
        this.busy = true;
        this.progress = 0;
        this.problem = '';
        const blob = await this.crop();
        if (!blob) {
            this.busy = false;
            this.problem = 'Tu navegador no ha podido preparar la foto.';
            return;
        }
        const file = new File([blob], blob.type === 'image/webp' ? 'avatar.webp' : 'avatar.jpg', { type: blob.type });
        this.$wire.upload(
            model,
            file,
            async () => {
                await this.$wire.call(save);
                this.busy = false;
                this.close();
            },
            () => {
                this.busy = false;
                this.problem = 'No se ha podido subir la foto. Inténtalo de nuevo.';
            },
            (event) => {
                this.progress = event.detail.progress;
            },
        );
    },

    cancel() {
        this.problem = '';
        this.close();
    },

    close() {
        this.editing = false;
        this.release();
        if (this.url) {
            URL.revokeObjectURL(this.url);
            this.url = null;
        }
    },
});

const register = () => window.Alpine.data('avatarEditor', avatarEditor);
if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
