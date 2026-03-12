<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyFeatureAnswer;
use App\Models\Feature;
use App\Models\FeatureOption;
use App\Models\Scope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ImportCompanyFeatureAnswersCommand extends Command
{
    protected $signature = 'companies:import-feature-answers
        {company : Company ID or exact name}
        {scope : Scope code}
        {file : TSV/CSV file with feature label and answer columns}
        {--locale=es : Locale used to match feature and option labels}
        {--dry-run : Parse and validate without writing data}';

    protected $description = 'Import company feature answers for one scope from a TSV/CSV file.';

    public function handle(): int
    {
        try {
            $company = $this->resolveCompany((string) $this->argument('company'));
            $scope = $this->resolveScope((string) $this->argument('scope'));
            $locale = trim(Str::lower((string) $this->option('locale'))) ?: 'es';
            $path = $this->resolveFilePath((string) $this->argument('file'));
            $rows = $this->parseRows($path);
            $features = $this->featuresByNormalizedLabel($scope, $locale);

            if ($rows->isEmpty()) {
                $this->warn('No data rows found.');
                return self::SUCCESS;
            }

            $results = [];
            $errors = [];

            foreach ($rows as $index => $row) {
                $featureLabel = trim((string) ($row['feature'] ?? ''));
                $answerLabel = trim((string) ($row['answer'] ?? ''));

                if ($featureLabel === '' || $answerLabel === '') {
                    continue;
                }

                $feature = $features[$this->normalizeLabel($featureLabel)] ?? null;
                if (! $feature instanceof Feature) {
                    $errors[] = "Row {$index}: feature not found for label \"{$featureLabel}\".";
                    continue;
                }

                try {
                    $payload = $this->mapAnswerPayload($feature, $answerLabel, $locale);
                } catch (RuntimeException $exception) {
                    $errors[] = "Row {$index} ({$featureLabel}): {$exception->getMessage()}";
                    continue;
                }

                $results[] = [
                    'feature' => $feature,
                    'payload' => $payload,
                ];
            }

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $this->line(sprintf(
                'Importing %d answers for company #%d (%s) and scope %s.',
                count($results),
                $company->id,
                $company->name,
                $scope->code,
            ));

            if ((bool) $this->option('dry-run')) {
                $this->info('Dry run completed successfully. No data written.');
                return self::SUCCESS;
            }

            $company->scopes()->syncWithoutDetaching([$scope->id]);

            foreach ($results as $result) {
                /** @var Feature $feature */
                $feature = $result['feature'];
                /** @var array<string, mixed> $payload */
                $payload = $result['payload'];

                CompanyFeatureAnswer::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'feature_id' => $feature->id,
                    ],
                    $payload,
                );
            }

            $this->info(sprintf('Imported %d answers successfully.', count($results)));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function resolveCompany(string $value): Company
    {
        $company = ctype_digit($value)
            ? Company::query()->find((int) $value)
            : Company::query()->where('name', $value)->first();

        if (! $company instanceof Company) {
            throw new RuntimeException("Company not found: {$value}");
        }

        return $company;
    }

    private function resolveScope(string $code): Scope
    {
        $scope = Scope::query()->with('translations')->where('code', trim($code))->first();

        if (! $scope instanceof Scope) {
            throw new RuntimeException("Scope not found: {$code}");
        }

        return $scope;
    }

    private function resolveFilePath(string $value): string
    {
        $path = is_file($value) ? $value : base_path($value);

        if (! is_file($path)) {
            throw new RuntimeException("Import file not found: {$value}");
        }

        return $path;
    }

    /**
     * @return Collection<int, array{feature: string, answer: string}>
     */
    private function parseRows(string $path): Collection
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException("Unable to read import file: {$path}");
        }

        $delimiter = $this->detectDelimiter($lines);

        return collect($lines)
            ->values()
            ->map(function (string $line, int $offset) use ($delimiter): ?array {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return null;
                }

                $columns = str_getcsv($line, $delimiter);
                if (count($columns) < 2) {
                    return null;
                }

                return [
                    'row' => $offset + 1,
                    'feature' => trim((string) $columns[0]),
                    'answer' => trim((string) $columns[1]),
                ];
            })
            ->filter()
            ->reject(fn (array $row): bool => $this->looksLikeHeaderRow($row))
            ->values();
    }

    private function detectDelimiter(array $lines): string
    {
        $sample = collect($lines)
            ->first(fn (string $line): bool => trim($line) !== '');

        if (! is_string($sample)) {
            return "\t";
        }

        return match (true) {
            str_contains($sample, "\t") => "\t",
            str_contains($sample, ';') => ';',
            default => ',',
        };
    }

    /**
     * @return array<string, Feature>
     */
    private function featuresByNormalizedLabel(Scope $scope, string $locale): array
    {
        $features = Feature::query()
            ->with(['translations', 'options.translations'])
            ->where('scope_id', $scope->id)
            ->get();

        $map = [];

        foreach ($features as $feature) {
            $map[$this->normalizeLabel($feature->label($locale))] = $feature;
            $map[$this->normalizeLabel($feature->code)] = $feature;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAnswerPayload(Feature $feature, string $answerLabel, string $locale): array
    {
        return match ($feature->data_type) {
            Feature::DATA_TYPE_BOOLEAN => $this->mapBooleanAnswer($answerLabel),
            Feature::DATA_TYPE_SINGLE_CHOICE => $this->mapSingleChoiceAnswer($feature, $answerLabel, $locale),
            Feature::DATA_TYPE_TEXT => $this->mapTextAnswer($answerLabel),
            default => throw new RuntimeException("Unsupported feature data type: {$feature->data_type}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBooleanAnswer(string $answerLabel): array
    {
        $normalized = $this->normalizeLabel($answerLabel);

        $trueValues = ['si', 'sí', 'yes', 'true', '1'];
        $falseValues = ['no', 'false', '0'];

        if (in_array($normalized, $trueValues, true)) {
            return [
                'feature_option_id' => null,
                'value_boolean' => true,
                'value_text' => null,
            ];
        }

        if (in_array($normalized, $falseValues, true)) {
            return [
                'feature_option_id' => null,
                'value_boolean' => false,
                'value_text' => null,
            ];
        }

        throw new RuntimeException("Boolean answer not recognized: {$answerLabel}");
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSingleChoiceAnswer(Feature $feature, string $answerLabel, string $locale): array
    {
        $feature->loadMissing('options.translations');

        $normalized = $this->normalizeLabel($answerLabel);

        $option = $feature->options->first(function (FeatureOption $option) use ($normalized, $locale): bool {
            return $this->normalizeLabel($option->code) === $normalized
                || $this->normalizeLabel($option->label($locale)) === $normalized
                || $this->normalizeLabel($option->label('es')) === $normalized
                || $this->normalizeLabel($option->label('en')) === $normalized;
        });

        if (! $option && in_array($normalized, ['no', 'none', 'ninguno', 'ninguna', 'sin'], true)) {
            $option = $feature->options->firstWhere('code', 'none');
        }

        if (! $option instanceof FeatureOption) {
            throw new RuntimeException("Choice answer not recognized: {$answerLabel}");
        }

        return [
            'feature_option_id' => $option->id,
            'value_boolean' => null,
            'value_text' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTextAnswer(string $answerLabel): array
    {
        return [
            'feature_option_id' => null,
            'value_boolean' => null,
            'value_text' => trim($answerLabel),
        ];
    }

    /**
     * @param  array{row:int, feature:string, answer:string}  $row
     */
    private function looksLikeHeaderRow(array $row): bool
    {
        $feature = $this->normalizeLabel($row['feature']);

        return in_array($feature, [
            'caracteristicas tecnicas',
            'caracteristicas tecniques',
            'technical features',
            'feature',
        ], true);
    }

    private function normalizeLabel(string $value): string
    {
        $normalized = Str::lower(Str::ascii(trim($value)));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
