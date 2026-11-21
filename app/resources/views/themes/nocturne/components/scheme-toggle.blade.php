{{--
    Switches this device between light and dark. Behaviour and the accessible label live in
    partials/color-scheme (works without Alpine, also on authentication pages). It shows the
    scheme it switches to: a moon in light, a sun in dark. "Según el sistema" stays in Preferencias.
--}}
<button type="button" {{ $attributes->class('ui-iconbtn ui-scheme-toggle') }} data-scheme-toggle aria-label="Cambiar entre tema claro y oscuro">
    <span class="ui-scheme-icon" data-icon="sun" aria-hidden="true"><x-ui::icon name="sun" /></span>
    <span class="ui-scheme-icon" data-icon="moon" aria-hidden="true"><x-ui::icon name="moon" /></span>
</button>
