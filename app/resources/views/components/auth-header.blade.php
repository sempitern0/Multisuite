@props([
    'title',
    'description',
])

<div class="flex w-full flex-col gap-3 text-center">
    <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
