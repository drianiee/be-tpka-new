<?php

namespace App\Services;

use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionPackage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class QuestionBankService
{
    public const DEFAULT_SECTIONS = [
        ['code' => 'verbal', 'name' => 'Verbal', 'order_number' => 1, 'duration_minutes' => 25, 'target_question_count' => 50],
        ['code' => 'kuantitatif', 'name' => 'Kuantitatif', 'order_number' => 2, 'duration_minutes' => 60, 'target_question_count' => 70],
        ['code' => 'logika', 'name' => 'Logika', 'order_number' => 3, 'duration_minutes' => 40, 'target_question_count' => 60],
        ['code' => 'spasial', 'name' => 'Spasial', 'order_number' => 4, 'duration_minutes' => 25, 'target_question_count' => 20],
    ];

    public const IGNORED_SHEET_NAMES = ['Petunjuk', 'Contoh'];

    /** Kolom wajib. section_code tidak termasuk: subtes ditentukan dari tempat import dijalankan. */
    public const IMPORT_COLUMNS = [
        'order_number',
        'question_text',
        'option_a_text',
        'option_b_text',
        'option_c_text',
        'option_d_text',
        'correct_option',
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
            'base_score' => (int) $package->base_score,
            'score_span' => (int) $package->correct_answer_score,
            'max_score' => (int) $package->base_score + (int) $package->correct_answer_score,
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

        $questionId = isset($data['id']) ? (int) $data['id'] : null;
        $existingQuestionCount = $section->questions()->count();
        $isNewQuestion = ! $questionId;
        $isMovingIntoSection = $questionId
            ? ! Question::query()
                ->whereKey($questionId)
                ->where('exam_section_id', $section->id)
                ->exists()
            : false;

        if (
            $section->target_question_count > 0
            && (($isNewQuestion && $existingQuestionCount >= $section->target_question_count)
                || ($isMovingIntoSection && $existingQuestionCount >= $section->target_question_count))
        ) {
            throw ValidationException::withMessages([
                'exam_section_id' => "Section {$section->name} sudah penuh. Maksimal {$section->target_question_count} soal.",
            ]);
        }

        $data['question_image'] = $this->normalizeImageReference($data['question_image'] ?? null, 'question_image');

        if (blank($data['question_text'] ?? null) && blank($data['question_image'] ?? null)) {
            throw ValidationException::withMessages(['question_text' => 'Soal wajib memiliki teks atau gambar.']);
        }

        $options = collect($data['options'] ?? [])->map(function (array $option) {
            return [
                'option_label' => strtoupper((string) ($option['option_label'] ?? '')),
                'option_text' => filled($option['option_text'] ?? null) ? $option['option_text'] : null,
                'option_image' => $this->normalizeImageReference($option['option_image'] ?? null, 'options'),
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
        $data['score'] = $data['score'] ?? 1;
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
        $data['id'] = $question->id;
        $data = $this->normalizeQuestionPayload($data, $package);
        $options = $data['options'];
        unset($data['options'], $data['id']);

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

    /**
     * Setiap sheet yang namanya cocok dengan subtes diproses ke subtesnya sendiri,
     * jadi satu file boleh memuat beberapa subtes sekaligus. Subtes yang diklik
     * hanya jadi tujuan cadangan untuk file bersheet tunggal tanpa nama yang dikenal.
     *
     * Atomik per sheet: sheet yang bersih tersimpan, sheet bermasalah ditolak sendiri.
     */
    public function importRows(ExamSection $section, UploadedFile $file): array
    {
        $targets = $this->resolveImportTargets($file, $section);

        if ($targets === []) {
            throw ValidationException::withMessages([
                'file' => 'Tidak ada baris soal yang terisi. Pastikan soal diisi di sheet "'.$section->name.'", bukan di sheet Contoh.',
            ]);
        }

        $imported = 0;
        $errors = [];
        $results = [];

        foreach ($targets as $target) {
            $outcome = $target['errors'] !== []
                ? ['imported' => 0, 'errors' => $target['errors']]
                : $this->importSheet($target['section'], $target['rows'], $target['sheet']);

            $imported += $outcome['imported'];
            $errors = [...$errors, ...$outcome['errors']];
            $results[] = [
                'sheet' => $target['sheet'],
                'section' => $target['section']->name,
                'imported' => $outcome['imported'],
                'failed' => count($outcome['errors']),
            ];
        }

        return ['imported' => $imported, 'failed' => count($errors), 'errors' => $errors, 'results' => $results];
    }

    /** @param  array<int, array<string, string|null>>  $rows */
    private function importSheet(ExamSection $section, array $rows, string $sheetName): array
    {
        $errors = [];
        $limit = (int) $section->target_question_count;
        $planned = (int) $section->questions()->count();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $correct = strtoupper(trim((string) ($row['correct_option'] ?? '')));

            $planned++;
            if ($limit > 0 && $planned > $limit) {
                $errors[] = [
                    'sheet' => $sheetName,
                    'row' => $rowNumber,
                    'field' => 'jumlah soal',
                    'message' => "Subtes {$section->name} melebihi batas {$limit} soal.",
                ];
            }

            if (! in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                $errors[] = ['sheet' => $sheetName, 'row' => $rowNumber, 'field' => 'correct_option', 'message' => 'correct_option wajib A/B/C/D.'];
            }
            if (blank($row['question_text'] ?? null)) {
                $errors[] = ['sheet' => $sheetName, 'row' => $rowNumber, 'field' => 'question_text', 'message' => 'Pertanyaan wajib diisi. Soal bergambar ditambahkan lewat tombol Tambah Soal.'];
            }

            foreach (['a', 'b', 'c', 'd'] as $label) {
                if (blank($row["option_{$label}_text"] ?? null)) {
                    $errors[] = ['sheet' => $sheetName, 'row' => $rowNumber, 'field' => "option_{$label}_text", 'message' => 'Semua pilihan jawaban wajib diisi.'];
                }
            }
        }

        if ($errors !== []) {
            return ['imported' => 0, 'errors' => $errors];
        }

        // order_number di file hanya menentukan urutan antar baris di dalam file itu.
        // Nomor akhirnya selalu melanjutkan soal yang sudah ada, supaya tidak bentrok.
        $rows = $this->sortRowsByOrderColumn($rows);
        $nextOrder = (int) $section->questions()->max('order_number');
        $package = $section->package;

        DB::transaction(function () use ($rows, $section, $package, $nextOrder) {
            foreach ($rows as $row) {
                $correct = strtoupper(trim((string) $row['correct_option']));
                $nextOrder++;
                $this->createQuestion($package, [
                    'exam_section_id' => $section->id,
                    'order_number' => $nextOrder,
                    'question_text' => $row['question_text'],
                    'question_image' => null,
                    'score' => 1,
                    'options' => collect(['A', 'B', 'C', 'D'])->map(fn (string $label, int $i) => [
                        'option_label' => $label,
                        'option_text' => $row['option_'.strtolower($label).'_text'],
                        'option_image' => null,
                        'is_correct' => $label === $correct,
                        'order_number' => $i + 1,
                    ])->all(),
                ]);
            }
        });

        return ['imported' => count($rows), 'errors' => []];
    }

    public function refreshCounts(QuestionPackage $package): void
    {
        $package->load('sections');
        foreach ($package->sections as $section) {
            $section->update(['question_count' => $section->questions()->count()]);
        }

        $package->update(['total_questions' => $package->questions()->count()]);
    }

    /**
     * Baris yang mengisi order_number diurutkan menurut nilainya, sedangkan baris
     * yang mengosongkannya tetap mengikuti urutan aslinya di file dan diletakkan
     * setelahnya. Urutan asli dipakai sebagai pemutus kalau nilainya sama.
     */
    private function sortRowsByOrderColumn(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $index => $row) {
            $declared = $row['order_number'] ?? null;
            $indexed[] = [
                'row' => $row,
                'index' => $index,
                'order' => filled($declared) && is_numeric($declared) ? (int) $declared : PHP_INT_MAX,
            ];
        }

        usort($indexed, fn (array $a, array $b) => [$a['order'], $a['index']] <=> [$b['order'], $b['index']]);

        return array_column($indexed, 'row');
    }

    /**
     * @return array<int, array{section: ExamSection, sheet: string, rows: array, errors: array}>
     */
    private function resolveImportTargets(UploadedFile $file, ExamSection $fallbackSection): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'csv');

        $tables = in_array($extension, ['xlsx', 'xls'], true)
            ? $this->readSpreadsheetTables($file, $fallbackSection)
            : [['section' => $fallbackSection, 'sheet' => $fallbackSection->name, 'table' => $this->readCsvTable($file)]];

        $targets = [];
        foreach ($tables as $table) {
            [$rows, $errors] = $this->parseTable($table['table'], $table['section'], $table['sheet']);

            // Sheet yang tidak berisi apa pun dilewati tanpa dianggap gagal, supaya
            // template subtes lain yang dibiarkan kosong tidak memicu error.
            if ($rows === [] && $errors === []) {
                continue;
            }

            $targets[] = [
                'section' => $table['section'],
                'sheet' => $table['sheet'],
                'rows' => $rows,
                'errors' => $errors,
            ];
        }

        return $targets;
    }

    /** @return array{0: array, 1: array} */
    private function parseTable(array $table, ExamSection $section, string $sheetName): array
    {
        if ($table === []) {
            return [[], []];
        }

        $header = array_map(fn ($value) => strtolower(trim((string) $value)), array_shift($table));
        $missing = array_values(array_diff(self::IMPORT_COLUMNS, $header));
        if ($missing !== []) {
            return [[], [[
                'sheet' => $sheetName,
                'row' => 1,
                'field' => 'header',
                'message' => 'Kolom wajib belum ada: '.implode(', ', $missing),
            ]]];
        }

        $rows = [];
        $errors = [];
        foreach ($table as $line) {
            if (count(array_filter($line, fn ($value) => filled($value))) === 0) {
                continue;
            }

            $assoc = [];
            foreach ($header as $index => $key) {
                $assoc[$key] = isset($line[$index]) && filled($line[$index]) ? trim((string) $line[$index]) : null;
            }

            // File lama masih membawa kolom section_code. Isinya harus cocok dengan subtes tujuan.
            $declared = strtolower((string) ($assoc['section_code'] ?? ''));
            if ($declared !== '' && $declared !== strtolower((string) $section->code)) {
                $errors[] = [
                    'sheet' => $sheetName,
                    'row' => count($rows) + 2,
                    'field' => 'section_code',
                    'message' => "Baris ini ditujukan untuk subtes \"{$declared}\", padahal masuk ke {$section->name}.",
                ];
            }

            $rows[] = $assoc;
        }

        return [$rows, $errors];
    }

    private function readCsvTable(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'File tidak dapat dibaca.']);
        }

        $table = [];
        while (($line = fgetcsv($handle)) !== false) {
            $table[] = $line;
        }
        fclose($handle);

        return $table;
    }

    /**
     * Nama sheet menentukan subtes tujuan. Sheet bernama Petunjuk dan Contoh diabaikan,
     * dan file bersheet tunggal yang namanya tidak dikenal jatuh ke subtes yang diklik.
     *
     * @return array<int, array{section: ExamSection, sheet: string, table: array}>
     */
    private function readSpreadsheetTables(UploadedFile $file, ExamSection $fallbackSection): array
    {
        $path = (string) $file->getRealPath();
        $size = is_file($path) ? (int) filesize($path) : 0;

        if ($size === 0) {
            throw ValidationException::withMessages([
                'file' => 'File yang diunggah kosong (0 byte). Kemungkinan gagal terunggah, coba pilih ulang filenya.',
            ]);
        }

        // File .xlsx sejatinya arsip zip dan selalu diawali "PK". Kalau tidak, biasanya
        // file itu sebenarnya CSV yang namanya diberi akhiran .xlsx.
        $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $signature = (string) file_get_contents($path, false, null, 0, 2);

        if ($extension !== 'xls' && $signature !== 'PK') {
            Log::warning('File import bukan arsip xlsx, dicoba dibaca sebagai CSV.', [
                'nama' => $file->getClientOriginalName(),
                'ukuran' => $size,
                'signature' => bin2hex($signature),
            ]);

            return [[
                'section' => $fallbackSection,
                'sheet' => $fallbackSection->name,
                'table' => $this->readCsvTable($file),
            ]];
        }

        try {
            // Reader dipilih dari ekstensi, bukan lewat createReaderForFile(), supaya file
            // xlsx yang rusak tidak diam-diam jatuh ke pembaca CSV dan memunculkan
            // error kolom yang menyesatkan.
            $reader = IOFactory::createReader($extension === 'xls' ? 'Xls' : 'Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            Log::error('Gagal membaca file import Excel.', [
                'nama' => $file->getClientOriginalName(),
                'ukuran' => $size,
                'mime' => $file->getMimeType(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'file' => 'File Excel tidak dapat dibaca ('.$e->getMessage().'). Coba buka filenya di Excel lalu simpan ulang lewat Save As dengan format .xlsx.',
            ]);
        }

        $sections = $fallbackSection->package->sections;
        $candidates = collect($spreadsheet->getAllSheets())
            ->reject(fn (Worksheet $sheet) => in_array(trim($sheet->getTitle()), self::IGNORED_SHEET_NAMES, true))
            ->values();

        $tables = [];
        foreach ($candidates as $sheet) {
            $title = trim($sheet->getTitle());
            $section = $sections->first(
                fn (ExamSection $candidate) => strcasecmp($title, (string) $candidate->name) === 0
                    || strcasecmp($title, (string) $candidate->code) === 0
            );

            if (! $section) {
                continue;
            }

            $tables[] = ['section' => $section, 'sheet' => $title, 'table' => $this->normalizeTable($sheet)];
        }

        if ($tables === [] && $candidates->count() === 1) {
            $tables[] = [
                'section' => $fallbackSection,
                'sheet' => trim($candidates->first()->getTitle()),
                'table' => $this->normalizeTable($candidates->first()),
            ];
        }

        $spreadsheet->disconnectWorksheets();

        if ($tables === []) {
            throw ValidationException::withMessages([
                'file' => 'Tidak ada sheet yang namanya cocok dengan subtes paket ini. Beri nama sheet sesuai subtesnya: '
                    .$sections->pluck('name')->implode(', ').'.',
            ]);
        }

        return $tables;
    }

    private function normalizeTable(Worksheet $sheet): array
    {
        return array_map(
            fn (array $line) => array_map(
                fn ($value) => is_float($value) && floor($value) === $value ? (string) (int) $value : $value,
                $line,
            ),
            $sheet->toArray(null, true, false, false),
        );
    }

    private function normalizeImageReference(mixed $value, string $field): ?string
    {
        if (blank($value)) {
            return null;
        }

        $value = (string) $value;
        $path = parse_url($value, PHP_URL_PATH) ?: $value;
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (! str_starts_with($path, 'question-images/')) {
            throw ValidationException::withMessages([$field => 'Gambar soal/opsi hanya boleh berasal dari upload gambar.']);
        }

        return $value;
    }
}
