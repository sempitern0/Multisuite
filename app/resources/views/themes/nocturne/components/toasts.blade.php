{{-- Listens to `ui-toast` (App\Livewire\Concerns\NotifiesInterface) and shows short confirmations. --}}
<div class="ui-toasts" aria-live="polite" aria-atomic="false"
    x-data="{
        items: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.items.push({ id, text: detail.text, tone: detail.tone || 'ok' });
            setTimeout(() => this.dismiss(id), 4200);
        },
        dismiss(id) { this.items = this.items.filter((item) => item.id !== id); },
    }"
    x-on:ui-toast.window="push($event.detail)">
    <template x-for="item in items" x-bind:key="item.id">
        <div class="ui-toast" x-bind:data-tone="item.tone" role="status">
            <span x-text="item.text"></span>
            <button type="button" class="ui-toast-close" x-on:click="dismiss(item.id)" aria-label="Cerrar aviso"><x-ui::icon name="close" /></button>
        </div>
    </template>
</div>
