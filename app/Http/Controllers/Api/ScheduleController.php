<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleRequest;
use App\Models\ExamAttempt;
use App\Models\ExamRegistration;
use App\Models\Payment;
use App\Models\Partner;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Services\CertificateTemplateService;
use App\Services\CertificateDataService;
use App\Services\ExamAttemptService;
use App\Services\PartnerInvitationService;
use App\Services\QuestionPackageAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    public function __construct(
        private ExamAttemptService $examAttemptService,
        private QuestionPackageAssignmentService $packageAssignments,
        private CertificateDataService $certificateData,
        private CertificateTemplateService $certificateTemplates,
    ) {}

    public function index(Request $request)
    {
        $query = Schedule::query()
            ->with(['package', 'registrations', 'partner'])
            ->latest('start_at');

        $this->applyIndexFilters($query, $request);

        if ($request->boolean('export')) {
            return $this->downloadSchedulesExport((clone $query)->get());
        }

        return $query
            ->paginate($request->integer('per_page', 10))
            ->through(fn ($s) => $this->legacy($s));
    }

    public function upcoming()
    {
        return Schedule::query()
            ->where('status', 'published')
            ->whereNull('partner_id')
            ->where('is_partner', false)
            ->where('start_at', '>', now())
            ->where(function (Builder $query) {
                $query
                    ->whereNull('registration_end_at')
                    ->orWhere('registration_end_at', '>=', now());
            })
            ->orderBy('start_at')
            ->limit(10)
            ->get()
            ->map(fn ($s) => $this->legacy($s));
    }

    public function store(StoreScheduleRequest $request)
    {
        $schedule = Schedule::create([
            ...$this->scheduleData($request->validated(), true),
            'created_by' => $request->user()->id,
            'certificate_id' => $this->certificateTemplates->active()?->id,
        ]);

        return response()->json(['message' => 'Jadwal dibuat.', 'data' => $this->legacy($schedule->load('package'))], 201);
    }

    public function show(Request $request, Schedule $schedule)
    {
        $participantQuery = ExamRegistration::query()
            ->where('schedule_id', $schedule->id)
            ->with(['user', 'package', 'payment', 'payments']);

        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $participantQuery->whereHas('user', function (Builder $query) use ($term) {
                $query
                    ->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        $participants = $participantQuery
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 10));

        $userIds = collect($participants->items())
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values();

        $attempts = ExamAttempt::query()
            ->where('schedule_id', $schedule->id)
            ->when($userIds->isNotEmpty(), fn (Builder $query) => $query->whereIn('user_id', $userIds))
            ->with(['scores', 'package.sections'])
            ->get()
            ->map(function (ExamAttempt $attempt) {
                if (in_array($attempt->status, ['submitted', 'expired'], true)) {
                    return $this->examAttemptService
                        ->recalculateCompletedAttempt($attempt)
                        ->loadMissing(['scores', 'package.sections']);
                }

                return $attempt->loadMissing(['scores', 'package.sections']);
            });

        return $this->legacy(
            $schedule->load(['package.sections', 'partner']),
            $participants,
            $attempts,
        );
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
        abort_if(now()->greaterThanOrEqualTo($schedule->start_at), 422, 'Peserta tidak dapat ditambahkan setelah waktu tes dimulai.');

        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $result = app(PartnerInvitationService::class)->registerManualParticipant($schedule, $request->user(), [
            'user_id' => $data['user_id'] ?? null,
            'email' => $data['email'],
            'name' => $data['name'] ?? '',
            'registration_source' => $schedule->partner_id || $schedule->is_partner ? 'partner' : 'admin',
        ]);

        return [
            'message' => 'Peserta ditambahkan.',
            'participant' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'created_user' => $result['created_user'],
                'sent_invitation' => $result['sent_invitation'],
                'sent_confirmation' => $result['sent_confirmation'],
            ],
            'schedule' => $this->legacy($schedule->fresh([
                'package.sections',
                'partner',
                'registrations.user',
                'registrations.package',
                'registrations.payment',
                'registrations.payments',
                'attempts.scores',
            ])),
        ];
    }

    public function removeParticipant(Schedule $schedule, int $userId)
    {
        ExamRegistration::where('schedule_id', $schedule->id)->where('user_id', $userId)->delete();

        return ['message' => 'Peserta dihapus.'];
    }

    public function grantRetake(Request $request, Schedule $schedule, int $userId)
    {
        $registration = ExamRegistration::where('schedule_id', $schedule->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        abort_if($registration->access_status !== 'unlocked', 422, 'Peserta belum memiliki akses ujian.');
        abort_unless(in_array($registration->status, ['paid', 'approved'], true), 422, 'Peserta belum siap mengikuti ujian.');

        $this->examAttemptService->grantRetake($registration, $request->user());

        return [
            'message' => 'Peserta diizinkan mengulang ujian.',
            'schedule' => $this->legacy($schedule->fresh([
                'package.sections',
                'partner',
                'registrations.user',
                'registrations.package',
                'registrations.payment',
                'registrations.payments',
                'attempts.scores',
            ])),
        ];
    }

    public function importPartnerParticipants(Request $request, Schedule $schedule, PartnerInvitationService $invitations)
    {
        abort_if(now()->greaterThanOrEqualTo($schedule->start_at), 422, 'Peserta tidak dapat diimport setelah waktu tes dimulai.');

        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240']]);
        $summary = $invitations->import($schedule, $data['file'], $request->user());

        return ['message' => 'Import peserta mitra diproses.', 'summary' => $summary];
    }

    public function partnerImportTemplate(Schedule $schedule)
    {
        abort_unless($schedule->is_partner || $schedule->partner_id, 422, 'Jadwal ini bukan jadwal mitra.');
        $path = $this->createPartnerImportTemplateXlsx();

        return response()->download(
            $path,
            'template-import-peserta-mitra.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    public function exportParticipants(Request $request, Schedule $schedule)
    {
        $query = ExamRegistration::query()
            ->where('schedule_id', $schedule->id)
            ->with(['user', 'package', 'payment', 'payments']);

        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $query->whereHas('user', function (Builder $builder) use ($term) {
                $builder
                    ->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        $registrations = $query->orderByDesc('id')->get();
        $attempts = ExamAttempt::query()
            ->where('schedule_id', $schedule->id)
            ->whereIn('user_id', $registrations->pluck('user_id')->filter()->unique()->values())
            ->with(['scores', 'package.sections'])
            ->get()
            ->map(function (ExamAttempt $attempt) {
                if (in_array($attempt->status, ['submitted', 'expired'], true)) {
                    return $this->examAttemptService
                        ->recalculateCompletedAttempt($attempt)
                        ->loadMissing(['scores', 'package.sections']);
                }

                return $attempt->loadMissing(['scores', 'package.sections']);
            });

        $rows = [[
            'No', 'Nama', 'Email', 'Status', 'Paket', 'Verbal', 'Kuantitatif', 'Logika', 'Spasial', 'Total',
        ]];

        $isPartnerSchedule = (bool) ($schedule->is_partner || $schedule->partner_id);

        foreach ($registrations->values() as $index => $registration) {
            $attempt = $attempts->where('user_id', $registration->user_id)->sortByDesc('id')->first();
            $payment = $this->resolvedRegistrationPayment($registration);
            $statusMeta = $this->participantStatusMeta($registration, $attempt, $isPartnerSchedule, $payment);
            $scores = $attempt ? $this->sectionScores($attempt) : [];

            $rows[] = [
                (string) ($index + 1),
                (string) ($registration->user?->name ?? '-'),
                (string) ($registration->user?->email ?? '-'),
                (string) ($statusMeta['label'] ?? '-'),
                (string) ($registration->package?->name ?? '-'),
                $this->scoreExportValue($scores['verbal'] ?? null),
                $this->scoreExportValue($scores['kuantitatif'] ?? null),
                $this->scoreExportValue($scores['logika'] ?? null),
                $this->scoreExportValue($scores['spasial'] ?? null),
                $this->scoreExportValue($scores['total'] ?? null),
            ];
        }

        $path = $this->createXlsxFile($rows, 'Peserta Jadwal');

        return response()->download(
            $path,
            'schedule-'.$schedule->id.'-participants-'.now()->format('Ymd-His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    public function exportPartnerSchedules(Request $request, Partner $partner)
    {
        $schedules = Schedule::query()
            ->where('partner_id', $partner->id)
            ->withCount('registrations')
            ->latest('start_at')
            ->get();

        $rows = [[
            'No', 'ID Jadwal', 'Tanggal', 'Jam Mulai', 'Jam Selesai', 'Harga', 'Kapasitas', 'Peserta', 'Status',
        ]];

        foreach ($schedules->values() as $index => $schedule) {
            $rows[] = [
                (string) ($index + 1),
                (string) $schedule->id,
                $schedule->start_at->translatedFormat('d M Y'),
                $schedule->start_at->format('H:i'),
                $schedule->end_at->format('H:i'),
                (string) ((int) round((float) $schedule->price)),
                (string) $schedule->quota,
                (string) $schedule->registrations_count,
                $this->scheduleWindowStatusLabel($schedule),
            ];
        }

        $path = $this->createXlsxFile($rows, 'Jadwal Mitra');

        return response()->download(
            $path,
            'partner-'.$partner->id.'-schedules-'.now()->format('Ymd-His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    private function legacy(
        Schedule $s,
        ?LengthAwarePaginator $participantPage = null,
        ?Collection $attempts = null,
    ): array
    {
        $start = $s->start_at;
        $attempts = $attempts ?? ($s->relationLoaded('attempts')
            ? $s->attempts->map(function (ExamAttempt $attempt) {
                if (in_array($attempt->status, ['submitted', 'expired'], true)) {
                    return $this->examAttemptService->recalculateCompletedAttempt($attempt)->loadMissing(['scores', 'package.sections']);
                }

                return $attempt->loadMissing(['scores', 'package.sections']);
            })
            : collect());
        $participantItems = $participantPage
            ? collect($participantPage->items())
            : ($s->relationLoaded('registrations') ? $s->registrations : collect());

        $isPartnerSchedule = (bool) ($s->is_partner || $s->partner_id);
        $availablePackages = $this->packageAssignments->availablePackages($s);

        return ['id' => $s->id, 'title' => $s->title, 'description' => $s->description, 'date' => $start->format('Y-m-d H:i:s'), 'start_time' => $start->format('H:i:s'), 'end_time' => $s->end_at->format('H:i:s'), 'start_at' => $start->toISOString(), 'end_at' => $s->end_at->toISOString(), 'price' => (string) $s->price, 'capacity' => $s->quota, 'quota' => $s->quota, 'status' => $this->scheduleWindowStatus($s), 'is_partner' => $isPartnerSchedule, 'partner_id' => $s->partner_id, 'partner' => $s->partner ? ['id' => $s->partner->id, 'name' => $s->partner->name] : null, 'current_participants' => $s->registrations()->count(), 'question_package_id' => $s->question_package_id, 'packages' => $availablePackages->map(fn ($package) => ['id' => $package->id, 'name' => $package->name, 'status' => $package->status, 'total_questions' => $package->total_questions, 'total_duration_minutes' => $package->total_duration_minutes])->values(), 'attempt_count' => $attempts->count(), 'submitted_count' => $attempts->whereIn('status', ['submitted', 'expired'])->count(), 'participants' => ['data' => $participantItems->map(function ($r) use ($attempts, $isPartnerSchedule) {
            $attempt = $attempts->where('user_id', $r->user_id)->sortByDesc('id')->first();
            $payment = $this->resolvedRegistrationPayment($r);
            $statusMeta = $this->participantStatusMeta($r, $attempt, $isPartnerSchedule, $payment);
            $package = $r->package;
            $hasCompletedAttempt = $attempt && in_array($attempt->status, ['submitted', 'expired'], true);
            $certificate = $hasCompletedAttempt ? $this->certificateData->fromRegistration($r, true) : null;

            return [
                'registration_id' => $r->id,
                'id' => $r->user_id,
                'name' => $r->user?->name,
                'email' => $r->user?->email,
                'status' => $r->status,
                'attempt_id' => $attempt?->id,
                'attempt_status' => $attempt?->status,
                'status_label' => $statusMeta['label'],
                'status_variant' => $statusMeta['variant'],
                'email_verified_at' => $r->user?->email_verified_at?->toISOString(),
                'question_package' => $package ? ['id' => $package->id, 'name' => $package->name] : null,
                'scores' => $attempt ? $this->sectionScores($attempt) : null,
                'certificate_no' => $r->certificate_no,
                'certificate_issued_at' => $r->certificate_issued_at?->toISOString(),
                'certificate_revoked_at' => $r->certificate_revoked_at?->toISOString(),
                'certificate_download_url' => $certificate['download_url'] ?? null,
                'can_retake' => ! $r->certificate_issued_at,
            ];
        })->values(), 'current_page' => $participantPage?->currentPage() ?? 1, 'last_page' => $participantPage?->lastPage() ?? 1, 'per_page' => $participantPage?->perPage() ?? 100, 'total' => $participantPage?->total() ?? $s->registrations()->count(), 'from' => $participantPage?->firstItem(), 'to' => $participantPage?->lastItem()]];
    }

    private function applyIndexFilters(Builder $query, Request $request): void
    {
        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $driver = $query->getModel()->getConnection()->getDriverName();
            $dateTimeExpression = $driver === 'sqlite'
                ? "strftime('%Y-%m-%d %H:%M:%S', start_at)"
                : "DATE_FORMAT(start_at, '%Y-%m-%d %H:%i:%s')";
            $dateExpression = $driver === 'sqlite'
                ? "strftime('%d/%m/%Y %H:%M', start_at)"
                : "DATE_FORMAT(start_at, '%d/%m/%Y %H:%i')";

            $query->where(function (Builder $builder) use ($term, $dateTimeExpression, $dateExpression) {
                $builder
                    ->where('title', 'like', '%'.$term.'%')
                    ->orWhereHas('partner', fn (Builder $partnerQuery) => $partnerQuery->where('name', 'like', '%'.$term.'%'))
                    ->orWhereRaw($dateTimeExpression.' like ?', ['%'.$term.'%'])
                    ->orWhereRaw($dateExpression.' like ?', ['%'.$term.'%']);

                if (ctype_digit($term)) {
                    $builder->orWhere('id', (int) $term);
                }
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('start_at', '>=', Carbon::parse((string) $request->string('date_from'))->toDateString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('start_at', '<=', Carbon::parse((string) $request->string('date_to'))->toDateString());
        }

        if ($request->filled('status')) {
            $this->applyStatusFilter($query, (string) $request->string('status'));
        }
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        $normalized = strtolower(trim($status));
        $now = now();

        if (in_array($normalized, ['belum-dimulai', 'belum dimulai'], true)) {
            $query->where('start_at', '>', $now);

            return;
        }

        if (in_array($normalized, ['sedang-berlangsung', 'sedang berlangsung'], true)) {
            $query->where('start_at', '<=', $now)
                ->where('end_at', '>', $now);

            return;
        }

        if (in_array($normalized, ['selesai', 'telah-selesai', 'telah selesai'], true)) {
            $query->where('end_at', '<=', $now);
        }
    }

    private function scheduleWindowStatus(Schedule $schedule): string
    {
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
        return match ($this->scheduleWindowStatus($schedule)) {
            'belum-dimulai' => 'Belum Dimulai',
            'sedang-berlangsung' => 'Sedang Berlangsung',
            default => 'Selesai',
        };
    }

    private function scoreExportValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        $numeric = (float) $value;

        return floor($numeric) === $numeric
            ? (string) (int) $numeric
            : number_format($numeric, 2, '.', '');
    }

    private function downloadSchedulesExport(Collection $schedules)
    {
        $rows = [
            ['No', 'ID Jadwal', 'Tanggal', 'Jam Mulai', 'Jam Selesai', 'Harga', 'Partner', 'Kapasitas', 'Peserta', 'Status'],
        ];

        foreach ($schedules->values() as $index => $schedule) {
            $rows[] = [
                (string) ($index + 1),
                (string) $schedule->id,
                $schedule->start_at->translatedFormat('d M Y'),
                $schedule->start_at->format('H:i'),
                $schedule->end_at->format('H:i'),
                (string) ((int) round((float) $schedule->price)),
                $schedule->partner?->name ?? '-',
                (string) $schedule->quota,
                (string) $schedule->registrations->count(),
                $this->scheduleWindowStatusLabel($schedule),
            ];
        }

        $path = $this->createXlsxFile($rows, 'Schedules');

        return response()->download(
            $path,
            'schedules-'.now()->format('Ymd-His').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    private function resolvedRegistrationPayment(ExamRegistration $registration): ?Payment
    {
        $payments = $registration->relationLoaded('payments')
            ? $registration->payments
            : $registration->payments()->latest('id')->get();

        if ($payments->isNotEmpty()) {
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

        return $registration->relationLoaded('payment') ? $registration->payment : $registration->payment()->first();
    }

    private function participantStatusMeta(ExamRegistration $registration, ?ExamAttempt $attempt, bool $isPartnerSchedule, ?Payment $payment): array
    {
        $terminationReason = strtolower((string) ($attempt?->metadata['termination_reason'] ?? ''));
        if ($attempt?->status === 'in_progress') {
            return ['label' => 'Sedang melaksanakan ujian', 'variant' => 'warning'];
        }

        if ($terminationReason === 'violation') {
            return ['label' => 'Selesai dengan pelanggaran', 'variant' => 'warning'];
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

    private function isPaidPayment(?Payment $payment): bool
    {
        if (! $payment) {
            return false;
        }

        return in_array(strtolower((string) $payment->transaction_status), ['capture', 'settlement', 'paid'], true) || $payment->paid_at !== null;
    }

    private function sectionScores(ExamAttempt $attempt): array
    {
        $scoreMap = [
            'verbal' => null,
            'kuantitatif' => null,
            'logika' => null,
            'spasial' => null,
            'total' => $attempt->score_total !== null ? (float) $attempt->score_total : null,
        ];

        $sections = $attempt->package?->sections instanceof Collection
            ? $attempt->package->sections->sortBy('order_number')
            : collect();
        $scoresBySectionId = $attempt->scores->keyBy('exam_section_id');

        foreach ($sections as $section) {
            $code = strtolower((string) ($section->code ?? ''));
            if (! array_key_exists($code, $scoreMap)) {
                continue;
            }

            $scoreMap[$code] = (float) ($scoresBySectionId->get($section->id)?->final_score ?? 0);
        }

        return $scoreMap;
    }

    private function createPartnerImportTemplateXlsx(): string
    {
        return $this->createXlsxFile([
            ['name', 'email'],
            ['Contoh Peserta', 'peserta@example.com'],
        ], 'Peserta Mitra', 'partner-import-template-');
    }

    private function createXlsxFile(array $rows, string $sheetName, string $prefix = 'xlsx-export-'): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            abort(500, 'Gagal membuat file template.');
        }

        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Gagal menyiapkan file template.');
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbookXml($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->xlsxStylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxWorksheetXml($rows));
        $zip->close();

        return $path;
    }

    private function xlsxContentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML;
    }

    private function xlsxRootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    private function xlsxWorkbookXml(string $sheetName): string
    {
        $escapedSheetName = htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return str_replace('XML_PLACEHOLDER_SHEET_NAME', $escapedSheetName, <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="XML_PLACEHOLDER_SHEET_NAME" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);
    }

    private function xlsxWorkbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function xlsxStylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1">
    <font>
      <sz val="11"/>
      <name val="Calibri"/>
      <family val="2"/>
    </font>
  </fonts>
  <fills count="1">
    <fill>
      <patternFill patternType="none"/>
    </fill>
  </fills>
  <borders count="1">
    <border>
      <left/>
      <right/>
      <top/>
      <bottom/>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>
XML;
    }

    private function xlsxWorksheetXml(array $rows): string
    {
        $lastRow = max(1, count($rows));
        $maxColumns = max(1, collect($rows)->map(fn (array $row) => count($row))->max() ?? 1);
        $dimension = 'A1:'.$this->xlsxColumnName($maxColumns).$lastRow;

        $rowXml = collect($rows)->map(function (array $row, int $rowIndex) {
            $cells = collect($row)->map(function (string $value, int $columnIndex) use ($rowIndex) {
                $cellRef = $this->xlsxColumnName($columnIndex + 1).($rowIndex + 1);
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                return '<c r="'.$cellRef.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
            })->implode('');

            return '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        })->implode('');

return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <dimension ref="{$dimension}"/>
  <sheetViews>
    <sheetView workbookViewId="0"/>
  </sheetViews>
  <sheetFormatPr defaultRowHeight="15"/>
  <sheetData>{$rowXml}</sheetData>
</worksheet>
XML;
    }

    private function xlsxColumnName(int $columnNumber): string
    {
        $name = '';

        while ($columnNumber > 0) {
            $mod = ($columnNumber - 1) % 26;
            $name = chr(65 + $mod).$name;
            $columnNumber = intdiv($columnNumber - 1, 26);
        }

        return $name;
    }

    private function scheduleData(array $data, bool $creating = false): array
    {
        $package = ! empty($data['question_package_id'])
            ? QuestionPackage::findOrFail($data['question_package_id'])
            : $this->packageAssignments->availablePackages()->sortBy('id')->first();

        throw_if(! $package, ValidationException::withMessages([
            'package' => 'Buat minimal satu paket soal published sebelum membuat jadwal.',
        ]));

        $data['question_package_id'] = ! empty($data['question_package_id']) ? $package->id : null;
        $registrationCloseOffset = max(0, (int) env('REGISTRATION_CLOSE_OFFSET_MINUTES'));
        $data['registration_end_at'] = Carbon::parse($data['start_at'])->subMinutes($registrationCloseOffset);
        if (empty($data['end_at'])) {
            $data['end_at'] = Carbon::parse($data['start_at'])->addMinutes((int) $package->total_duration_minutes);
        }
        if ($creating) {
            $data['title'] = 'Jadwal Tes - '.Carbon::parse($data['start_at'])->format('d/m/Y H:i');
            $data['status'] = 'published';
        }
        $data['is_partner'] = (bool) ($data['is_partner'] ?? false) || ! empty($data['partner_id']);
        if ($data['is_partner']) {
            $data['price'] = 0;
        } else {
            $data['partner_id'] = null;
        }

        return $data;
    }
}
