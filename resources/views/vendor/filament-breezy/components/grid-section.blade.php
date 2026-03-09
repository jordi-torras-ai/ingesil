@props(['title', 'description'])

<section {{ $attributes->class(['space-y-4 pt-6 filament-breezy-grid-section']) }}>
    <div class="space-y-1">
        <h3 class="text-lg font-medium filament-breezy-grid-title">{{ $title }}</h3>

        <p class="text-sm text-gray-500 filament-breezy-grid-description">
            {{ $description }}
        </p>
    </div>

    <div>
        {{ $slot }}
    </div>
</section>
