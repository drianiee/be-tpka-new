<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamAttempt;
use App\Models\ExamRegistration;
use App\Models\Schedule;
use App\Models\User;
use App\Services\CertificateDataService;
use Illuminate\Http\Request;

class ParticipantController extends Controller
{
    public function __construct(private CertificateDataService $certificateData) {}

    public function index(Request $request)
    {
        return User::where('role', 'peserta')->when($request->filled('q'), fn ($q) => $q->where(fn ($x) => $x->where('name', 'like', '%'.$request->q.'%')->orWhere('email', 'like', '%'.$request->q.'%')))->paginate($request->integer('per_page', 10));
    }

    public function show(User $participant)
    {
        abort_unless($participant->role === 'peserta', 404);
        $regs = ExamRegistration::where('user_id', $participant->id)->with('schedule')->get();

        return ['participant' => $participant, 'schedules' => $regs->map(fn ($r) => ['schedule_id' => $r->schedule_id, 'date' => $r->schedule->start_at->format('Y-m-d H:i:s'), 'start_time' => $r->schedule->start_at->format('H:i:s'), 'end_time' => $r->schedule->end_at->format('H:i:s'), 'status_jadwal' => $r->schedule->status, 'participant_status' => $r->status, 'attempt_id' => ExamAttempt::where('user_id', $participant->id)->where('schedule_id', $r->schedule_id)->value('id'), 'accessed_at' => null, 'finished_at' => null, 'assigned_at' => $r->registered_at?->toISOString(), 'capacity' => $r->schedule->quota, 'current_participants' => $r->schedule->registrations()->count()]), 'total_schedules' => $regs->count()];
    }

    public function schedules()
    {
        return Schedule::where('status', 'published')->where('registration_end_at', '>=', now())->withCount('registrations')->orderBy('start_at')->get()->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'date' => $s->start_at->format('Y-m-d H:i:s'), 'start_time' => $s->start_at->format('H:i:s'), 'price' => (string) $s->price, 'capacity' => $s->quota, 'current_participants' => $s->registrations_count, 'registration_status' => 'available', 'status' => $s->status]);
    }

    public function register(Request $request, Schedule $schedule)
    {
        $free = (float) $schedule->price === 0.0;
        $registration = ExamRegistration::updateOrCreate(['user_id' => $request->user()->id, 'schedule_id' => $schedule->id], ['status' => $free ? 'approved' : 'pending_payment', 'access_status' => $free ? 'unlocked' : 'locked', 'registered_at' => now()]);

        return ['message' => $free ? 'Pendaftaran berhasil.' : 'Pendaftaran dibuat, lanjutkan pembayaran.', 'mode' => $free ? 'free' : 'paid', 'data' => ['schedule_id' => $schedule->id, 'registration_id' => $registration->id, 'payment_id' => null, 'order_id' => null, 'snap_token' => null, 'redirect_url' => null, 'amount' => (float) $schedule->price, 'picked' => ['question_package_id' => $schedule->question_package_id, 'package_name' => $schedule->package->name]]];
    }

    public function mySchedules(Request $request)
    {
        $regs = ExamRegistration::where('user_id', $request->user()->id)->with('schedule.package')->get();

        return ['status' => $request->get('status', 'all'), 'count' => $regs->count(), 'data' => $regs->map(fn ($r) => $this->myScheduleData($r, $request->user()->id))];
    }

    public function mySchedule(Request $request, Schedule $schedule)
    {
        $reg = ExamRegistration::where('user_id', $request->user()->id)->where('schedule_id', $schedule->id)->with('schedule.package')->firstOrFail();

        return $this->myScheduleData($reg, $request->user()->id);
    }

    public function scores(Request $request)
    {
        $attempts = ExamAttempt::where('user_id', $request->user()->id)->whereIn('status', ['submitted', 'expired'])->with(['schedule', 'scores'])->latest()->get();

        return ['status' => $request->get('status', 'all'), 'count' => $attempts->count(), 'data' => $attempts->map(fn ($a) => $this->scoreData($a))];
    }

    public function score(Request $request, ExamAttempt $attempt)
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);

        return $this->scoreData($attempt->load(['schedule', 'scores']));
    }

    private function myScheduleData(ExamRegistration $r, int $userId): array
    {
        $s = $r->schedule;
        $attempt = ExamAttempt::where('user_id', $userId)->where('schedule_id', $s->id)->latest()->first();

        return ['id' => $s->id, 'title' => $s->title, 'date' => $s->start_at->format('Y-m-d H:i:s'), 'start_time' => $s->start_at->format('H:i:s'), 'end_time' => $s->end_at->format('H:i:s'), 'status' => $s->status, 'is_partner' => false, 'price' => (string) $s->price, 'payment_status' => $r->status, 'paid_at' => null, 'order_id' => null, 'payment_type' => null, 'partner' => null, 'can_start' => $r->access_status === 'unlocked' && $s->status === 'published' && now()->between($s->start_at, $s->end_at), 'has_attempt' => (bool) $attempt, 'attempt_id' => $attempt?->id, 'question_package' => ['id' => $s->package->id, 'name' => $s->package->name, 'total_duration_minutes' => $s->package->total_duration_minutes, 'total_questions' => $s->package->total_questions]];
    }

    private function scoreData(ExamAttempt $a): array
    {
        $by = $a->scores->keyBy('exam_section_id');
        $certificate = $a->scores->isNotEmpty() ? $this->certificateData->payload($a) : null;

        return ['id' => $a->id, 'date' => $a->schedule->start_at->format('Y-m-d H:i:s'), 'start_time' => $a->schedule->start_at->format('H:i:s'), 'end_time' => $a->schedule->end_at->format('H:i:s'), 'status' => $a->status, 'partner' => null, 'score_total' => (float) $a->score_total, 'score_breakdown' => $a->score_breakdown, 'scores' => ['verbal' => $by->values()->get(0)?->final_score, 'kuantitatif' => $by->values()->get(1)?->final_score, 'logika' => $by->values()->get(2)?->final_score, 'spasial' => $by->values()->get(3)?->final_score, 'total' => (float) $a->score_total], 'certificate' => $certificate ? ['certificate_no' => $certificate['certificate_no'], 'verify_url' => $certificate['verify_url'], 'download_url' => $certificate['download_url']] : null];
    }
}
