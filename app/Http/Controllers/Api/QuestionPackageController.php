<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionPackageRequest;
use App\Models\QuestionPackage;
use App\Services\QuestionBankService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionPackageController extends Controller
{
    public function __construct(private QuestionBankService $questionBank) {}

    public function index(Request $request)
    {
        $q = QuestionPackage::query()->with(['sections' => fn ($query) => $query->orderBy('order_number')])->withCount('sections');
        $q->when($request->filled('q'), fn ($x) => $x->where('name', 'like', '%'.$request->q.'%'));
        if ($request->has('is_active')) {
            $request->boolean('is_active') ? $q->where('status', 'published') : $q->where('status', '!=', 'published');
        }

        return $q->latest()->paginate($request->integer('per_page', 10))->through(fn (QuestionPackage $package) => $this->questionBank->packagePayload($package));
    }

    public function store(StoreQuestionPackageRequest $request)
    {
        $package = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $sections = $data['sections'] ?? [];
            $data['correct_answer_score'] = $data['score_span'] ?? 600;
            unset($data['score_span']);
            unset($data['sections']);
            $data['target_total_questions'] = $data['target_total_questions'] ?? ($sections === [] ? collect(QuestionBankService::DEFAULT_SECTIONS)->sum('target_question_count') : collect($sections)->sum('target_question_count'));
            $data['base_score'] = $data['base_score'] ?? 200;
            $data['status'] = 'draft';
            $package = QuestionPackage::create([...$data, 'created_by' => $request->user()->id]);
            $this->questionBank->createDefaultSections($package, $sections);

            return $package;
        });

        return response()->json(['message' => 'Paket dibuat.', 'data' => $this->questionBank->packagePayload($package)], 201);
    }

    public function show(QuestionPackage $questionPackage)
    {
        return ['data' => $this->questionBank->packagePayload($questionPackage)];
    }

    public function update(StoreQuestionPackageRequest $request, QuestionPackage $questionPackage)
    {
        $data = $request->validated();
        $sections = $data['sections'] ?? [];
        $data['correct_answer_score'] = $data['score_span'] ?? $questionPackage->correct_answer_score;
        unset($data['score_span']);
        unset($data['sections']);
        $data['base_score'] = $data['base_score'] ?? $questionPackage->base_score;
        if ($sections !== []) {
            $data['target_total_questions'] = $data['target_total_questions'] ?? collect($sections)->sum('target_question_count');
        }
        $questionPackage->update($data);
        foreach ($sections as $section) {
            $questionPackage->sections()->where('code', $section['code'])->update([
                'duration_minutes' => $section['duration_minutes'],
                'target_question_count' => $section['target_question_count'],
            ]);
        }

        return ['message' => 'Paket diperbarui.', 'data' => $this->questionBank->packagePayload($questionPackage->fresh())];
    }

    public function destroy(QuestionPackage $questionPackage)
    {
        abort_if($questionPackage->status === 'published', 422, 'Arsipkan paket sebelum menghapus.');
        $questionPackage->delete();

        return ['message' => 'Paket dihapus.'];
    }

    public function status(Request $request, QuestionPackage $questionPackage)
    {
        $data = $request->validate(['status' => ['nullable', 'in:draft,published,archived'], 'is_active' => ['nullable', 'boolean']]);
        $status = $data['status'] ?? (($data['is_active'] ?? false) ? 'published' : 'archived');
        $questionPackage->update(['status' => $status]);

        return ['message' => 'Status diperbarui.', 'data' => $this->questionBank->packagePayload($questionPackage->fresh())];
    }
}
