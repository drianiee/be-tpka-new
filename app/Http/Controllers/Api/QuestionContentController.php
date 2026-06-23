<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionPackage;
use Illuminate\Http\Request;

class QuestionContentController extends Controller
{
    public function sections(QuestionPackage $questionPackage)
    {
        return $questionPackage->sections()->withCount('questions')->get();
    }

    public function storeSection(Request $request, QuestionPackage $questionPackage)
    {
        $data = $request->validate(['name' => ['required', 'string'], 'description' => ['nullable', 'string'], 'order_number' => ['required', 'integer', 'min:1'], 'duration_minutes' => ['required', 'integer', 'min:1']]);

        return response()->json($questionPackage->sections()->create($data), 201);
    }

    public function updateSection(Request $request, ExamSection $section)
    {
        $section->update($request->validate(['name' => ['sometimes', 'string'], 'description' => ['nullable', 'string'], 'order_number' => ['sometimes', 'integer', 'min:1'], 'duration_minutes' => ['sometimes', 'integer', 'min:1']]));

        return $section;
    }

    public function deleteSection(ExamSection $section)
    {
        $section->delete();

        return response()->noContent();
    }

    public function questions(Request $request)
    {
        return Question::with(['section', 'options'])->when($request->filled('package_id'), fn ($q) => $q->where('question_package_id', $request->package_id))->paginate($request->integer('per_page', 20));
    }

    public function storeQuestion(Request $request)
    {
        $data = $this->validatedQuestion($request);
        $options = $data['options'];
        unset($data['options']);
        $question = Question::create($data);
        foreach ($options as $i => $option) {
            $question->options()->create([...$option, 'order_number' => $option['order_number'] ?? $i + 1]);
        } $this->refreshCounts($question->question_package_id, $question->exam_section_id);

        return response()->json($question->load('options'), 201);
    }

    public function showQuestion(Question $question)
    {
        return $question->load(['section', 'options']);
    }

    public function updateQuestion(Request $request, Question $question)
    {
        $data = $this->validatedQuestion($request);
        $options = $data['options'];
        unset($data['options']);
        $question->update($data);
        $question->options()->delete();
        foreach ($options as $i => $option) {
            $question->options()->create([...$option, 'order_number' => $option['order_number'] ?? $i + 1]);
        } $this->refreshCounts($question->question_package_id, $question->exam_section_id);

        return $question->load('options');
    }

    public function deleteQuestion(Question $question)
    {
        $package = $question->question_package_id;
        $section = $question->exam_section_id;
        $question->delete();
        $this->refreshCounts($package, $section);

        return response()->noContent();
    }

    private function validatedQuestion(Request $request): array
    {
        return $request->validate(['question_package_id' => ['required', 'exists:question_packages,id'], 'exam_section_id' => ['required', 'exists:exam_sections,id'], 'type' => ['sometimes', 'in:single_choice'], 'question_text' => ['required', 'string'], 'question_image' => ['nullable', 'string'], 'explanation' => ['nullable', 'string'], 'difficulty' => ['nullable', 'string'], 'score' => ['nullable', 'numeric', 'min:0'], 'is_active' => ['nullable', 'boolean'], 'order_number' => ['required', 'integer', 'min:1'], 'options' => ['required', 'array', 'min:2'], 'options.*.option_label' => ['required', 'string', 'max:10'], 'options.*.option_text' => ['required', 'string'], 'options.*.option_image' => ['nullable', 'string'], 'options.*.is_correct' => ['required', 'boolean'], 'options.*.order_number' => ['nullable', 'integer']]);
    }

    private function refreshCounts(int $packageId, int $sectionId): void
    {
        ExamSection::whereKey($sectionId)->update(['question_count' => Question::where('exam_section_id', $sectionId)->where('is_active', true)->count()]);
        QuestionPackage::whereKey($packageId)->update(['total_questions' => Question::where('question_package_id', $packageId)->where('is_active', true)->count()]);
    }
}
