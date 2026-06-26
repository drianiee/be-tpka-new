<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduleRequest;
use App\Models\ExamAttempt;
use App\Models\ExamRegistration;
use App\Models\Payment;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Services\ExamAttemptService;
use App\Services\PartnerInvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ScheduleController extends Controller
{
    public function __construct(private ExamAttemptService $examAttemptService) {}

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
        return $this->legacy($schedule->load(['package.sections', 'registrations.user', 'registrations.payment', 'registrations.payments', 'attempts.scores']));
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

    private function legacy(Schedule $s): array
    {
        $start = $s->start_at;
        $attempts = $s->relationLoaded('attempts')
            ? $s->attempts->map(function (ExamAttempt $attempt) {
                if (in_array($attempt->status, ['submitted', 'expired'], true)) {
                    return $this->examAttemptService->recalculateCompletedAttempt($attempt)->loadMissing(['scores', 'package.sections']);
                }

                return $attempt->loadMissing(['scores', 'package.sections']);
            })
            : collect();

        $isPartnerSchedule = (bool) ($s->is_partner || $s->partner_id);

        return ['id' => $s->id, 'title' => $s->title, 'description' => $s->description, 'date' => $start->format('Y-m-d H:i:s'), 'start_time' => $start->format('H:i:s'), 'end_time' => $s->end_at->format('H:i:s'), 'start_at' => $start->toISOString(), 'end_at' => $s->end_at->toISOString(), 'price' => (string) $s->price, 'capacity' => $s->quota, 'quota' => $s->quota, 'status' => $s->status, 'is_partner' => $isPartnerSchedule, 'partner_id' => $s->partner_id, 'partner' => $s->partner ? ['id' => $s->partner->id, 'name' => $s->partner->name] : null, 'current_participants' => $s->registrations->count(), 'question_package_id' => $s->question_package_id, 'packages' => $s->package ? [['id' => $s->package->id, 'name' => $s->package->name, 'status' => $s->package->status, 'total_questions' => $s->package->total_questions, 'total_duration_minutes' => $s->package->total_duration_minutes]] : [], 'attempt_count' => $attempts->count(), 'submitted_count' => $attempts->whereIn('status', ['submitted', 'expired'])->count(), 'participants' => ['data' => $s->relationLoaded('registrations') ? $s->registrations->map(function ($r) use ($attempts, $isPartnerSchedule) {
            $attempt = $attempts->where('user_id', $r->user_id)->sortByDesc('id')->first();
            $payment = $this->resolvedRegistrationPayment($r);
            $statusMeta = $this->registrationStatusMeta($r, $isPartnerSchedule, $payment);

            return [
                'id' => $r->user_id,
                'name' => $r->user?->name,
                'email' => $r->user?->email,
                'status' => $r->status,
                'status_label' => $statusMeta['label'],
                'status_variant' => $statusMeta['variant'],
                'email_verified_at' => $r->user?->email_verified_at?->toISOString(),
                'scores' => $attempt ? $this->sectionScores($attempt) : null,
            ];
        })->values() : [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => $s->registrations->count()]];
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

    private function registrationStatusMeta(ExamRegistration $registration, bool $isPartnerSchedule, ?Payment $payment): array
    {
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
        $path = tempnam(sys_get_temp_dir(), 'partner-import-template-');
        if ($path === false) {
            abort(500, 'Gagal membuat file template.');
        }

        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Gagal menyiapkan file template.');
        }

        $rows = [
            ['name', 'email'],
            ['Contoh Peserta', 'peserta@example.com'],
        ];

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbookXml());
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

    private function xlsxWorkbookXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Peserta Mitra" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
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
  <dimension ref="A1:B2"/>
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
        $package = QuestionPackage::findOrFail($data['question_package_id']);
        if (empty($data['end_at'])) {
            $data['end_at'] = Carbon::parse($data['start_at'])->addMinutes((int) $package->total_duration_minutes);
        }
        if ($creating) {
            $data['title'] = $package->name.' - '.Carbon::parse($data['start_at'])->format('d/m/Y H:i');
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
