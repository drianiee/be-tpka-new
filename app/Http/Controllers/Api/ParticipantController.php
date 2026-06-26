<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamAttempt;
use App\Models\ExamRegistration;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;
use App\Services\CertificateDataService;
use App\Services\ExamAttemptService;
use App\Services\MidtransService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ParticipantController extends Controller
{
    public function __construct(
        private CertificateDataService $certificateData,
        private MidtransService $midtrans,
        private ExamAttemptService $examAttemptService,
    ) {}

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
        return Schedule::where('status', 'published')->where(fn ($q) => $q->whereNull('partner_id')->where('is_partner', false))->where(fn ($q) => $q->whereNull('registration_start_at')->orWhere('registration_start_at', '<=', now()))->where(fn ($q) => $q->whereNull('registration_end_at')->orWhere('registration_end_at', '>=', now()))->withCount('registrations')->orderBy('start_at')->get()->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'date' => $s->start_at->format('Y-m-d H:i:s'), 'start_time' => $s->start_at->format('H:i:s'), 'price' => (string) $s->price, 'capacity' => $s->quota, 'current_participants' => $s->registrations_count, 'registration_status' => 'available', 'status' => $s->status]);
    }

    public function register(Request $request, Schedule $schedule)
    {
        abort_if($request->user()->role !== 'peserta', 403);
        abort_unless($schedule->status === 'published', 422, 'Jadwal belum dibuka.');
        abort_if($schedule->partner_id || $schedule->is_partner, 403, 'Jadwal ini khusus peserta mitra.');
        if ($schedule->registration_start_at && now()->lt($schedule->registration_start_at)) {
            abort(422, 'Periode pendaftaran belum dimulai.');
        }
        if ($schedule->registration_end_at && now()->gt($schedule->registration_end_at)) {
            abort(422, 'Periode pendaftaran sudah berakhir.');
        }

        $user = $request->user();
        $free = (float) $schedule->price <= 0.0;
        $existing = ExamRegistration::where('user_id', $user->id)->where('schedule_id', $schedule->id)->with('payment')->first();
        if ($existing) {
            if ($existing->access_status === 'unlocked') {
                return $this->registrationResponse('Registration already approved.', $existing->load('payment'), null);
            }
            if ($existing->status === 'pending_payment') {
                $payment = $existing->payment ?: $this->createPendingPayment($existing, $schedule, $user);
                $this->ensureSnap($payment->load(['user', 'schedule']));

                return $this->registrationResponse('Registration already exists. Please continue payment.', $existing->fresh('payment'), $payment->fresh());
            }
            if (in_array($existing->status, ['cancelled', 'expired'], true)) {
                $existing->update([
                    'status' => 'pending_payment',
                    'access_status' => 'locked',
                    'registered_at' => now(),
                ]);
                $payment = $this->createPendingPayment($existing, $schedule, $user, true);
                $this->ensureSnap($payment->load(['user', 'schedule']));

                return $this->registrationResponse('Registration reopened. Please continue payment.', $existing->fresh('payment'), $payment->fresh());
            }
        }

        if ($free) {
            $registration = ExamRegistration::create([
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'status' => 'approved',
                'access_status' => 'unlocked',
                'registration_source' => 'self',
                'registered_at' => now(),
            ]);

            return $this->registrationResponse('Registration approved.', $registration, null);
        }

        $registration = DB::transaction(fn () => ExamRegistration::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'pending_payment',
            'access_status' => 'locked',
            'registration_source' => 'self',
            'registered_at' => now(),
        ]));
        $payment = $this->createPendingPayment($registration, $schedule, $user);
        $this->ensureSnap($payment->load(['user', 'schedule']));

        return $this->registrationResponse('Registration created. Please continue payment.', $registration->fresh('payment'), $payment->fresh());
    }

    public function mySchedules(Request $request)
    {
        $regs = ExamRegistration::where('user_id', $request->user()->id)->with(['schedule.package', 'schedule.partner', 'payment', 'payments'])->get();

        return ['status' => $request->get('status', 'all'), 'count' => $regs->count(), 'data' => $regs->map(fn ($r) => $this->myScheduleData($r, $request->user()->id))];
    }

    public function mySchedule(Request $request, Schedule $schedule)
    {
        $reg = ExamRegistration::where('user_id', $request->user()->id)->where('schedule_id', $schedule->id)->with(['schedule.package', 'schedule.partner', 'payment', 'payments'])->firstOrFail();

        return $this->myScheduleData($reg, $request->user()->id);
    }

    public function scores(Request $request)
    {
        $attempts = ExamAttempt::where('user_id', $request->user()->id)
            ->whereIn('status', ['submitted', 'expired'])
            ->with(['schedule', 'scores', 'package.sections'])
            ->latest()
            ->get()
            ->map(fn (ExamAttempt $attempt) => $this->examAttemptService->recalculateCompletedAttempt($attempt)->loadMissing(['schedule', 'scores', 'package.sections']));

        return ['status' => $request->get('status', 'all'), 'count' => $attempts->count(), 'data' => $attempts->map(fn ($a) => $this->scoreData($a))];
    }

    public function score(Request $request, ExamAttempt $attempt)
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);

        return $this->scoreData(
            $this->examAttemptService
                ->recalculateCompletedAttempt($attempt)
                ->loadMissing(['schedule', 'scores', 'package.sections'])
        );
    }

    private function myScheduleData(ExamRegistration $r, int $userId): array
    {
        $s = $r->schedule;
        $attempt = ExamAttempt::where('user_id', $userId)->where('schedule_id', $s->id)->latest()->first();
        $payment = $this->resolvedPayment($r);
        $isPartnerSchedule = (bool) ($s->is_partner || $s->partner_id);
        $participantStatus = $this->participantStatusPayload($r, $isPartnerSchedule, $payment);

        return ['id' => $s->id, 'title' => $s->title, 'date' => $s->start_at->format('Y-m-d H:i:s'), 'start_time' => $s->start_at->format('H:i:s'), 'end_time' => $s->end_at->format('H:i:s'), 'status' => $s->status, 'is_partner' => $isPartnerSchedule, 'price' => (string) $s->price, 'payment_status' => $this->effectivePaymentStatus($r, $payment, $isPartnerSchedule), 'participant_status_key' => $participantStatus['key'], 'participant_status_label' => $participantStatus['label'], 'paid_at' => $payment?->paid_at?->toISOString(), 'order_id' => $payment?->order_id, 'payment_type' => $payment?->payment_type, 'partner' => $s->partner ? ['id' => $s->partner->id, 'name' => $s->partner->name] : null, 'registration' => ['id' => $r->id, 'status' => $r->status, 'access_status' => $r->access_status, 'registration_source' => $r->registration_source], 'payment' => $payment ? ['id' => $payment->id, 'transaction_status' => $payment->transaction_status, 'snap_redirect_url' => $payment->snap_redirect_url, 'paid_at' => $payment->paid_at?->toISOString()] : null, 'can_start' => $r->access_status === 'unlocked' && $s->status === 'published' && now()->between($s->start_at, $s->end_at), 'has_attempt' => (bool) $attempt, 'attempt_id' => $attempt?->id, 'question_package' => ['id' => $s->package->id, 'name' => $s->package->name, 'total_duration_minutes' => $s->package->total_duration_minutes, 'total_questions' => $s->package->total_questions]];
    }

    private function resolvedPayment(ExamRegistration $registration): ?Payment
    {
        $payments = $registration->relationLoaded('payments')
            ? $registration->payments
            : $registration->payments()->latest('id')->get();

        if (! $payments instanceof Collection || $payments->isEmpty()) {
            return $registration->relationLoaded('payment') ? $registration->payment : $registration->payment()->first();
        }

        $paid = $payments->first(fn (Payment $payment) => $this->isPaidPayment($payment));
        if ($paid) {
            return $paid;
        }

        $pending = $payments->first(fn (Payment $payment) => strtolower((string) $payment->transaction_status) === 'pending');
        if ($pending) {
            return $pending;
        }

        return $payments->first();
    }

    private function effectivePaymentStatus(ExamRegistration $registration, ?Payment $payment, bool $isPartnerSchedule): string
    {
        if ($isPartnerSchedule) {
            return $registration->user?->email_verified_at ? 'confirmed_email' : 'unconfirmed_email';
        }

        if (
            $registration->access_status === 'unlocked'
            || $registration->status === 'approved'
            || $this->isPaidPayment($payment)
        ) {
            return 'paid';
        }

        $paymentStatus = strtolower((string) ($payment?->transaction_status ?? ''));
        if (in_array($paymentStatus, ['deny', 'cancel', 'cancelled', 'expire', 'expired', 'failure'], true)) {
            return $paymentStatus;
        }

        if ($registration->status === 'expired') {
            return 'expired';
        }

        if ($registration->status === 'cancelled') {
            return 'cancelled';
        }

        return 'pending_payment';
    }

    private function participantStatusPayload(ExamRegistration $registration, bool $isPartnerSchedule, ?Payment $payment): array
    {
        if ($isPartnerSchedule) {
            return $registration->user?->email_verified_at
                ? ['key' => 'confirmed_email', 'label' => 'Telah konfirmasi email']
                : ['key' => 'unconfirmed_email', 'label' => 'Belum konfirmasi email'];
        }

        return (
            $registration->access_status === 'unlocked'
            || $registration->status === 'approved'
            || $this->isPaidPayment($payment)
        )
            ? ['key' => 'payment_completed', 'label' => 'Telah melakukan pembayaran']
            : ['key' => 'payment_pending', 'label' => 'Belum melakukan pembayaran'];
    }

    private function isPaidPayment(?Payment $payment): bool
    {
        if (! $payment) {
            return false;
        }

        $status = strtolower((string) $payment->transaction_status);

        return in_array($status, ['capture', 'settlement', 'paid'], true) || $payment->paid_at !== null;
    }

    private function createPendingPayment(ExamRegistration $registration, Schedule $schedule, User $user, bool $forceNew = false): Payment
    {
        $payment = $forceNew
            ? null
            : Payment::where('exam_registration_id', $registration->id)->where('transaction_status', 'pending')->latest()->first();

        if (! $payment) {
            $payment = Payment::create([
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'exam_registration_id' => $registration->id,
                'order_id' => $this->newOrderId($schedule),
                'amount' => $schedule->price,
                'transaction_status' => 'pending',
            ]);
        }

        $registration->update(['payment_id' => $payment->id]);

        return $payment;
    }

    private function ensureSnap(Payment $payment): void
    {
        if ($payment->snap_token && $payment->snap_redirect_url) {
            return;
        }

        $snap = $this->midtrans->createSnapTransaction($payment);
        $payment->update([
            'snap_token' => $snap['token'],
            'snap_redirect_url' => $snap['redirect_url'],
        ]);
    }

    private function registrationResponse(string $message, ExamRegistration $registration, ?Payment $payment): array
    {
        $schedule = $registration->schedule()->with('package')->firstOrFail();

        return [
            'message' => $message,
            'mode' => $payment ? 'paid' : 'free',
            'registration' => ['id' => $registration->id, 'status' => $registration->status, 'access_status' => $registration->access_status],
            'payment' => $payment ? ['id' => $payment->id, 'order_id' => $payment->order_id, 'amount' => (float) $payment->amount, 'transaction_status' => $payment->transaction_status, 'snap_token' => $payment->snap_token, 'snap_redirect_url' => $payment->snap_redirect_url] : null,
            'midtrans' => $payment ? ['client_key' => config('midtrans.client_key'), 'snap_token' => $payment->snap_token, 'redirect_url' => $payment->snap_redirect_url] : null,
            'data' => ['schedule_id' => $schedule->id, 'registration_id' => $registration->id, 'payment_id' => $payment?->id, 'order_id' => $payment?->order_id, 'snap_token' => $payment?->snap_token, 'redirect_url' => $payment?->snap_redirect_url, 'amount' => (float) ($payment?->amount ?? $schedule->price), 'picked' => ['question_package_id' => $schedule->question_package_id, 'package_name' => $schedule->package->name]],
        ];
    }

    private function newOrderId(Schedule $schedule): string
    {
        do {
            $orderId = 'TPKA-'.$schedule->id.'-'.now()->format('YmdHis').'-'.strtoupper(str()->random(6));
        } while (Payment::where('order_id', $orderId)->exists());

        return $orderId;
    }

    private function scoreData(ExamAttempt $a): array
    {
        $scoresBySectionId = $a->scores->keyBy('exam_section_id');
        $sectionScores = [
            'verbal' => 0.0,
            'kuantitatif' => 0.0,
            'logika' => 0.0,
            'spasial' => 0.0,
        ];

        foreach (($a->package?->sections ?? collect())->sortBy('order_number') as $section) {
            $code = strtolower((string) ($section->code ?? ''));
            if (! array_key_exists($code, $sectionScores)) {
                continue;
            }

            $sectionScores[$code] = (float) ($scoresBySectionId->get($section->id)?->final_score ?? 0);
        }

        $certificate = $a->scores->isNotEmpty() ? $this->certificateData->payload($a) : null;

        return ['id' => $a->id, 'date' => $a->schedule->start_at->format('Y-m-d H:i:s'), 'start_time' => $a->schedule->start_at->format('H:i:s'), 'end_time' => $a->schedule->end_at->format('H:i:s'), 'status' => $a->status, 'partner' => null, 'score_total' => (float) $a->score_total, 'score_breakdown' => $a->score_breakdown, 'scores' => [...$sectionScores, 'total' => (float) $a->score_total], 'certificate' => $certificate ? ['certificate_no' => $certificate['certificate_no'], 'verify_url' => $certificate['verify_url'], 'download_url' => $certificate['download_url']] : null];
    }
}
