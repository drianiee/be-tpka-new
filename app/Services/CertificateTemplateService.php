<?php

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Support\Facades\DB;

class CertificateTemplateService
{
    public function active(): ?Certificate
    {
        return Certificate::where('is_active', true)->latest('id')->first();
    }

    public function activate(Certificate $certificate): Certificate
    {
        return DB::transaction(function () use ($certificate) {
            Certificate::query()->lockForUpdate()->get();
            Certificate::query()->whereKeyNot($certificate->id)->update(['is_active' => false]);
            $certificate->forceFill(['is_active' => true])->save();

            return $certificate->fresh();
        });
    }
}
