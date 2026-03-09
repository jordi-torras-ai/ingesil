<button
    x-on:click="
        window.navigator.clipboard.writeText(@js($data));
        $tooltip('{{ \App\Support\BreezyTranslation::get('clipboard.tooltip') }}', {
            timeout: 1500,
        })
    "
    type="button"
    class="fi-link inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 focus:outline-none dark:text-primary-400 dark:hover:text-primary-300"
>
    <x-filament::icon
        icon="heroicon-m-clipboard-document"
        class="fi-link-icon h-4 w-4"
    />

    <span class="fi-link-label">
        {{ \App\Support\BreezyTranslation::get('clipboard.link') }}
    </span>
</button>
