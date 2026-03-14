<?php

namespace App\Filament\Pages;

use App\Filament\Resources\NoticeResource;
use App\Models\Notice;
use App\Models\Source;
use App\Services\SmartNoticeAnswerer;
use App\Services\SmartNoticeSearch;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class SmartSearch extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.smart-search';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var list<int>
     */
    public array $resultNoticeIds = [];

    /**
     * @var array<int, float>
     */
    public array $resultSimilarityScores = [];

    public ?string $answer = null;

    public ?string $answerError = null;

    public bool $hasSearched = false;

    public int $resultsCount = 0;

    public function mount(): void
    {
        $this->form->fill([
            'query' => '',
            'source_id' => null,
            'date_from' => null,
            'date_to' => null,
            'department' => null,
            'category' => null,
            'limit' => 10,
        ]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getNavigationLabel(): string
    {
        return __('app.smart_search.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.groups.workspace');
    }

    public function getTitle(): string
    {
        return __('app.smart_search.title');
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make(__('app.smart_search.sections.query'))
                    ->description(__('app.smart_search.sections.query_description'))
                    ->schema([
                        Forms\Components\Textarea::make('query')
                            ->label(__('app.smart_search.fields.query'))
                            ->rows(3)
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\Select::make('source_id')
                            ->label(__('app.smart_search.fields.source'))
                            ->options(function (): array {
                                return Source::query()
                                    ->select('sources.id', 'sources.name')
                                    ->selectRaw('COUNT(notices.id) as aggregate')
                                    ->join('daily_journals', 'daily_journals.source_id', '=', 'sources.id')
                                    ->join('notices', 'notices.daily_journal_id', '=', 'daily_journals.id')
                                    ->groupBy('sources.id', 'sources.name')
                                    ->orderBy('sources.name')
                                    ->get()
                                    ->mapWithKeys(fn (Source $source): array => [
                                        (string) $source->id => sprintf('%s (%d)', $source->name, $source->aggregate),
                                    ])
                                    ->all();
                            })
                            ->searchable()
                            ->native(false),
                        Forms\Components\DatePicker::make('date_from')
                            ->label(__('app.smart_search.fields.date_from'))
                            ->native(false),
                        Forms\Components\DatePicker::make('date_to')
                            ->label(__('app.smart_search.fields.date_to'))
                            ->native(false),
                        Forms\Components\Select::make('department')
                            ->label(__('app.smart_search.fields.department'))
                            ->options(function (): array {
                                return Notice::query()
                                    ->select('department')
                                    ->selectRaw('COUNT(*) as aggregate')
                                    ->whereNotNull('department')
                                    ->where('department', '<>', '')
                                    ->groupBy('department')
                                    ->orderBy('department')
                                    ->get()
                                    ->mapWithKeys(fn (Notice $notice): array => [
                                        (string) $notice->department => sprintf('%s (%d)', $notice->department, $notice->aggregate),
                                    ])
                                    ->all();
                            })
                            ->searchable()
                            ->native(false),
                        Forms\Components\Select::make('category')
                            ->label(__('app.smart_search.fields.category'))
                            ->options(function (): array {
                                return Notice::query()
                                    ->select('category')
                                    ->selectRaw('COUNT(*) as aggregate')
                                    ->whereNotNull('category')
                                    ->where('category', '<>', '')
                                    ->groupBy('category')
                                    ->orderBy('category')
                                    ->get()
                                    ->mapWithKeys(fn (Notice $notice): array => [
                                        (string) $notice->category => sprintf('%s (%d)', $notice->category, $notice->aggregate),
                                    ])
                                    ->all();
                            })
                            ->searchable()
                            ->native(false),
                        Forms\Components\Select::make('limit')
                            ->label(__('app.smart_search.fields.limit'))
                            ->options([
                                10 => '10',
                                20 => '20',
                                30 => '30',
                            ])
                            ->default(10)
                            ->required()
                            ->native(false),
                    ])
                    ->columns(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label(__('app.notices.fields.id'))
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('similarity_score')
                    ->label(__('app.smart_search.fields.relevance'))
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn ($state): string => number_format(((float) $state) * 100, 1).'%')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('dailyJournal.issue_date')
                    ->label(__('app.notices.fields.daily_journal'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dailyJournal.source.name')
                    ->label(__('app.smart_search.fields.source'))
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('app.notices.fields.title'))
                    ->limit(80)
                    ->tooltip(fn (Notice $record): string => $record->title),
                Tables\Columns\TextColumn::make('category')
                    ->label(__('app.notices.fields.category'))
                    ->badge(),
                Tables\Columns\TextColumn::make('department')
                    ->label(__('app.notices.fields.department'))
                    ->badge(),
                Tables\Columns\IconColumn::make('url')
                    ->label(__('app.notices.fields.url'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Notice $record): ?string => $record->url)
                    ->openUrlInNewTab()
                    ->alignCenter(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('open_official')
                        ->label(__('app.smart_search.actions.open_official'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (Notice $record): ?string => $record->url)
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('view_internal')
                        ->label(__('app.smart_search.actions.view_notice'))
                        ->icon('heroicon-o-eye')
                        ->url(fn (Notice $record): string => NoticeResource::getUrl('view', ['record' => $record]))
                        ->visible(fn (): bool => auth()->user()?->isPlatformAdmin() ?? false),
                ])->tooltip(__('app.common.actions')),
            ])
            ->emptyStateHeading(__('app.smart_search.messages.empty_heading'))
            ->emptyStateDescription(__('app.smart_search.messages.empty_description'));
    }

    public function search(): void
    {
        $state = $this->form->getState();
        $question = $this->getCurrentQuestion();

        $this->hasSearched = true;
        $this->answer = null;
        $this->answerError = null;

        $results = app(SmartNoticeSearch::class)->search($question, $state, (int) ($state['limit'] ?? 10));
        $this->resultNoticeIds = $results->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->resultSimilarityScores = $results->mapWithKeys(fn (Notice $notice): array => [
            (int) $notice->id => (float) ($notice->similarity_score ?? 0.0),
        ])->all();
        $this->resultsCount = count($this->resultNoticeIds);
        $this->resetTable();

        if ($results->isEmpty()) {
            $this->answer = __('app.smart_search.messages.no_results');
            return;
        }

        $this->dispatch('smart-search-results-ready');
    }

    public function generateAnswer(): void
    {
        if ($this->resultNoticeIds === []) {
            return;
        }

        try {
            $this->answer = app(SmartNoticeAnswerer::class)->answer(
                $this->getCurrentQuestion(),
                $this->getResultNotices(),
                auth()->user()?->locale ?? app()->getLocale()
            );
        } catch (\Throwable $exc) {
            $this->answerError = $exc->getMessage();

            Notification::make()
                ->danger()
                ->title(__('app.smart_search.messages.answer_error_title'))
                ->body($exc->getMessage())
                ->send();
        }
    }

    public function clearSearch(): void
    {
        $this->resultNoticeIds = [];
        $this->resultSimilarityScores = [];
        $this->resultsCount = 0;
        $this->answer = null;
        $this->answerError = null;
        $this->hasSearched = false;
        $this->resetTable();

        $this->form->fill([
            'query' => '',
            'source_id' => null,
            'date_from' => null,
            'date_to' => null,
            'department' => null,
            'category' => null,
            'limit' => 10,
        ]);
    }

    public function getCurrentQuestion(): string
    {
        return trim((string) ($this->form->getState()['query'] ?? ''));
    }

    public function getAnswerHtml(): HtmlString
    {
        return new HtmlString(Str::markdown((string) $this->answer, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }

    /**
     * @return Builder<Notice>
     */
    private function getTableQuery(): Builder
    {
        if ($this->resultNoticeIds === []) {
            return Notice::query()->whereRaw('1 = 0');
        }

        $orderedIds = array_values(array_unique(array_map('intval', $this->resultNoticeIds)));
        $scoreMap = $this->resultSimilarityScores;

        $orderCase = 'CASE notices.id '.implode(' ', array_map(
            static fn (int $noticeId, int $index): string => sprintf('WHEN %d THEN %d', $noticeId, $index),
            $orderedIds,
            array_keys($orderedIds)
        )).' END';

        $scoreCase = 'CASE notices.id '.implode(' ', array_map(
            static fn (int $noticeId): string => sprintf(
                'WHEN %d THEN %F',
                $noticeId,
                (float) ($scoreMap[$noticeId] ?? 0.0)
            ),
            $orderedIds
        )).' ELSE 0 END';

        return Notice::query()
            ->with(['dailyJournal.source'])
            ->select('notices.*')
            ->selectRaw($scoreCase.' as similarity_score')
            ->whereIn('notices.id', $orderedIds)
            ->orderByRaw($orderCase);
    }

    /**
     * @return Collection<int, Notice>
     */
    private function getResultNotices(): Collection
    {
        if ($this->resultNoticeIds === []) {
            return new Collection();
        }

        $orderedIds = array_values(array_unique(array_map('intval', $this->resultNoticeIds)));
        $records = Notice::query()
            ->with(['dailyJournal.source'])
            ->whereIn('id', $orderedIds)
            ->get()
            ->keyBy('id');

        return new Collection(
            collect($orderedIds)
                ->map(fn (int $noticeId): ?Notice => $records->get($noticeId))
                ->filter()
                ->values()
                ->all()
        );
    }
}
