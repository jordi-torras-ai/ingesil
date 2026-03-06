<?php

namespace App\Services;

use App\Models\Notice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SmartNoticeSearch
{
    public function __construct(
        private readonly OpenAIEmbeddings $embeddings,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Notice>
     */
    public function search(string $question, array $filters = [], int $limit = 10): Collection
    {
        $normalizedQuestion = trim($question);
        if ($normalizedQuestion === '') {
            return new Collection();
        }

        $embedding = $this->embeddings->embed($normalizedQuestion);
        $vectorLiteral = $this->toVectorLiteral($embedding);
        $limit = max(1, min(30, $limit));

        $query = Notice::query()
            ->with(['dailyJournal.source'])
            ->select('notices.*')
            ->selectRaw('embedding_vector <=> ?::vector as semantic_distance', [$vectorLiteral])
            ->selectRaw('GREATEST(0, 1 - (embedding_vector <=> ?::vector)) as similarity_score', [$vectorLiteral])
            ->whereNotNull('embedding_vector');

        $query = $this->applyFilters($query, $filters);

        return $query
            ->orderByRaw('embedding_vector <=> ?::vector asc', [$vectorLiteral])
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Builder<Notice>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Notice>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $sourceId = (int) ($filters['source_id'] ?? 0);
        if ($sourceId > 0) {
            $query->whereHas(
                'dailyJournal',
                fn (Builder $dailyJournalQuery): Builder => $dailyJournalQuery->where('source_id', $sourceId)
            );
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $query->whereHas(
                'dailyJournal',
                fn (Builder $dailyJournalQuery): Builder => $dailyJournalQuery->whereDate('issue_date', '>=', $dateFrom)
            );
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $query->whereHas(
                'dailyJournal',
                fn (Builder $dailyJournalQuery): Builder => $dailyJournalQuery->whereDate('issue_date', '<=', $dateTo)
            );
        }

        $department = trim((string) ($filters['department'] ?? ''));
        if ($department !== '') {
            $query->where('department', $department);
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $query->where('category', $category);
        }

        return $query;
    }

    /**
     * @param  list<float>  $embedding
     */
    private function toVectorLiteral(array $embedding): string
    {
        return '['.implode(',', array_map(
            static fn (float $value): string => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            $embedding
        )).']';
    }
}
