// Uploads for Mis archivos: tiny and event-delegated, so it works after SPA navigation.
import './files';
// Image viewer and video fallback for the preview component.
import './viewer';
// Side menu collapse button.
import './sidebar';
// Shift + click selects a range of checkboxes.
import './range-select';
// Profile photo editor (crop and upload).
import './avatar-editor';

function updateOfflineNotice() {
    const notice = document.querySelector('[data-offline-notice]');
    if (notice) notice.hidden = navigator.onLine;
}

window.addEventListener('online', updateOfflineNotice);
window.addEventListener('offline', updateOfflineNotice);
window.addEventListener('pageshow', updateOfflineNotice);
document.addEventListener('livewire:navigated', updateOfflineNotice);
updateOfflineNotice();
