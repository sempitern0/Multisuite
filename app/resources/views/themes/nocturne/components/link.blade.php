@props(['href' => null])
{{-- Contract: inline text action. Without href it is a button (pass type/x-on as attributes). --}}
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'ui-link']) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => 'ui-link']) }}>{{ $slot }}</button>
@endif
