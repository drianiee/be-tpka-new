<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamAttempt;
use App\Models\ExamRegistration;
use App\Models\Payment;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Models\User;
use App\Services\CertificateAssignmentService;
use App\Services\CertificateDataService;
use App\Services\ExamAttemptService;
use App\Services\MidtransService;
use App\Services\QuestionPackageAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ParticipantController extends Controller
{
    public function __construct(
        private CertificateDataService $certificateData,
        private CertificateAssignmentService $certificates,
        private MidtransService $midtrans,
        private ExamAttemptService $examAttemptService,
        private QuestionPackageAssignmentService $packageAssignments,
    ) {}

    public function index(Request $request)
    {
        return User::query()
            ->where('role', 'peserta')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->string('q'));

                $q->where(function ($inner) use ($term) {
                    $inner
                        ->where('name', 'like', '%'.$term.'%')
                        ->orWhere('email', 'like', '%'.$term.'%');
                });
            })
            ->when($request->has('verified') && $request->get('verified') !== null && $request->get('verified') !== '', function ($q) use ($request) {
                $verified = filter_var($request->get('verified'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

                if ($verified === true) {
                    $q->whereNotNull('email_verified_at');
                } elseif ($verified === false) {
                    $q->whereNull('email_verified_at');
                }
            })
            ->paginate($request->integer('per_page', 10));
    }

    public function show(Request $request, User $participant)
    {
        abort_unless($participant->role === 'peserta', 404);
        $regs = ExamRegistration::query()
            ->where('user_id', $participant->id)
            ->with(['schedule.partner', 'certificate'])
            ->get();

        $participantPayload = [
            'id' => $participant->id,
            'name' => $participant->name,
            'email' => $participant->email,
            'email_verified_at' => $participant->email_verified_at?->toISOString(),
            'role' => $participant->role,
            'is_active' => (bool) $participant->is_active,
            'id_type' => $participant->id_type,
            'id_image_path' => $participant->id_image_path,
            'id_image_url' => $participant->id_image_path
                ? $request->getSchemeAndHttpHost().Storage::url($participant->id_image_path)
                : null,
            'birth_date' => $participant->birth_date?->toDateString(),
            'phone' => $participant->phone,
            'profile_completed_at' => $participant->profile_completed_at?->toISOString(),
            'created_at' => $participant->created_at?->toISOString(),
            'updated_at' => $participant->updated_at?->toISOString(),
        ];

        $scheduleItems = $regs->map(function (ExamRegistration $registration) use ($participant) {
            $schedule = $registration->schedule;
            $attempt = ExamAttempt::query()
                ->where('user_id', $participant->id)
                ->where('schedule_id', $registration->schedule_id)
                ->latest()
                ->first();
            $payment = $this->resolvedPayment($registration);
            $isPartnerSchedule = (bool) ($schedule->is_partner || $schedule->partner_id);
            $statusMeta = $this->adminParticipantStatusMeta($registration, $attempt, $isPartnerSchedule, $payment);

            $certificate = $attempt && in_array($attempt->status, ['submitted', 'expired'], true)
                ? $this->certificateData->fromRegistration($registration, true)
                : null;

            return [
                'schedule_id' => $registration->schedule_id,
                'date' => $schedule->start_at->format('Y-m-d H:i:s'),
                'start_time' => $schedule->start_at->format('H:i:s'),
                'end_time' => $schedule->end_at->format('H:i:s'),
                'status_jadwal' => $this->scheduleWindowStatusLabel($schedule),
                'attempt_id' => $attempt?->id,
                'is_partner' => $isPartnerSchedule,
                'partner' => $schedule->partner ? [
                    'id' => $schedule->partner->id,
                    'name' => $schedule->partner->name,
                ] : null,
                'participant_status' => $statusMeta['label'],
                'participant_status_variant' => $statusMeta['variant'],
                'accessed_at' => $attempt?->started_at?->toISOString(),
                'finished_at' => $attempt?->submitted_at?->toISOString(),
                'assigned_at' => $registration->registered_at?->toISOString(),
                'capacity' => $schedule->quota,
                'current_participants' => $schedule->registrations()->count(),
                'certificate' => $certificate ? [
                    'certificate_no' => $certificate['certificate_no'] ?? null,
                    'verify_url' => $certificate['verify_url'] ?? null,
                    'download_url' => $certificate['download_url'] ?? null,
                    'public_pdf_url' => $certificate['public_pdf_url'] ?? null,
                ] : null,
            ];
        })->values();

        return [
            'participant' => $participantPayload,
            'schedules' => $scheduleItems,
            'total_schedules' => $regs->count(),
        ];
    }

    public function schedules(Request $request)
    {
        return Schedule::where('status', 'published')
            ->where(fn ($q) => $q->whereNull('partner_id')->where('is_partner', false))
            ->where(fn ($q) => $q->whereNull('registration_start_at')->orWhere('registration_start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('registration_end_at')->orWhere('registration_end_at', '>=', now()))
            ->whereDoesntHave('registrations', fn ($q) => $q->where('user_id', $request->user()->id))
            ->withCount('registrations')
            ->orderBy('start_at')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'date' => $s->start_at->format('Y-m-d H:i:s'),
                'start_time' => $s->start_at->format('H:i:s'),
                'price' => (string) $s->price,
                'capacity' => $s->quota,
                'current_participants' => $s->registrations_count,
                'registration_status' => 'available',
                'status' => $s->status,
            ]);
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
            if (! $existing->certificate_id && $schedule->certificate_id) {
                $existing->update(['certificate_id' => $schedule->certificate_id]);
            }
            if ($existing->access_status === 'unlocked') {
                if (! $existing->question_package_id) {
                    $existing->update([
                        'question_package_id' => $this->packageAssignments->pickRandomPackage($schedule)->id,
                    ]);
                }

                return $this->registrationResponse('Registration already approved.', $existing->load('payment'), null);
            }
            if ($existing->status === 'pending_payment') {
                if ($existing->question_package_id) {
                    $existing->update(['question_package_id' => null]);
                }
                $payment = $existing->payment ?: $this->createPendingPayment($existing, $schedule, $user);
                $this->ensureSnap($payment->load(['user', 'schedule']));

                return $this->registrationResponse('Registration already exists. Please continue payment.', $existing->fresh('payment'), $payment->fresh());
            }
            if (in_array($existing->status, ['cancelled', 'expired'], true)) {
                $existing->update([
                    'status' => 'pending_payment',
                    'access_status' => 'locked',
                    'question_package_id' => null,
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
                'question_package_id' => $this->packageAssignments->pickRandomPackage($schedule)->id,
                'certificate_id' => $schedule->certificate_id,
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
            'certificate_id' => $schedule->certificate_id,
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
        $status = strtolower(trim((string) $request->get('status', 'all')));

        $regs = ExamRegistration::where('user_id', $request->user()->id)
            ->with(['schedule.package', 'schedule.partner', 'package', 'payment', 'payments'])
            ->get()
            ->filter(function (ExamRegistration $registration) use ($status) {
                if ($status === '' || $status === 'all') {
                    return true;
                }

                return $this->participantScheduleStatusKey($registration) === $status;
            })
            ->values();

        return [
            'status' => $request->get('status', 'all'),
            'count' => $regs->count(),
            'data' => $regs->map(fn ($r) => $this->myScheduleData($r, $request->user()->id)),
        ];
    }

    public function mySchedule(Request $request, Schedule $schedule)
    {
        $reg = ExamRegistration::where('user_id', $request->user()->id)->where('schedule_id', $schedule->id)->with(['schedule.package', 'schedule.partner', 'package', 'payment', 'payments'])->firstOrFail();

        return $this->myScheduleData($reg, $request->user()->id);
    }

    public function scores(Request $request)
    {
        $attempts = ExamAttempt::where('user_id', $request->user()->id)
            ->whereIn('status', ['submitted', 'expired'])
            ->with(['schedule', 'scores', 'package.sections'])
            ->latest('id')
            ->get()
            ->unique('schedule_id')
            ->values()
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
        $payment = $this->expirePendingRegistrationAfterScheduleStart($r, $payment, $isPartnerSchedule);
        $r->refresh();
        $participantStatus = $this->participantStatusPayload($r, $isPartnerSchedule, $payment);
        $retakePending = $this->examAttemptService->hasPendingRetake($r);
        $assignedPackage = $r->relationLoaded('package') ? $r->package : $r->package()->with('sections')->first();
        $assignedPackage?->loadMissing('sections');
        $canSeeAssignedPackage = $r->access_status === 'unlocked'
            || $r->status === 'approved';
        $displayPackage = $canSeeAssignedPackage ? $assignedPackage : null;
        $activeAttempt = $attempt && $attempt->status === 'in_progress' ? $attempt : null;
        $completedAttempt = $attempt && in_array($attempt->status, ['submitted', 'expired'], true);
        $completedAttemptData = $completedAttempt
            ? $this->examAttemptService
                ->recalculateCompletedAttempt($attempt)
                ->loadMissing(['scores', 'package.sections'])
            : null;
        $certificate = $completedAttempt ? $this->certificateData->fromRegistration($r) : null;
        $canStart = $r->access_status === 'unlocked'
            && $s->status === 'published'
            && (! $completedAttempt || $retakePending)
            && ($retakePending || now()->between($s->start_at, $s->end_at));

        return ['id' => $s->id, 'title' => $s->title, 'date' => $s->start_at->format('Y-m-d H:i:s'), 'start_time' => $s->start_at->format('H:i:s'), 'end_time' => $s->end_at->format('H:i:s'), 'status' => $s->status, 'is_partner' => $isPartnerSchedule, 'price' => (string) $s->price, 'payment_status' => $this->effectivePaymentStatus($r, $payment, $isPartnerSchedule), 'participant_status_key' => $participantStatus['key'], 'participant_status_label' => $participantStatus['label'], 'paid_at' => $payment?->paid_at?->toISOString(), 'order_id' => $payment?->order_id, 'payment_type' => $payment?->payment_type, 'partner' => $s->partner ? ['id' => $s->partner->id, 'name' => $s->partner->name] : null, 'registration' => ['id' => $r->id, 'status' => $r->status, 'access_status' => $r->access_status, 'registration_source' => $r->registration_source, 'retake_granted_at' => $r->retake_granted_at?->toISOString(), 'retake_consumed_at' => $r->retake_consumed_at?->toISOString()], 'payment' => $payment ? ['id' => $payment->id, 'transaction_status' => $payment->transaction_status, 'snap_redirect_url' => $payment->snap_redirect_url, 'paid_at' => $payment->paid_at?->toISOString()] : null, 'can_start' => $canStart, 'has_attempt' => (bool) $activeAttempt, 'attempt_id' => $activeAttempt?->id, 'attempt_status' => $activeAttempt?->status ?? $attempt?->status, 'attempt_resume_count' => (int) ($activeAttempt?->metadata['resume_count'] ?? 0), 'question_package' => $this->participantPackagePayload($displayPackage), 'scores' => $completedAttemptData ? $this->sectionScoresPayload($completedAttemptData) : null, 'certificate' => $certificate ? ['certificate_no' => $certificate['certificate_no'], 'verify_url' => $certificate['verify_url'], 'download_url' => $certificate['download_url'], 'public_pdf_url' => $certificate['public_pdf_url'] ?? null] : null];
    }

    private function participantPackagePayload(?QuestionPackage $package): ?array
    {
        if (! $package) {
            return null;
        }

        return [
            'total_duration_minutes' => (int) $package->total_duration_minutes,
            'total_questions' => (int) $package->total_questions,
            'sections' => $package->sections
                ->sortBy('order_number')
                ->map(fn ($section) => [
                    'code' => $section->code,
                    'name' => $section->name,
                    'duration_minutes' => (int) $section->duration_minutes,
                    'question_count' => (int) $section->question_count,
                    'target_question_count' => (int) $section->target_question_count,
                ])
                ->values(),
        ];
    }

    private function expirePendingRegistrationAfterScheduleStart(ExamRegistration $registration, ?Payment $payment, bool $isPartnerSchedule): ?Payment
    {
        $schedule = $registration->schedule;

        if ($isPartnerSchedule || now()->lt($schedule->start_at)) {
            return $payment;
        }

        if (
            $registration->access_status === 'unlocked'
            || $registration->status === 'approved'
            || $this->isPaidPayment($payment)
        ) {
            return $payment;
        }

        $paymentStatus = strtolower((string) ($payment?->transaction_status ?? ''));
        $shouldExpire = $registration->status === 'pending_payment'
            || $paymentStatus === 'pending';

        if (! $shouldExpire) {
            return $payment;
        }

        DB::transaction(function () use ($registration, $payment) {
            $registration->update([
                'status' => 'expired',
                'access_status' => 'locked',
                'question_package_id' => null,
            ]);

            if ($payment && strtolower((string) $payment->transaction_status) === 'pending') {
                $payment->update([
                    'transaction_status' => 'expire',
                    'status_message' => 'Pendaftaran kedaluwarsa karena tes sudah dimulai.',
                ]);
            }
        });

        if ($payment && $payment->order_id) {
            try {
                $this->midtrans->cancel($payment->order_id);
            } catch (\Throwable) {
                // Local status is authoritative for exam access; Midtrans may already be expired/captured.
            }
        }

        return $payment?->fresh();
    }

    private function participantScheduleStatusKey(ExamRegistration $registration): string
    {
        $schedule = $registration->schedule;
        $attemptStatus = strtolower((string) ExamAttempt::where('user_id', $registration->user_id)
            ->where('schedule_id', $registration->schedule_id)
            ->latest()
            ->value('status'));

        if ($attemptStatus === 'in_progress') {
            return 'sedang-berlangsung';
        }

        if (in_array($attemptStatus, ['submitted', 'expired'], true)) {
            return 'telah-selesai';
        }

        if (now()->lt($schedule->start_at)) {
            return 'belum-dimulai';
        }

        if (now()->gte($schedule->end_at)) {
            return 'telah-selesai';
        }

        return 'sedang-berlangsung';
    }

    private function scheduleWindowStatusLabel(Schedule $schedule): string
    {
        if (now()->lt($schedule->start_at)) {
            return 'Belum Dimulai';
        }

        if (now()->gte($schedule->end_at)) {
            return 'Selesai';
        }

        return 'Sedang Berlangsung';
    }

    private function adminParticipantStatusMeta(ExamRegistration $registration, ?ExamAttempt $attempt, bool $isPartnerSchedule, ?Payment $payment): array
    {
        $terminationReason = strtolower((string) ($attempt?->metadata['termination_reason'] ?? ''));
        if ($attempt?->status === 'in_progress') {
            return ['label' => 'Sedang melaksanakan ujian', 'variant' => 'warning'];
        }

        if ($terminationReason === 'violation') {
            return ! empty($attempt->metadata['network_suspect'])
                ? ['label' => 'Terhenti (indikasi koneksi)', 'variant' => 'warning']
                : ['label' => 'Selesai dengan pelanggaran', 'variant' => 'warning'];
        }

        if ($attempt && in_array($attempt->status, ['submitted', 'expired'], true)) {
            return ['label' => 'Selesai', 'variant' => 'success'];
        }

        if ($isPartnerSchedule) {
            return $registration->user?->email_verified_at
                ? ['label' => 'Telah konfirmasi email', 'variant' => 'success']
                : ['label' => 'Belum konfirmasi email', 'variant' => 'warning'];
        }

        return (
            $registration->access_status === 'unlocked'
            || $registration->status === 'approved'
            || $this->isPaidPayment($payment)
        )
            ? ['label' => 'Telah melakukan pembayaran', 'variant' => 'success']
            : ['label' => 'Belum melakukan pembayaran', 'variant' => 'warning'];
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

        if ($registration->status === 'expired') {
            return 'expired';
        }

        if ($registration->status === 'cancelled') {
            return 'cancelled';
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

        return 'pending_payment';
    }

    private function participantStatusPayload(ExamRegistration $registration, bool $isPartnerSchedule, ?Payment $payment): array
    {
        if ($isPartnerSchedule) {
            return $registration->user?->email_verified_at
                ? ['key' => 'confirmed_email', 'label' => 'Telah konfirmasi email']
                : ['key' => 'unconfirmed_email', 'label' => 'Belum konfirmasi email'];
        }

        $paymentStatus = strtolower((string) ($payment?->transaction_status ?? ''));
        if ($registration->status === 'expired' || in_array($paymentStatus, ['expire', 'expired'], true)) {
            return ['key' => 'payment_expired', 'label' => 'Pendaftaran kedaluwarsa'];
        }

        if ($registration->status === 'cancelled' || in_array($paymentStatus, ['deny', 'cancel', 'cancelled', 'failure'], true)) {
            return ['key' => 'payment_cancelled', 'label' => 'Pendaftaran dibatalkan'];
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
        $registration = $this->certificates->assignToRegistration($registration);
        $schedule = $registration->schedule()->with('package')->firstOrFail();
        $package = $registration->package()->first() ?: $schedule->package;

        return [
            'message' => $message,
            'mode' => $payment ? 'paid' : 'free',
            'registration' => ['id' => $registration->id, 'status' => $registration->status, 'access_status' => $registration->access_status],
            'payment' => $payment ? ['id' => $payment->id, 'order_id' => $payment->order_id, 'amount' => (float) $payment->amount, 'transaction_status' => $payment->transaction_status, 'snap_token' => $payment->snap_token, 'snap_redirect_url' => $payment->snap_redirect_url] : null,
            'midtrans' => $payment ? ['client_key' => config('midtrans.client_key'), 'snap_token' => $payment->snap_token, 'redirect_url' => $payment->snap_redirect_url] : null,
            'data' => ['schedule_id' => $schedule->id, 'registration_id' => $registration->id, 'payment_id' => $payment?->id, 'order_id' => $payment?->order_id, 'snap_token' => $payment?->snap_token, 'redirect_url' => $payment?->snap_redirect_url, 'amount' => (float) ($payment?->amount ?? $schedule->price), 'picked' => $package ? ['question_package_id' => $package->id, 'package_name' => $package->name] : null],
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
        $sectionScores = $this->sectionScoresPayload($a);
        $certificate = $a->scores->isNotEmpty() ? $this->certificateData->payload($a) : null;

        return ['id' => $a->id, 'date' => $a->schedule->start_at->format('Y-m-d H:i:s'), 'start_time' => $a->schedule->start_at->format('H:i:s'), 'end_time' => $a->schedule->end_at->format('H:i:s'), 'status' => $a->status, 'partner' => null, 'score_total' => (float) $a->score_total, 'score_breakdown' => $a->score_breakdown, 'scores' => [...$sectionScores, 'total' => (float) $a->score_total], 'certificate' => $certificate ? ['certificate_no' => $certificate['certificate_no'], 'verify_url' => $certificate['verify_url'], 'download_url' => $certificate['download_url']] : null];
    }

    private function sectionScoresPayload(ExamAttempt $attempt): array
    {
        $scoresBySectionId = $attempt->scores->keyBy('exam_section_id');
        $sectionScores = [
            'verbal' => 0.0,
            'kuantitatif' => 0.0,
            'logika' => 0.0,
            'spasial' => 0.0,
        ];

        foreach (($attempt->package?->sections ?? collect())->sortBy('order_number') as $section) {
            $code = strtolower((string) ($section->code ?? ''));
            if (! array_key_exists($code, $sectionScores)) {
                continue;
            }

            $sectionScores[$code] = (float) ($scoresBySectionId->get($section->id)?->final_score ?? 0);
        }

        return [...$sectionScores, 'total' => (float) ($attempt->score_total ?? 0)];
    }
}
