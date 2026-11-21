{{--
    Contract: main content with a side column (aside slot) that stays in view while scrolling on
    wide screens and goes below the main content on narrow ones.
--}}
<div {{ $attributes->merge(['class' => 'ui-columns']) }}>
    <div class="ui-columns-main">{{ $slot }}</div>
    @isset($aside)<aside class="ui-columns-aside">{{ $aside }}</aside>@endisset
</div>
