<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleRequest;
use App\Models\ExamRegistration;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Services\PartnerInvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        return Schedule::with(['package', 'registrations'])->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%'.$request->q.'%'))->latest()->paginate($request->integer('per_page', 10))->through(fn ($s) => $this->legacy($s));
    }

    public function upcoming()
    {
        return Schedule::where('status', 'published')->whereNull('partner_id')->where('is_partner', false)->where('end_at', '>', now())->orderBy('start_at')->limit(10)->get()->map(fn ($s) => $this->legacy($s));
    }

    public function store(StoreScheduleRequest $request)
    {
        $schedule = Schedule::create([...$this->scheduleData($request->validated(), true), 'created_by' => $request->user()->id]);

        return response()->json(['message' => 'Jadwal dibuat.', 'data' => $this->legacy($schedule->load('package'))], 201);
    }

    public function show(Schedule $schedule)
    {
        return $this->legacy($schedule->load(['package', 'registrations.user', 'attempts.scores']));
    }

    public function update(StoreScheduleRequest $request, Schedule $schedule)
    {
        $schedule->update($this->scheduleData($request->validated()));

        return ['message' => 'Jadwal diperbarui.', 'data' => $this->legacy($schedule->fresh('package'))];
    }

    public function destroy(Schedule $schedule)
    {
        abort_if($schedule->attempts()->exists(), 422, 'Jadwal yang memiliki attempt tidak dapat dihapus.');
        $schedule->delete();

        return response()->noContent();
    }

    public function addParticipant(Request $request, Schedule $schedule)
    {
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        ExamRegistration::updateOrCreate(['user_id' => $data['user_id'], 'schedule_id' => $schedule->id], ['payment_id' => null, 'status' => 'approved', 'access_status' => 'unlocked', 'registration_source' => $schedule->partner_id || $schedule->is_partner ? 'partner' : 'admin', 'registered_at' => now()]);

        return ['message' => 'Peserta ditambahkan.', 'schedule' => $this->legacy($schedule->fresh(['package', 'registrations']))];
    }

    public function removeParticipant(Schedule $schedule, int $userId)
    {
        ExamRegistration::where('schedule_id', $schedule->id)->where('user_id', $userId)->delete();

        return ['message' => 'Peserta dihapus.'];
    }

    public function importPartnerParticipants(Request $request, Schedule $schedule, PartnerInvitationService $invitations)
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240']]);
        $summary = $invitations->import($schedule, $data['file'], $request->user());

        return ['message' => 'Import peserta mitra diproses.', 'summary' => $summary];
    }

    private function legacy(Schedule $s): array
    {
        $start = $s->start_at;

        return ['id' => $s->id, 'title' => $s->title, 'description' => $s->description, 'date' => $start->format('Y-m-d H:i:s'), 'start_time' => $start->format('H:i:s'), 'end_time' => $s->end_at->format('H:i:s'), 'start_at' => $start->toISOString(), 'end_at' => $s->end_at->toISOString(), 'price' => (string) $s->price, 'capacity' => $s->quota, 'quota' => $s->quota, 'status' => $s->status, 'is_partner' => (bool) ($s->is_partner || $s->partner_id), 'partner_id' => $s->partner_id, 'partner' => $s->partner ? ['id' => $s->partner->id, 'name' => $s->partner->name] : null, 'current_participants' => $s->registrations->count(), 'question_package_id' => $s->question_package_id, 'packages' => $s->package ? [['id' => $s->package->id, 'name' => $s->package->name, 'status' => $s->package->status, 'total_questions' => $s->package->total_questions, 'total_duration_minutes' => $s->package->total_duration_minutes]] : [], 'attempt_count' => $s->attempts->count(), 'submitted_count' => $s->attempts->whereIn('status', ['submitted', 'expired'])->count(), 'participants' => ['data' => $s->relationLoaded('registrations') ? $s->registrations->map(fn ($r) => ['id' => $r->user_id, 'name' => $r->user?->name, 'email' => $r->user?->email, 'status' => $r->status, 'scores' => $r->user ? $s->attempts->firstWhere('user_id', $r->user_id)?->score_breakdown : null])->values() : [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => $s->registrations->count()]];
    }

    private function scheduleData(array $data, bool $creating = false): array
    {
        $package = QuestionPackage::findOrFail($data['question_package_id']);
        if (empty($data['end_at'])) {
            $data['end_at'] = Carbon::parse($data['start_at'])->addMinutes((int) $package->total_duration_minutes);
        }
        if ($creating) {
            $data['title'] = $package->name.' - '.Carbon::parse($data['start_at'])->format('d/m/Y H:i');
            $data['status'] = 'published';
        }
        $data['is_partner'] = (bool) ($data['is_partner'] ?? false) || ! empty($data['partner_id']);

        return $data;
    }
}
