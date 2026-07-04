<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\ExamRegistration;
use App\Services\CertificateDataService;
use App\Services\CertificateIssueService;
use App\Services\CertificateTemplateService;
use App\Services\CertificateVerificationService;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function __construct(
        private CertificateDataService $certificateData,
        private CertificateIssueService $issuer,
        private CertificateTemplateService $templates,
        private CertificateVerificationService $verification,
    ) {}

    public function data(Request $request, string $uuid)
    {
        $registration = $this->verification->findValid($uuid, $request->query('h'));
        abort_unless($registration, 404, 'Sertifikat tidak ditemukan atau signature tidak valid.');

        return [
            'message' => $registration->certificate_revoked_at ? 'Sertifikat telah dicabut.' : 'Sertifikat valid.',
            'data' => $this->verification->metadata($registration),
        ];
    }

    public function pdf(Request $request, string $uuid)
    {
        $registration = $this->verification->findValid($uuid, $request->query('h'));
        abort_unless($registration, 404, 'Sertifikat tidak ditemukan atau signature tidak valid.');

        return $this->issuer->downloadResponse($registration);
    }

    public function pdfInline(Request $request, string $uuid)
    {
        $registration = $this->verification->findValid($uuid, $request->query('h'));
        abort_unless($registration, 404, 'Sertifikat tidak ditemukan atau signature tidak valid.');

        return $this->issuer->inlineResponse($registration);
    }

    public function previewPng(Request $request, string $uuid)
    {
        $registration = $this->verification->findValid($uuid, $request->query('h'));
        abort_unless($registration, 404, 'Sertifikat tidak ditemukan atau signature tidak valid.');

        return $this->issuer->previewPngResponse($registration);
    }

    public function downloadRegistration(ExamRegistration $registration)
    {
        return $this->issuer->downloadResponse($registration);
    }

    public function downloadOwnRegistration(Request $request, ExamRegistration $registration)
    {
        abort_unless($registration->user_id === $request->user()->id, 403);

        return $this->issuer->downloadResponse($registration);
    }

    public function revokeRegistration(ExamRegistration $registration)
    {
        $registration = $this->issuer->revoke($registration);

        return ['message' => 'Sertifikat dicabut.', 'data' => $registration];
    }

    public function index()
    {
        return ['data' => Certificate::latest()->get()];
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'name' => 'required|string',
            'period' => 'nullable|string',
            'year' => 'required|integer',
            'sign_name' => 'nullable|string',
            'sign_nip' => 'nullable|string',
            'letter_prefix' => 'required|string',
            'template' => 'required|file|image|max:5120',
            'sign_file' => 'nullable|file|image|max:5120',
            'stamp_file' => 'nullable|file|image|max:5120',
            'is_active' => 'nullable|boolean',
        ]);
        $active = $r->boolean('is_active', false) || Certificate::count() === 0;
        $d['template_file'] = $r->file('template')->store('certificates', 'public');
        if ($r->hasFile('sign_file')) {
            $d['sign_file'] = $r->file('sign_file')->store('certificates/signatures', 'public');
        }
        if ($r->hasFile('stamp_file')) {
            $d['stamp_file'] = $r->file('stamp_file')->store('certificates/stamps', 'public');
        }
        $d['is_active'] = false;
        unset($d['template']);
        $c = Certificate::create($d);
        if ($active) {
            $c = $this->templates->activate($c);
        }

        return response()->json(['message' => 'Template dibuat.', 'data' => $c], 201);
    }

    public function show(Certificate $certificate)
    {
        return $certificate;
    }

    public function update(Request $r, Certificate $certificate)
    {
        $certificate->update($r->validate([
            'name' => 'sometimes|string',
            'period' => 'nullable|string',
            'year' => 'sometimes|integer',
            'sign_name' => 'nullable|string',
            'sign_nip' => 'nullable|string',
            'letter_prefix' => 'sometimes|string',
        ]));

        return ['message' => 'Template diperbarui.', 'data' => $certificate];
    }

    public function layout(Request $r, Certificate $certificate)
    {
        $data = $r->validate(['canvas' => 'required|array', 'fields' => 'required|array']);
        $certificate->update(['layout_json' => $data]);

        return ['message' => 'Layout disimpan.', 'data' => $certificate];
    }

    public function activate(Request $r, Certificate $certificate)
    {
        abort_unless($r->boolean('is_active', true), 422, 'Sistem harus memiliki satu template aktif.');
        $certificate = $this->templates->activate($certificate);

        return ['message' => 'Template diaktifkan.', 'data' => $certificate];
    }
}
