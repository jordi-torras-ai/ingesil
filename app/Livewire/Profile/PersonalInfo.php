<?php

namespace App\Livewire\Profile;

use App\Models\User;
use App\Support\BreezyTranslation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo as BreezyPersonalInfo;

class PersonalInfo extends BreezyPersonalInfo
{
    protected string $view = 'filament-breezy::livewire.personal-info';

    public static function canView(): bool
    {
        return Filament::auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->user = Filament::getCurrentPanel()->auth()->user();
        $this->userClass = get_class($this->user);
        $this->hasAvatars = false;
        $this->only = $this->user->isAdmin()
            ? ['name', 'email']
            : [];

        if (in_array($this->user->locale, User::supportedLocales(), true)) {
            session(['locale' => $this->user->locale]);
            App::setLocale($this->user->locale);
        }

        $this->form->fill($this->user->only($this->only));
    }

    protected function getProfileFormComponents(): array
    {
        if ($this->user->isAdmin()) {
            return [
                $this->getNameComponent(),
                $this->getEmailComponent(),
            ];
        }

        return [];
    }

    public function submit(): void
    {
        $state = $this->form->getState();
        $data = collect($this->only)
            ->filter(fn (string $attribute): bool => array_key_exists($attribute, $state))
            ->mapWithKeys(fn (string $attribute): array => [$attribute => $state[$attribute]])
            ->all();

        $this->user->forceFill($data)->save();
        $this->user->refresh();
        Filament::auth()->setUser($this->user);
        $this->form->fill($this->user->only($this->only));

        $this->sendNotification();
    }

    protected function sendNotification(): void
    {
        \Filament\Notifications\Notification::make()
            ->success()
            ->title(BreezyTranslation::get('profile.personal_info.notify'))
            ->send();
    }
}
