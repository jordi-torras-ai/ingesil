<?php

namespace App\Support;

use Illuminate\Support\Arr;

class BreezyTranslation
{
    protected const OVERRIDES = [
        'en' => [
            'profile.2fa.description' => 'Two-factor authentication is required. Configure an authenticator app to continue.',
            'profile.2fa.not_enabled.description' => 'To use the application, you must enable two-factor authentication and verify codes from your authenticator app.',
        ],
        'es' => [
            'profile.2fa.description' => 'La autenticación de dos factores es obligatoria. Configura una aplicación autenticadora para continuar.',
            'profile.2fa.not_enabled.description' => 'Para usar la aplicación, debes activar la autenticación de dos factores y verificar códigos desde tu aplicación autenticadora.',
        ],
        'ca' => [
            'profile.2fa.description' => 'L’autenticació de dos factors és obligatòria. Configura una aplicació autenticadora per continuar.',
            'profile.2fa.not_enabled.description' => 'Per utilitzar l’aplicació has d’activar l’autenticació de dos factors i verificar codis des de l’aplicació autenticadora.',
        ],
    ];

    public static function get(string $key): string
    {
        $translator = app('translator');
        $loader = $translator->getLoader();
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        $override = Arr::get(self::OVERRIDES[$locale] ?? [], $key);

        if (is_string($override)) {
            return $override;
        }

        $line = Arr::get($loader->load($locale, 'default', 'filament-breezy'), $key);

        if (is_string($line)) {
            return $line;
        }

        $line = Arr::get($loader->load($fallback, 'default', 'filament-breezy'), $key);

        return is_string($line) ? $line : "filament-breezy::default.{$key}";
    }
}
