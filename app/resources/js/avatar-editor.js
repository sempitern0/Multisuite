// Profile photo editor (x-ui::avatar-editor) on Cropper.js 2: a fixed square selection
// shown as a circle; the picture moves and zooms underneath and always covers it. Only
// convenience: the server decodes, crops and re-encodes whatever arrives.
import Cropper from 'cropperjs';

const OUTPUT = 512;
const MAX_INPUT = 40 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

// Fixed selection (not movable or resizable): the person frames the picture, not the box.
const TEMPLATE = `
<cropper-canvas background>
  <cropper-image translatable scalable></cropper-image>
  <cropper-shade hidden></cropper-shade>
  <cropper-handle action="move" plain></cropper-handle>
  <cropper-selection aspect-ratio="1" initial-coverage="0.9"></cropper-selection>
</cropper-canvas>`;

// Rejects a move or zoom that would uncover part of the circle (Cropper.js documented recipe).
const keepCovered = (canvas, image, selection) => (event) => {
    const probe = image.cloneNode();
    probe.style.transform = `matrix(${event.detail.matrix.join(', ')})`;
    probe.style.opacity = '0';
    canvas.appendChild(probe);
    const picture = probe.getBoundingClientRect();
    canvas.removeChild(probe);
    const circle = selection.getBoundingClientRect();
    if (circle.top < picture.top || circle.right > picture.right || circle.bottom > picture.bottom || circle.left < picture.left) {
        event.preventDefault();
    }
};

const avatarEditor = ({ model, save }) => ({
    editing: false,
    busy: false,
    progress: 0,
    problem: '',
    url: null,
    cropper: null,

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
        this.problem = '';
        this.close();
        this.url = URL.createObjectURL(file);
        const source = new Image();
        source.alt = '';
        source.onload = () => {
            this.editing = true;
            this.$nextTick(() => this.mount(source));
        };
        source.onerror = () => {
            this.problem = 'No se ha podido abrir la foto. Prueba con otra.';
            this.close();
        };
        source.src = this.url;
    },

    mount(source) {
        const stage = this.$refs.stage;
        stage.replaceChildren();
        this.cropper = new Cropper(source, { container: stage, template: TEMPLATE });
        const canvas = this.cropper.getCropperCanvas();
        const image = this.cropper.getCropperImage();
        const selection = this.cropper.getCropperSelection();
        image.$ready().then(() => {
            image.$center('cover');
            image.addEventListener('transform', keepCovered(canvas, image, selection));
            stage.focus();
        });
    },

    zoom(step) {
        this.cropper?.getCropperImage()?.$zoom(step);
    },

    // Same rule as the image viewer: the wheel alone keeps scrolling the page, Ctrl/⌘ + wheel zooms.
    wheel(event) {
        if (!event.ctrlKey && !event.metaKey) {
            event.stopPropagation();
        }
    },

    key(event) {
        const image = this.cropper?.getCropperImage();
        if (!image) {
            return;
        }
        const step = event.shiftKey ? 40 : 10;
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (moves[event.key]) {
            event.preventDefault();
            image.$move(...moves[event.key]);
        } else if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            image.$zoom(0.1);
        } else if (event.key === '-') {
            event.preventDefault();
            image.$zoom(-0.1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            this.upload();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.cancel();
        }
    },

    async blob() {
        const canvas = await this.cropper.getCropperSelection().$toCanvas({ width: OUTPUT, height: OUTPUT });
        const encode = (type, quality) => new Promise((resolve) => canvas.toBlob(resolve, type, quality));
        const webp = await encode('image/webp', 0.92);
        return webp && webp.type === 'image/webp' ? webp : encode('image/jpeg', 0.92);
    },

    async upload() {
        if (this.busy || !this.cropper) {
            return;
        }
        this.busy = true;
        this.progress = 0;
        this.problem = '';
        const blob = await this.blob().catch(() => null);
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
        this.cropper = null;
        this.$refs.stage?.replaceChildren();
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
