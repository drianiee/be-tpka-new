<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionPackage;
use App\Services\QuestionBankService;
use App\Services\QuestionImportTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class QuestionContentController extends Controller
{
    public function __construct(private QuestionBankService $questionBank) {}

    public function sections(QuestionPackage $questionPackage)
    {
        return $questionPackage->sections()->withCount('questions')->orderBy('order_number')->get();
    }

    public function storeSection(Request $request, QuestionPackage $questionPackage)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('exam_sections')->where('question_package_id', $questionPackage->id)],
            'description' => ['nullable', 'string'],
            'order_number' => ['required', 'integer', 'min:1'],
            'duration_minutes' => ['required', 'integer', 'min:0'],
        ]);
        if (filled($data['code'] ?? null)) {
            $data['code'] = strtolower($data['code']);
        }

        return response()->json($questionPackage->sections()->create($data), 201);
    }

    public function updateSection(Request $request, ExamSection $section)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('exam_sections')->where('question_package_id', $section->question_package_id)->ignore($section->id)],
            'description' => ['nullable', 'string'],
            'order_number' => ['sometimes', 'integer', 'min:1'],
            'duration_minutes' => ['sometimes', 'integer', 'min:0'],
        ]);
        if (array_key_exists('code', $data) && filled($data['code'])) {
            $data['code'] = strtolower($data['code']);
        }
        $section->update($data);

        return $section->fresh()->loadCount('questions');
    }

    public function deleteSection(ExamSection $section)
    {
        abort_if($section->questions()->exists(), 422, 'Section yang sudah memiliki soal tidak dapat dihapus.');
        $section->delete();

        return response()->noContent();
    }

    public function questions(Request $request, ?QuestionPackage $questionPackage = null)
    {
        $query = Question::query()->with(['section', 'options']);
        if ($questionPackage) {
            $query->where('question_package_id', $questionPackage->id);
        } elseif ($request->filled('package_id')) {
            $query->where('question_package_id', $request->integer('package_id'));
        }

        $query
            ->when($request->filled('section_id'), fn ($q) => $q->where('exam_section_id', $request->integer('section_id')))
            ->when($request->filled('section_code'), fn ($q) => $q->whereHas('section', fn ($s) => $s->where('code', strtolower((string) $request->section_code))))
            ->when($request->filled('search'), fn ($q) => $q->where('question_text', 'like', '%'.$request->search.'%'))
            ->orderBy('exam_section_id')
            ->orderBy('order_number');

        return $query->paginate($request->integer('per_page', 20));
    }

    public function storePackageQuestion(Request $request, QuestionPackage $questionPackage)
    {
        $question = $this->questionBank->createQuestion($questionPackage, $this->validatedQuestion($request));

        return response()->json(['message' => 'Soal dibuat.', 'data' => $question], 201);
    }

    public function storeQuestion(Request $request)
    {
        $data = $this->validatedQuestion($request, requirePackageId: true);
        $package = QuestionPackage::findOrFail($data['question_package_id']);
        $question = $this->questionBank->createQuestion($package, $data);

        return response()->json(['message' => 'Soal dibuat.', 'data' => $question], 201);
    }

    public function showQuestion(Question $question)
    {
        return ['data' => $question->load(['section', 'options'])];
    }

    public function updatePackageQuestion(Request $request, QuestionPackage $questionPackage, Question $question)
    {
        abort_unless($question->question_package_id === $questionPackage->id, 404);

        return ['message' => 'Soal diperbarui.', 'data' => $this->questionBank->updateQuestion($questionPackage, $question, $this->validatedQuestion($request))];
    }

    public function updateQuestion(Request $request, Question $question)
    {
        $package = $question->package ?? QuestionPackage::findOrFail($question->question_package_id);

        return ['message' => 'Soal diperbarui.', 'data' => $this->questionBank->updateQuestion($package, $question, $this->validatedQuestion($request))];
    }

    public function deleteQuestion(Question $question)
    {
        $package = QuestionPackage::findOrFail($question->question_package_id);
        $question->delete();
        $this->questionBank->refreshCounts($package);

        return response()->noContent();
    }

    public function importTemplate(ExamSection $section)
    {
        $contents = (new QuestionImportTemplate)->build($section);
        $filename = 'template-soal-'.Str::slug($section->name ?: 'subtes').'-'.Str::slug($section->package?->name ?: 'paket').'.xlsx';

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }

    public function exportQuestions(QuestionPackage $questionPackage)
    {
        $contents = (new QuestionImportTemplate)->buildPackageExport($questionPackage);
        $filename = 'soal-'.Str::slug($questionPackage->name ?: 'paket').'.xlsx';

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }

    public function importQuestions(Request $request, ExamSection $section)
    {
        $data = $request->validate(['file' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:10240']]);
        $result = $this->questionBank->importRows($section, $data['file']);

        // Import atomik per sheet, jadi sebagian bisa berhasil sementara sisanya ditolak.
        $status = match (true) {
            $result['errors'] === [] => 201,
            $result['imported'] > 0 => 200,
            default => 422,
        };

        $ringkasan = collect($result['results'])
            ->filter(fn (array $row) => $row['imported'] > 0)
            ->map(fn (array $row) => $row['imported'].' soal '.$row['section'])
            ->implode(', ');

        return response()->json([
            'message' => match ($status) {
                201 => 'Import berhasil: '.$ringkasan.'.',
                200 => 'Sebagian berhasil: '.$ringkasan.'. Sheet lain ditolak, periksa daftar error.',
                default => 'Import gagal, tidak ada soal yang disimpan.',
            },
            ...$result,
        ], $status);
    }

    public function uploadImage(Request $request)
    {
        $data = $request->validate(['image' => ['required', 'image', 'max:4096']]);
        $path = $data['image']->store('question-images', 'public');

        return response()->json([
            'url' => $request->getSchemeAndHttpHost().'/storage/'.$path,
        ], 201);
    }

    private function validatedQuestion(Request $request, bool $requirePackageId = false): array
    {
        return $request->validate([
            'question_package_id' => [$requirePackageId ? 'required' : 'sometimes', 'integer', 'exists:question_packages,id'],
            'exam_section_id' => ['required', 'integer', 'exists:exam_sections,id'],
            'type' => ['sometimes', 'in:multiple_choice,single_choice'],
            'question_text' => ['nullable', 'string'],
            'question_image' => ['nullable', 'string', $this->uploadedQuestionImageRule()],
            'score' => ['nullable', 'numeric', 'min:1'],
            'order_number' => ['nullable', 'integer', 'min:1'],
            'options' => ['required', 'array', 'size:4'],
            'options.*.option_label' => ['required', 'string', 'in:A,B,C,D'],
            'options.*.option_text' => ['nullable', 'string'],
            'options.*.option_image' => ['nullable', 'string', $this->uploadedQuestionImageRule()],
            'options.*.is_correct' => ['required', 'boolean'],
            'options.*.order_number' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function uploadedQuestionImageRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (blank($value)) {
                return;
            }

            $path = parse_url((string) $value, PHP_URL_PATH) ?: (string) $value;
            $path = ltrim($path, '/');
            if (str_starts_with($path, 'storage/')) {
                $path = substr($path, strlen('storage/'));
            }

            if (! str_starts_with($path, 'question-images/')) {
                $fail('Gambar soal/opsi hanya boleh berasal dari upload gambar.');
            }
        };
    }
}
