@props(['label' => null, 'sub' => null, 'primary' => false, 'align' => null, 'check' => false, 'nowrap' => false])
{{--
    Contract: table cell (check = narrow selection column). label = column name (stacked mobile layout);
    primary + sub = main text with a secondary line; nowrap keeps short values (sizes, dates) on one line;
    align end = actions cell: its buttons stay in one aligned row.
--}}
<td @if ($label !== null) data-label="{{ $label }}" @endif @if ($align === 'end') data-align="end" @endif @if ($check) data-check @endif @if ($nowrap) data-nowrap @endif {{ $attributes }}>
    @if ($align === 'end')<div class="ui-cell-actions">{{ $slot }}</div>@elseif ($primary)<span class="ui-cell-main">{{ $slot }}</span>@else{{ $slot }}@endif
    @if (filled($sub))<span class="ui-cell-sub">{{ $sub }}</span>@endif
</td>
