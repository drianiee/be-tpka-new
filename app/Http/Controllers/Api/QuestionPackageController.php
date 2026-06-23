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
            $package = QuestionPackage::create([...$request->validated(), 'created_by' => $request->user()->id]);
            $this->questionBank->createDefaultSections($package);

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
        $questionPackage->update($request->validated());

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
