<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function index()
    {
        return ['data' => Certificate::latest()->get()];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string', 'period' => 'required|string', 'year' => 'required|integer', 'sign_name' => 'required|string', 'sign_nip' => 'required|string', 'letter_prefix' => 'required|string', 'template' => 'required|file|max:5120', 'is_active' => 'nullable|boolean']);
        $d['template_path'] = $r->file('template')->store('certificates', 'public');
        unset($d['template']);
        $c = Certificate::create($d);

        return response()->json(['message' => 'Template dibuat.', 'data' => $c], 201);
    }

    public function show(Certificate $certificate)
    {
        return $certificate;
    }

    public function update(Request $r, Certificate $certificate)
    {
        $certificate->update($r->validate(['name' => 'sometimes|string', 'period' => 'sometimes|string', 'year' => 'sometimes|integer', 'sign_name' => 'sometimes|string', 'sign_nip' => 'sometimes|string', 'letter_prefix' => 'sometimes|string']));

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
        Certificate::query()->update(['is_active' => false]);
        $certificate->update(['is_active' => $r->boolean('is_active', true)]);

        return ['message' => 'Template diaktifkan.', 'data' => $certificate];
    }
}
