<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlerRun extends Model
{
    use HasFactory;

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'source_id',
        'source_slug',
        'issue_date',
        'run_id',
        'mode',
        'triggered_by',
        'status',
        'exit_code',
        'run_directory',
        'log_path',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function absoluteLogPath(): ?string
    {
        $path = trim((string) $this->log_path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    public function hasLogFile(): bool
    {
        $path = $this->absoluteLogPath();

        return $path !== null && is_file($path);
    }

    public function readLogPreview(int $maxBytes = 24000): string
    {
        $path = $this->absoluteLogPath();

        if ($path === null || ! is_file($path)) {
            return __('app.crawler_runs.messages.log_not_available');
        }

        $content = (string) file_get_contents($path);

        if (strlen($content) <= $maxBytes) {
            return $content;
        }

        return "…\n".substr($content, -1 * $maxBytes);
    }

    public function durationLabel(): ?string
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return CarbonInterval::seconds($this->finished_at->diffInSeconds($this->started_at))
            ->cascade()
            ->forHumans(short: true);
    }
}
