<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionPackageRequest;
use App\Models\QuestionPackage;
use Illuminate\Http\Request;

class QuestionPackageController extends Controller
{
    public function index(Request $request)
    {
        $q = QuestionPackage::query()->withCount('sections');
        $q->when($request->filled('q'), fn ($x) => $x->where('name', 'like', '%'.$request->q.'%'));
        if ($request->has('is_active')) {
            $request->boolean('is_active') ? $q->where('status', 'published') : $q->where('status', '!=', 'published');
        }

        return $q->latest()->paginate($request->integer('per_page', 10));
    }

    public function store(StoreQuestionPackageRequest $request)
    {
        $package = QuestionPackage::create([...$request->validated(), 'created_by' => $request->user()->id]);

        return response()->json(['message' => 'Paket dibuat.', 'data' => $package], 201);
    }

    public function show(QuestionPackage $questionPackage)
    {
        return ['data' => $questionPackage->load(['sections.questions.options'])];
    }

    public function update(StoreQuestionPackageRequest $request, QuestionPackage $questionPackage)
    {
        $questionPackage->update($request->validated());

        return ['message' => 'Paket diperbarui.', 'data' => $questionPackage->fresh()];
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

        return ['message' => 'Status diperbarui.', 'data' => $questionPackage->fresh()];
    }
}
