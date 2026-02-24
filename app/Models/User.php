<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_REGULAR = 'regular';
    public const LOCALE_EN = 'en';
    public const LOCALE_ES = 'es';
    public const LOCALE_CA = 'ca';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * @return array<int, string>
     */
    public static function supportedLocales(): array
    {
        return [
            self::LOCALE_EN,
            self::LOCALE_ES,
            self::LOCALE_CA,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        return [
            self::LOCALE_EN => __('app.locales.en'),
            self::LOCALE_ES => __('app.locales.es'),
            self::LOCALE_CA => __('app.locales.ca'),
        ];
    }
}
