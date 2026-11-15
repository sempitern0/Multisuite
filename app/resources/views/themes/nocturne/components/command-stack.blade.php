@props(['selecting'])
{{--
    Contract: holds a filter toolbar and an x-ui::selection-bar in the same place, so
    selecting the first item swaps one for the other without moving the list below
    (no layout jump under the pointer). `selecting` is the Alpine expression that is true
    while something is selected; the hidden toolbar becomes inert, and the stack stays at
    the top of the screen while scrolling with a selection.
--}}
<div {{ $attributes->merge(['class' => 'ui-command-stack']) }} x-bind:data-selecting="{{ $selecting }}">{{ $slot }}</div>
