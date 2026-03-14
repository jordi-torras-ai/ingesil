<x-filament-panels::page.simple>
    <x-filament-panels::form wire:submit="authenticate">
        <div class="mx-auto grid w-full max-w-xl gap-y-8">
            <div class="grid gap-y-4">
                <x-filament-forms::field-wrapper.label :required="true">
                    {{ $this->usingRecoveryCode ? \App\Support\BreezyTranslation::get('fields.2fa_recovery_code') : __('app.auth.two_factor_code_prompt') }}
                </x-filament-forms::field-wrapper.label>

                @if ($this->usingRecoveryCode)
                    <x-filament::input.wrapper :valid="! $errors->has('code')">
                        <x-filament::input
                            autocomplete="off"
                            autofocus
                            class="text-center"
                            placeholder="{{ \App\Support\BreezyTranslation::get('two_factor.recovery_code_placeholder') }}"
                            type="text"
                            wire:model.live="code"
                        />
                    </x-filament::input.wrapper>
                @else
                    <div
                        x-data="{
                            code: $wire.entangle('code').live,
                            digits: ['', '', '', '', '', ''],
                            init() {
                                this.syncFromCode(this.code ?? '')

                                this.$watch('code', (value) => {
                                    const normalized = this.normalize(value)

                                    if (normalized !== this.digits.join('')) {
                                        this.syncFromCode(normalized)
                                    }
                                })
                            },
                            normalize(value) {
                                return String(value ?? '').replace(/\D/g, '').slice(0, 6)
                            },
                            syncFromCode(value) {
                                const normalized = this.normalize(value)
                                this.digits = normalized.padEnd(6, ' ').split('').map((digit) => digit.trim())
                                this.code = normalized
                            },
                            updateCode() {
                                this.code = this.digits.join('')
                            },
                            autoSubmit() {
                                if (this.code.length !== 6) {
                                    return
                                }

                                this.$nextTick(() => {
                                    this.$root.closest('form')?.requestSubmit()
                                })
                            },
                            focusDigit(index) {
                                this.$refs[`digit${index}`]?.focus()
                                this.$refs[`digit${index}`]?.select()
                            },
                            fillDigits(value) {
                                const normalized = this.normalize(value)

                                this.digits = normalized.padEnd(6, ' ').split('').map((digit) => digit.trim())
                                this.updateCode()
                                this.autoSubmit()

                                const nextIndex = Math.min(normalized.length, 5)
                                this.focusDigit(nextIndex)
                            },
                            handleInput(index, event) {
                                const sanitized = this.normalize(event.target.value)

                                if (sanitized.length > 1) {
                                    this.fillDigits(sanitized)
                                    return
                                }

                                this.digits[index] = sanitized
                                this.updateCode()
                                this.autoSubmit()

                                if (sanitized !== '' && index < 5) {
                                    this.focusDigit(index + 1)
                                }
                            },
                            handleKeydown(index, event) {
                                if (event.key === 'Backspace' && this.digits[index] === '' && index > 0) {
                                    this.focusDigit(index - 1)
                                    return
                                }

                                if (event.key === 'ArrowLeft' && index > 0) {
                                    event.preventDefault()
                                    this.focusDigit(index - 1)
                                    return
                                }

                                if (event.key === 'ArrowRight' && index < 5) {
                                    event.preventDefault()
                                    this.focusDigit(index + 1)
                                }
                            },
                            handlePaste(event) {
                                const pasted = this.normalize(event.clipboardData?.getData('text') ?? '')

                                if (pasted === '') {
                                    return
                                }

                                event.preventDefault()
                                this.fillDigits(pasted)
                            },
                        }"
                        class="grid gap-y-4"
                    >
                        <div
                            style="
                                display: grid;
                                grid-template-columns: repeat(6, minmax(0, 1fr));
                                gap: 0.4rem;
                                max-width: 18rem;
                                width: 100%;
                            "
                        >
                            @for ($index = 0; $index < 6; $index++)
                                <input
                                    x-ref="digit{{ $index }}"
                                    x-model="digits[{{ $index }}]"
                                    x-on:focus="$event.target.select()"
                                    x-on:input="handleInput({{ $index }}, $event)"
                                    x-on:keydown="handleKeydown({{ $index }}, $event)"
                                    x-on:paste="handlePaste($event)"
                                    @if ($index === 0) autofocus @endif
                                    autocomplete="{{ $index === 0 ? 'one-time-code' : 'off' }}"
                                    class="block min-w-0 border border-gray-300 bg-white/60 px-0 text-center text-lg font-semibold text-gray-950 shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-white/15 dark:bg-white/5 dark:text-white"
                                    inputmode="numeric"
                                    maxlength="1"
                                    pattern="[0-9]*"
                                    style="
                                        width: 100%;
                                        height: 2.85rem;
                                        border-radius: 0.65rem;
                                        box-sizing: border-box;
                                    "
                                    type="text"
                                />
                            @endfor
                        </div>
                    </div>
                @endif

                <div>
                    <x-filament::link
                        class="text-base font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                        href="#"
                        wire:click.prevent="toggleRecoveryCode"
                    >
                        {{ $this->usingRecoveryCode ? \App\Support\BreezyTranslation::get('cancel') : \App\Support\BreezyTranslation::get('two_factor.recovery_code_link') }}
                    </x-filament::link>
                </div>

                @error('code')
                    <x-filament-forms::field-wrapper.error-message>
                        {{ $message }}
                    </x-filament-forms::field-wrapper.error-message>
                @enderror
            </div>
        </div>

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />

        <div class="mt-6 flex justify-center">
            <x-filament::link
                class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                href="#"
                wire:click.prevent="logoutAndReset"
            >
                {{ __('app.auth.sign_out') }}
            </x-filament::link>
        </div>
    </x-filament-panels::form>
</x-filament-panels::page.simple>
