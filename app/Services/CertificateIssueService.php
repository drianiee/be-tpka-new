<?php

namespace App\Services;

use App\Models\ExamRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CertificateIssueService
{
    public function __construct(
        private CertificateAssignmentService $assignment,
        private CertificateTemplateService $templates,
        private CertificateScoreProvider $scores,
        private CertificateRenderer $renderer,
    ) {}

    public function issue(ExamRegistration $registration): ExamRegistration
    {
        return DB::transaction(function () use ($registration) {
            $locked = ExamRegistration::query()
                ->whereKey($registration->id)
                ->with(['certificate', 'schedule', 'user'])
                ->lockForUpdate()
                ->firstOrFail();

            throw_if($locked->certificate_revoked_at, ValidationException::withMessages([
                'certificate' => 'Sertifikat sudah dicabut.',
            ]));

            if ($locked->certificate_file_path && Storage::disk('local')->exists($locked->certificate_file_path)) {
                return $locked;
            }

            $template = $locked->certificate ?: $this->templates->active();
            throw_unless($template, ValidationException::withMessages([
                'template' => 'Template sertifikat aktif belum tersedia.',
            ]));

            $locked = $this->assignment
                ->assignToRegistration($locked, $template)
                ->loadMissing(['certificate', 'schedule', 'user']);

            $scores = $this->scores->scoresForRegistration($locked);
            $verifyUrl = $this->frontendVerifyUrl($locked);
            $pdf = $this->renderer->render($locked->certificate, $this->renderData($locked, $scores), $verifyUrl);
            $path = 'certificates/issued/'.$this->safeFilename($locked).'.pdf';

            Storage::disk('local')->put($path, $pdf);

            $locked->forceFill([
                'certificate_issued_at' => $locked->certificate_issued_at ?: now(),
                'certificate_file_path' => $path,
            ])->save();

            return $locked->fresh(['certificate', 'schedule', 'user']);
        });
    }

    public function downloadResponse(ExamRegistration $registration)
    {
        $issued = $this->issue($registration);

        throw_unless($issued->certificate_file_path && Storage::disk('local')->exists($issued->certificate_file_path), ValidationException::withMessages([
            'certificate' => 'File PDF sertifikat tidak ditemukan.',
        ]));

        return response()->download(
            Storage::disk('local')->path($issued->certificate_file_path),
            $this->safeFilename($issued).'.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    public function inlineResponse(ExamRegistration $registration)
    {
        $issued = $this->issue($registration);

        throw_unless($issued->certificate_file_path && Storage::disk('local')->exists($issued->certificate_file_path), ValidationException::withMessages([
            'certificate' => 'File PDF sertifikat tidak ditemukan.',
        ]));

        return response()->file(
            Storage::disk('local')->path($issued->certificate_file_path),
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$this->safeFilename($issued).'.pdf"',
            ]
        );
    }

    public function previewPngResponse(ExamRegistration $registration)
    {
        $issued = $this->issue($registration)->loadMissing(['certificate', 'schedule', 'user']);

        throw_if($issued->certificate_revoked_at, ValidationException::withMessages([
            'certificate' => 'Sertifikat sudah dicabut.',
        ]));

        $template = $issued->certificate ?: $this->templates->active();
        throw_unless($template, ValidationException::withMessages([
            'template' => 'Template sertifikat aktif belum tersedia.',
        ]));

        $scores = $this->scores->scoresForRegistration($issued);
        $verifyUrl = $this->frontendVerifyUrl($issued);
        $png = $this->renderer->renderPreviewPng($template, $this->renderData($issued, $scores), $verifyUrl);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($issued).'.png"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function revoke(ExamRegistration $registration): ExamRegistration
    {
        $registration->forceFill(['certificate_revoked_at' => now()])->save();

        return $registration->fresh(['certificate']);
    }

    private function renderData(ExamRegistration $registration, array $scores): array
    {
        return [
            'participant_name' => $registration->user?->name,
            'certificate_no' => $registration->certificate_no,
            'exam_date' => $registration->schedule?->start_at?->translatedFormat('d F Y'),
            'score_verbal' => $this->formatScore($scores['verbal'] ?? 0),
            'score_kuantitatif' => $this->formatScore($scores['kuantitatif'] ?? 0),
            'score_logika' => $this->formatScore($scores['logika'] ?? 0),
            'score_spasial' => $this->formatScore($scores['spasial'] ?? 0),
            'score_total' => $this->formatScore($scores['total'] ?? 0),
        ];
    }

    private function formatScore(float|int $value): string
    {
        $value = (float) $value;

        return fmod($value, 1.0) === 0.0 ? (string) (int) $value : number_format($value, 2, '.', '');
    }

    private function safeFilename(ExamRegistration $registration): string
    {
        $base = Str::of((string) $registration->certificate_no)
            ->replaceMatches('/[^a-zA-Z0-9._-]+/', '-')
            ->trim('-')
            ->limit(120, '')
            ->value();

        return ($base !== '' ? $base : 'sertifikat').'-registration-'.$registration->id;
    }

    private function frontendVerifyUrl(ExamRegistration $registration): string
    {
        return rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/')
            ."/certificates/verify/{$registration->certificate_verify_uuid}?h={$registration->certificate_verify_hash}";
    }
}
