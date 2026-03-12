<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureOptionTranslation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'feature_option_id',
        'locale',
        'label',
    ];

    public function featureOption(): BelongsTo
    {
        return $this->belongsTo(FeatureOption::class);
    }
}
