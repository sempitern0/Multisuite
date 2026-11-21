{{--
    Consent for an external MCP client (MCP-05, docs/MCP.md). Rendered by Passport with
    $client, $user, $scopes, $request and $authToken. Without access the approve form is not
    rendered at all, and the /mcp endpoint refuses the token anyway.
--}}
@php
    $settings = \App\Models\AssistantSetting::current();
    $denial = $settings->external_enabled ? app(\App\Services\Assistant\AssistantAccess::class)->denial($user) : 'external_off';
    $clientName = \Illuminate\Support\Str::limit((string) $client->name, 80);
@endphp
<x-layouts::auth title="Conectar aplicación" eyebrow="Asistente" heading="¿Conectar «{{ $clientName }}»?"
    description="Esta aplicación quiere usar el asistente de Multisuite en tu nombre.">
    @if ($denial !== null)
        <x-ui::notice tone="warn">
            {{ $denial === 'external_off' ? 'Multisuite no permite conectar aplicaciones externas. Pídeselo a quien lo administra.' : \App\Services\Assistant\AssistantAccess::DENIALS[$denial] }}
        </x-ui::notice>
        <x-ui::form :action="route('passport.authorizations.deny')" method="DELETE">
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <x-ui::button type="submit" variant="secondary" block>Volver a la aplicación</x-ui::button>
        </x-ui::form>
    @else
        <x-ui::stack gap="md">
            <p>Si aceptas, «{{ $clientName }}» podrá hacer lo mismo que el asistente de Multisuite, siempre como {{ $user->name }} y solo con lo tuyo:</p>
            <x-ui::list>
                <li>Ver tu cuenta, tus servicios, tu espacio y los nombres de tus archivos.</li>
                <li>Buscar en tu música y en tus libros.</li>
                <li>Leer el texto de tus archivos solo si lo has permitido en la página del asistente.</li>
                <li>No podrá cambiar nada: los cambios solo se aprueban desde el chat de Multisuite.</li>
            </x-ui::list>
            <p class="ui-help">Puedes desconectarla cuando quieras en la página del asistente. Lo que consulte se envía a esa aplicación.</p>
            <x-ui::actions>
                <x-ui::form :action="route('passport.authorizations.deny')" method="DELETE">
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <x-ui::button type="submit" variant="ghost">No conectar</x-ui::button>
                </x-ui::form>
                <x-ui::form :action="route('passport.authorizations.approve')">
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <x-ui::button type="submit">Conectar</x-ui::button>
                </x-ui::form>
            </x-ui::actions>
        </x-ui::stack>
    @endif
</x-layouts::auth>
