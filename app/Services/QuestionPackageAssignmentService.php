<?php

namespace App\Services;

use App\Models\ExamRegistration;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use Illuminate\Validation\ValidationException;

class QuestionPackageAssignmentService
{
    public function assignToRegistration(ExamRegistration $registration): QuestionPackage
    {
        if ($registration->question_package_id) {
            return $registration->package()->with([
                'sections' => fn ($query) => $query->orderBy('order_number'),
                'questions' => fn ($query) => $query->with('options'),
            ])->firstOrFail();
        }

        $package = $this->pickRandomPackage($registration->schedule);
        $registration->update(['question_package_id' => $package->id]);

        return $package->loadMissing([
            'sections' => fn ($query) => $query->orderBy('order_number'),
            'questions' => fn ($query) => $query->with('options'),
        ]);
    }

    public function pickRandomPackage(?Schedule $schedule = null): QuestionPackage
    {
        $query = QuestionPackage::query()
            ->where('status', 'published')
            ->whereHas('questions');

        $package = $query->inRandomOrder()->first();

        throw_if(
            ! $package,
            ValidationException::withMessages([
                'package' => 'Belum ada paket soal published yang siap diacak.',
            ])
        );

        return $package;
    }

    public function availablePackages(?Schedule $schedule = null)
    {
        return QuestionPackage::query()
            ->where('status', 'published')
            ->whereHas('questions')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'total_questions', 'total_duration_minutes']);
    }
}
