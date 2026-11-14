@props(['label' => null, 'sub' => null, 'primary' => false, 'align' => null, 'check' => false])
{{-- Contract: table cell (check = narrow selection column). label = column name (stacked mobile layout); primary + sub = main text with a secondary line; align end for actions. --}}
<td @if ($label !== null) data-label="{{ $label }}" @endif @if ($align === 'end') data-align="end" @endif @if ($check) data-check @endif {{ $attributes }}>
    @if ($primary)<span class="ui-cell-main">{{ $slot }}</span>@else{{ $slot }}@endif
    @if (filled($sub))<span class="ui-cell-sub">{{ $sub }}</span>@endif
</td>
