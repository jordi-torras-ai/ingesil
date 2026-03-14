<?php

namespace App\Models;

use App\Models\Concerns\LogsAdminActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory;
    use LogsAdminActivity;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'base_url',
        'slug',
        'start_at',
        'comments',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'date',
        ];
    }

    public function dailyJournals(): HasMany
    {
        return $this->hasMany(DailyJournal::class);
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'description',
            'base_url',
            'slug',
            'start_at',
            'comments',
        ];
    }
}
