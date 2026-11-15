// Shift + click on a checkbox inside [data-range-select] ticks or unticks every checkbox
// between it and the last one clicked, like a desktop file explorer. It only changes
// checked states and fires the usual events (Livewire wire:model picks them up); it
// never moves focus or selects page text.
const anchors = new WeakMap();

const boxesIn = (scope) => [...scope.querySelectorAll('input[type="checkbox"]:not(:disabled)')].filter((box) => box.offsetParent !== null);

// Shift + mousedown would otherwise select the text between the two clicks.
document.addEventListener('mousedown', (event) => {
    if (event.shiftKey && event.target instanceof HTMLInputElement && event.target.type === 'checkbox' && event.target.closest('[data-range-select]')) {
        event.preventDefault();
    }
});

document.addEventListener('click', (event) => {
    const box = event.target instanceof HTMLInputElement && event.target.type === 'checkbox' ? event.target : null;
    const scope = box?.closest('[data-range-select]');
    if (!box || !scope) {
        return;
    }
    const anchor = anchors.get(scope);
    anchors.set(scope, box);
    if (!event.shiftKey || !anchor || anchor === box || !scope.contains(anchor)) {
        return;
    }
    const boxes = boxesIn(scope);
    const [from, to] = [boxes.indexOf(anchor), boxes.indexOf(box)].sort((a, b) => a - b);
    if (from < 0) {
        return;
    }
    boxes.slice(from, to + 1).forEach((other) => {
        if (other !== box && other.checked !== box.checked) {
            other.checked = box.checked;
            other.dispatchEvent(new Event('input', { bubbles: true }));
            other.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
});
