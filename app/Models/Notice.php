<?php

namespace App\Models;

use App\Casts\PgVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notice extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'daily_journal_id',
        'title',
        'category',
        'department',
        'url',
        'content',
        'extra_info',
        'embedding',
        'embedding_vector',
        'embedding_model',
        'embedding_updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'embedding_vector' => PgVector::class,
            'embedding_updated_at' => 'datetime',
        ];
    }

    public function dailyJournal(): BelongsTo
    {
        return $this->belongsTo(DailyJournal::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(NoticeAnalysis::class);
    }
}
