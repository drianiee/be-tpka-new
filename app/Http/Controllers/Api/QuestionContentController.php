<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionPackage;
use App\Services\QuestionBankService;
use Illuminate\Http\Request;
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
            ->when($request->filled('difficulty'), fn ($q) => $q->where('difficulty', $request->difficulty))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
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

    public function importTemplate()
    {
        $rows = [
            QuestionBankService::IMPORT_COLUMNS,
            ['verbal', '1', 'Contoh soal verbal', '', 'Pilihan A', '', 'Pilihan B', '', 'Pilihan C', '', 'Pilihan D', '', 'A', '1', 'mudah', 'Pembahasan opsional'],
        ];
        $csv = collect($rows)->map(fn ($row) => collect($row)->map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"')->implode(','))->implode("\n")."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="template-import-soal-tpa.csv"',
        ]);
    }

    public function importQuestions(Request $request, QuestionPackage $questionPackage)
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $result = $this->questionBank->importCsv($questionPackage, $data['file']);
        $status = $result['errors'] === [] ? 201 : 422;

        return response()->json(['message' => $status === 201 ? 'Import soal berhasil.' : 'Import gagal, periksa error per baris.', ...$result], $status);
    }

    public function uploadImage(Request $request)
    {
        $data = $request->validate(['image' => ['required', 'image', 'max:4096']]);
        $path = $data['image']->store('question-images', 'public');

        return response()->json([
            'url' => $request->getSchemeAndHttpHost().'/storage/'.$path,
            'path' => $path,
        ], 201);
    }

    private function validatedQuestion(Request $request, bool $requirePackageId = false): array
    {
        return $request->validate([
            'question_package_id' => [$requirePackageId ? 'required' : 'sometimes', 'integer', 'exists:question_packages,id'],
            'exam_section_id' => ['required', 'integer', 'exists:exam_sections,id'],
            'type' => ['sometimes', 'in:multiple_choice,single_choice'],
            'question_text' => ['nullable', 'string'],
            'question_image' => ['nullable', 'string'],
            'explanation' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'string', 'max:100'],
            'score' => ['nullable', 'numeric', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'order_number' => ['nullable', 'integer', 'min:1'],
            'options' => ['required', 'array', 'size:4'],
            'options.*.option_label' => ['required', 'string', 'in:A,B,C,D'],
            'options.*.option_text' => ['nullable', 'string'],
            'options.*.option_image' => ['nullable', 'string'],
            'options.*.is_correct' => ['required', 'boolean'],
            'options.*.order_number' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
