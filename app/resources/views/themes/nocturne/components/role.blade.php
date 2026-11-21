@props(['role', 'level' => 1, 'max' => 3])
{{--
    Contract: role badge. The bars show how much the role can do (level of max, from the page);
    the colour follows the role key. Purely visual: permissions are decided elsewhere.
--}}
<span {{ $attributes->class('ui-role') }} data-role="{{ $role }}">
    <span class="ui-role-level" aria-hidden="true">
        @for ($i = 1; $i <= $max; $i++)<i @class(['is-on' => $i <= $level])></i>@endfor
    </span>
    <span>{{ $slot }}</span><x-ui::sr>, nivel de permisos {{ $level }} de {{ $max }}</x-ui::sr>
</span>
