<?php

namespace App\Services;

use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionPackage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionBankService
{
    public const DEFAULT_SECTIONS = [
        ['code' => 'verbal', 'name' => 'Verbal', 'order_number' => 1, 'duration_minutes' => 50, 'target_question_count' => 50],
        ['code' => 'kuantitatif', 'name' => 'Kuantitatif', 'order_number' => 2, 'duration_minutes' => 40, 'target_question_count' => 70],
        ['code' => 'logika', 'name' => 'Logika', 'order_number' => 3, 'duration_minutes' => 40, 'target_question_count' => 60],
        ['code' => 'spasial', 'name' => 'Spasial', 'order_number' => 4, 'duration_minutes' => 20, 'target_question_count' => 20],
    ];

    public const IMPORT_COLUMNS = [
        'section_code',
        'order_number',
        'question_text',
        'question_image',
        'option_a_text',
        'option_a_image',
        'option_b_text',
        'option_b_image',
        'option_c_text',
        'option_c_image',
        'option_d_text',
        'option_d_image',
        'correct_option',
        'score',
        'difficulty',
        'explanation',
    ];

    public function createDefaultSections(QuestionPackage $package, array $sectionSettings = []): void
    {
        foreach (self::DEFAULT_SECTIONS as $section) {
            $setting = collect($sectionSettings)->firstWhere('code', $section['code']) ?? [];
            $package->sections()->firstOrCreate(
                ['code' => $section['code']],
                [
                    'name' => $section['name'],
                    'description' => null,
                    'order_number' => $section['order_number'],
                    'duration_minutes' => (int) ($setting['duration_minutes'] ?? $section['duration_minutes']),
                    'question_count' => 0,
                    'target_question_count' => (int) ($setting['target_question_count'] ?? $section['target_question_count']),
                ],
            );
        }
    }

    public function packagePayload(QuestionPackage $package): array
    {
        $package->loadMissing(['sections' => fn ($query) => $query->withCount('questions')->orderBy('order_number')]);
        $summary = [];
        foreach (self::DEFAULT_SECTIONS as $section) {
            $summary[$section['code']] = 0;
        }
        foreach ($package->sections as $section) {
            $summary[$section->code ?? $section->name] = (int) $section->question_count;
        }

        return [
            'id' => $package->id,
            'name' => $package->name,
            'status' => $package->status,
            'total_duration_minutes' => (int) $package->total_duration_minutes,
            'total_questions' => (int) $package->total_questions,
            'target_total_questions' => (int) $package->target_total_questions,
            'created_by' => $package->created_by,
            'created_at' => $package->created_at?->toISOString(),
            'updated_at' => $package->updated_at?->toISOString(),
            'sections' => $package->sections->map(fn (ExamSection $section) => [
                'id' => $section->id,
                'code' => $section->code,
                'name' => $section->name,
                'description' => $section->description,
                'order_number' => (int) $section->order_number,
                'duration_minutes' => (int) $section->duration_minutes,
                'question_count' => (int) $section->question_count,
                'target_question_count' => (int) $section->target_question_count,
                'questions_count' => (int) ($section->questions_count ?? $section->question_count),
            ])->values(),
            'summary' => $summary,
        ];
    }

    public function normalizeQuestionPayload(array $data, QuestionPackage $package): array
    {
        $section = $package->sections()->whereKey($data['exam_section_id'])->first();
        if (! $section) {
            throw ValidationException::withMessages(['exam_section_id' => 'Section tidak termasuk paket soal ini.']);
        }

        if (blank($data['question_text'] ?? null) && blank($data['question_image'] ?? null)) {
            throw ValidationException::withMessages(['question_text' => 'Soal wajib memiliki teks atau gambar.']);
        }

        $options = collect($data['options'] ?? [])->map(function (array $option) {
            return [
                'option_label' => strtoupper((string) ($option['option_label'] ?? '')),
                'option_text' => filled($option['option_text'] ?? null) ? $option['option_text'] : null,
                'option_image' => filled($option['option_image'] ?? null) ? $option['option_image'] : null,
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'order_number' => (int) ($option['order_number'] ?? 0),
            ];
        })->values();

        $labels = $options->pluck('option_label')->sort()->values()->all();
        if ($labels !== ['A', 'B', 'C', 'D']) {
            throw ValidationException::withMessages(['options' => 'Opsi wajib tepat A, B, C, dan D.']);
        }

        foreach ($options as $option) {
            if (blank($option['option_text']) && blank($option['option_image'])) {
                throw ValidationException::withMessages(['options' => "Opsi {$option['option_label']} wajib memiliki teks atau gambar."]);
            }
        }

        if ($options->where('is_correct', true)->count() !== 1) {
            throw ValidationException::withMessages(['options' => 'Tepat satu opsi harus menjadi kunci jawaban.']);
        }

        $data['question_package_id'] = $package->id;
        $data['type'] = $data['type'] ?? 'multiple_choice';
        $data['question_text'] = filled($data['question_text'] ?? null) ? $data['question_text'] : null;
        $data['question_image'] = filled($data['question_image'] ?? null) ? $data['question_image'] : null;
        $data['score'] = $data['score'] ?? 1;
        $data['is_active'] = $data['is_active'] ?? true;
        $data['order_number'] = $data['order_number'] ?? ((int) $section->questions()->max('order_number') + 1);
        $data['options'] = $options->all();

        return $data;
    }

    public function createQuestion(QuestionPackage $package, array $data): Question
    {
        $data = $this->normalizeQuestionPayload($data, $package);
        $options = $data['options'];
        unset($data['options']);

        return DB::transaction(function () use ($data, $options, $package) {
            $question = Question::create($data);
            foreach ($options as $i => $option) {
                $question->options()->create([...$option, 'order_number' => $option['order_number'] ?: $i + 1]);
            }
            $this->refreshCounts($package);

            return $question->load(['section', 'options']);
        });
    }

    public function updateQuestion(QuestionPackage $package, Question $question, array $data): Question
    {
        $data = $this->normalizeQuestionPayload($data, $package);
        $options = $data['options'];
        unset($data['options']);

        return DB::transaction(function () use ($question, $data, $options, $package) {
            $question->update($data);
            $question->options()->delete();
            foreach ($options as $i => $option) {
                $question->options()->create([...$option, 'order_number' => $option['order_number'] ?: $i + 1]);
            }
            $this->refreshCounts($package);

            return $question->fresh(['section', 'options']);
        });
    }

    public function importCsv(QuestionPackage $package, UploadedFile $file): array
    {
        [$rows, $errors] = $this->readCsv($file);
        $sections = $package->sections()->get()->keyBy('code');

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $sectionCode = strtolower(trim((string) ($row['section_code'] ?? '')));
            $correct = strtoupper(trim((string) ($row['correct_option'] ?? '')));

            if (! $sections->has($sectionCode)) {
                $errors[] = ['row' => $rowNumber, 'field' => 'section_code', 'message' => 'section_code tidak valid.'];
            }
            if (! in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                $errors[] = ['row' => $rowNumber, 'field' => 'correct_option', 'message' => 'correct_option wajib A/B/C/D.'];
            }
            if (blank($row['question_text'] ?? null) && blank($row['question_image'] ?? null)) {
                $errors[] = ['row' => $rowNumber, 'field' => 'question_text', 'message' => 'Soal wajib memiliki teks atau gambar.'];
            }

            foreach (['a', 'b', 'c', 'd'] as $label) {
                if (blank($row["option_{$label}_text"] ?? null) && blank($row["option_{$label}_image"] ?? null)) {
                    $errors[] = ['row' => $rowNumber, 'field' => "option_{$label}_text", 'message' => 'Setiap opsi wajib memiliki teks atau gambar.'];
                }
            }
        }

        if ($errors !== []) {
            return ['imported' => 0, 'failed' => count($errors), 'errors' => $errors];
        }

        DB::transaction(function () use ($rows, $sections, $package) {
            foreach ($rows as $row) {
                $correct = strtoupper(trim((string) $row['correct_option']));
                $section = $sections->get(strtolower(trim((string) $row['section_code'])));
                $this->createQuestion($package, [
                    'exam_section_id' => $section->id,
                    'order_number' => (int) ($row['order_number'] ?: $section->questions()->max('order_number') + 1),
                    'question_text' => $row['question_text'] ?: null,
                    'question_image' => $row['question_image'] ?: null,
                    'score' => filled($row['score'] ?? null) ? (float) $row['score'] : 1,
                    'difficulty' => $row['difficulty'] ?: null,
                    'explanation' => $row['explanation'] ?: null,
                    'is_active' => true,
                    'options' => collect(['A', 'B', 'C', 'D'])->map(fn (string $label, int $i) => [
                        'option_label' => $label,
                        'option_text' => $row['option_'.strtolower($label).'_text'] ?: null,
                        'option_image' => $row['option_'.strtolower($label).'_image'] ?: null,
                        'is_correct' => $label === $correct,
                        'order_number' => $i + 1,
                    ])->all(),
                ]);
            }
        });

        return ['imported' => count($rows), 'failed' => 0, 'errors' => []];
    }

    public function refreshCounts(QuestionPackage $package): void
    {
        $package->load('sections');
        foreach ($package->sections as $section) {
            $section->update(['question_count' => $section->questions()->where('is_active', true)->count()]);
        }

        $package->update(['total_questions' => $package->questions()->where('is_active', true)->count()]);
    }

    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'File tidak dapat dibaca.']);
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return [[], [['row' => 1, 'field' => 'header', 'message' => 'Header CSV kosong.']]];
        }

        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
        $missing = array_values(array_diff(self::IMPORT_COLUMNS, $header));
        if ($missing !== []) {
            fclose($handle);

            return [[], [['row' => 1, 'field' => 'header', 'message' => 'Kolom wajib belum ada: '.implode(', ', $missing)]]];
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn ($value) => filled($value))) === 0) {
                continue;
            }
            $assoc = [];
            foreach ($header as $index => $key) {
                $assoc[$key] = isset($line[$index]) && filled($line[$index]) ? trim((string) $line[$index]) : null;
            }
            $rows[] = $assoc;
        }
        fclose($handle);

        return [$rows, []];
    }
}
