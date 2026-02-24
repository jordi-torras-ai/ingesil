<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    public function dailyJournal(): BelongsTo
    {
        return $this->belongsTo(DailyJournal::class);
    }
}
