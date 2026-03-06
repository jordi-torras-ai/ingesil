<x-filament-panels::page x-data x-on:smart-search-results-ready.window="$wire.generateAnswer()">
    <form wire:submit="search" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button
                type="submit"
                icon="heroicon-m-magnifying-glass"
                wire:loading.attr="disabled"
                wire:target="search,generateAnswer"
            >
                <span wire:loading.remove wire:target="search,generateAnswer">
                    {{ __('app.smart_search.actions.search') }}
                </span>
                <span wire:loading.inline-flex wire:target="search" class="items-center">
                    {{ __('app.smart_search.actions.searching') }}
                </span>
                <span wire:loading.inline-flex wire:target="generateAnswer" class="items-center">
                    {{ __('app.smart_search.actions.thinking') }}
                </span>
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                wire:click="clearSearch"
                wire:loading.attr="disabled"
                wire:target="search,generateAnswer"
                icon="heroicon-m-x-mark"
            >
                {{ __('app.smart_search.actions.clear') }}
            </x-filament::button>

            @if ($this->hasSearched)
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('app.smart_search.messages.results_count', ['count' => $this->resultsCount]) }}
                </span>
            @endif
        </div>
    </form>

    @if ($this->hasSearched)
        <x-filament::section
            :heading="__('app.smart_search.sections.answer')"
            :description="__('app.smart_search.sections.answer_description')"
            class="mt-6"
        >
            @if ($this->answer)
                <div class="prose max-w-none dark:prose-invert">
                    {!! $this->getAnswerHtml() !!}
                </div>
            @elseif ($this->answerError)
                <div class="text-sm text-danger-600 dark:text-danger-400">
                    {{ $this->answerError }}
                </div>
            @elseif ($this->hasSearched)
                <div wire:loading.flex wire:target="search" class="items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    <span>{{ __('app.smart_search.actions.searching') }}</span>
                </div>
                <div wire:loading.flex wire:target="generateAnswer" class="items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    <span>{{ __('app.smart_search.actions.thinking') }}</span>
                </div>
            @else
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('app.smart_search.messages.no_answer') }}
                </div>
            @endif
        </x-filament::section>

        <x-filament::section
            :heading="__('app.smart_search.sections.results')"
            :description="__('app.smart_search.messages.results_description', ['count' => $this->resultsCount])"
            class="mt-6"
        >
            {{ $this->table }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
