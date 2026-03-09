<x-filament-breezy::grid-section md="2" :title="\App\Support\BreezyTranslation::get('profile.personal_info.heading')" :description="\App\Support\BreezyTranslation::get('profile.personal_info.subheading')">
    <x-filament::card>
        <form wire:submit.prevent="submit" class="space-y-6">
            {{ $this->form }}

            <div class="text-right">
                <x-filament::button type="submit" form="submit" class="align-right">
                    {{ \App\Support\BreezyTranslation::get('profile.personal_info.submit.label') }}
                </x-filament::button>
            </div>
        </form>
    </x-filament::card>
</x-filament-breezy::grid-section>
