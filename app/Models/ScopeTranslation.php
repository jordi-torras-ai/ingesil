<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScopeTranslation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'scope_id',
        'locale',
        'name',
        'description',
    ];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }
}
