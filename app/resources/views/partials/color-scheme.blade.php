{{-- Resolve the device preference before CSS paints, also on public authentication pages. --}}
<script data-navigate-once>
(() => {
    if (window.multisuiteTheme) return;
    const key = 'multisuite.appearance';
    const media = matchMedia('(prefers-color-scheme: dark)');
    const read = () => {
        try { return localStorage.getItem(key) || 'system'; }
        catch { return 'system'; }
    };
    let choice = read();
    const apply = () => {
        const dark = choice === 'dark' || (choice !== 'light' && media.matches);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.dataset.scheme = dark ? 'dark' : 'light';
        const label = dark ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro';
        document.querySelectorAll('[data-scheme-toggle]').forEach((button) => {
            button.setAttribute('aria-label', label);
            button.title = label;
        });
        window.dispatchEvent(new CustomEvent('multisuite-scheme', { detail: { dark, choice } }));
    };
    window.multisuiteTheme = {
        get choice() { return choice; },
        get dark() { return document.documentElement.dataset.scheme === 'dark'; },
        toggle() { this.select(this.dark ? 'light' : 'dark'); },
        select(value) {
            choice = ['light','dark'].includes(value) ? value : 'system';
            try { localStorage.setItem(key, choice); } catch {}
            apply();
        }
    };
    media.addEventListener('change', apply);
    window.addEventListener('storage', () => { choice = read(); apply(); });
    document.addEventListener('livewire:navigated', apply);
    document.addEventListener('DOMContentLoaded', apply);
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-scheme-toggle]')) window.multisuiteTheme.toggle();
    });
    apply();
})();
</script>
