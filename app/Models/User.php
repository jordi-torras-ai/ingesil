<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\LogsAdminActivity;
use Filament\Models\Contracts\FilamentUser;
use Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, CanResetPasswordContract
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    use CanResetPasswordTrait;
    use LogsAdminActivity;
    use TwoFactorAuthenticatable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_COMPANY_ADMIN = 'company_admin';
    public const ROLE_REGULAR = 'regular';
    public const LOCALE_EN = 'en';
    public const LOCALE_ES = 'es';
    public const LOCALE_CA = 'ca';
    public const NOTICE_DIGEST_DAILY = 'daily';
    public const NOTICE_DIGEST_WEEKLY = 'weekly';
    public const NOTICE_DIGEST_MONTHLY = 'monthly';
    public const NOTICE_DIGEST_NEVER = 'never';

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
        'notice_digest_frequency',
        'notify_if_pending_tasks',
        'notify_if_new_relevant_notices',
        'last_notice_digest_sent_at',
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
            'notify_if_pending_tasks' => 'boolean',
            'notify_if_new_relevant_notices' => 'boolean',
            'last_notice_digest_sent_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->isPlatformAdmin();
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === self::ROLE_COMPANY_ADMIN;
    }

    public function canManageUsers(): bool
    {
        return $this->isPlatformAdmin() || $this->isCompanyAdmin();
    }

    public function canManageSubscriptions(): bool
    {
        return $this->isPlatformAdmin();
    }

    public function canManageCompany(Company $company): bool
    {
        return $this->isPlatformAdmin()
            || ($this->isCompanyAdmin() && $this->companies()->whereKey($company->getKey())->exists());
    }

    public function canAccessCompany(Company $company): bool
    {
        return $this->isPlatformAdmin() || $this->companies()->whereKey($company->getKey())->exists();
    }

    public function canManageUser(User $managedUser): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        if (! $this->isCompanyAdmin() || $managedUser->isPlatformAdmin()) {
            return false;
        }

        return $managedUser->companies()
            ->whereIn('companies.id', $this->managedCompanyIds())
            ->exists();
    }

    /**
     * @return list<int>
     */
    public function managedCompanyIds(): array
    {
        if ($this->isPlatformAdmin()) {
            return Company::query()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (int $id): int => (int) $id)
                ->all();
        }

        if (! $this->canManageUsers()) {
            return [];
        }

        return $this->companies()
            ->orderBy('companies.id')
            ->pluck('companies.id')
            ->map(fn (int $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function assignableRoleOptionsFor(?self $actingUser): array
    {
        if (! $actingUser) {
            return [];
        }

        if ($actingUser->isPlatformAdmin()) {
            return [
                self::ROLE_ADMIN => __('app.roles.admin'),
                self::ROLE_COMPANY_ADMIN => __('app.roles.company_admin'),
                self::ROLE_REGULAR => __('app.roles.regular'),
            ];
        }

        if ($actingUser->isCompanyAdmin()) {
            return [
                self::ROLE_COMPANY_ADMIN => __('app.roles.company_admin'),
                self::ROLE_REGULAR => __('app.roles.regular'),
            ];
        }

        return [];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
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

    /**
     * @return array<string, string>
     */
    public static function noticeDigestFrequencyOptions(): array
    {
        return [
            self::NOTICE_DIGEST_DAILY => __('app.notice_digests.frequencies.daily'),
            self::NOTICE_DIGEST_WEEKLY => __('app.notice_digests.frequencies.weekly'),
            self::NOTICE_DIGEST_MONTHLY => __('app.notice_digests.frequencies.monthly'),
            self::NOTICE_DIGEST_NEVER => __('app.notice_digests.frequencies.never'),
        ];
    }

    public function noticeAnalysisRuns(): HasMany
    {
        return $this->hasMany(NoticeAnalysisRun::class, 'requested_by_user_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withTimestamps();
    }

    public function companyNoticeAnalysisEvents(): HasMany
    {
        return $this->hasMany(CompanyNoticeAnalysisEvent::class);
    }

    public function notificationDigestRuns(): HasMany
    {
        return $this->hasMany(NotificationDigestRun::class);
    }

    public function preferredLocaleForNotifications(): string
    {
        return in_array($this->locale, self::supportedLocales(), true)
            ? $this->locale
            : config('app.locale', self::LOCALE_EN);
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'email',
            'role',
            'locale',
            'notice_digest_frequency',
            'notify_if_pending_tasks',
            'notify_if_new_relevant_notices',
        ];
    }
}
