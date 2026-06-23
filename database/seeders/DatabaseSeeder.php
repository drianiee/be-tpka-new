<?php

namespace Database\Seeders;

use App\Models\ExamRegistration;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@example.com'], ['name' => 'Admin TPA', 'password' => Hash::make('password'), 'role' => 'administrator', 'is_active' => true, 'email_verified_at' => now()]);
        User::updateOrCreate(['email' => 'operator@example.com'], ['name' => 'Operator TPA', 'password' => Hash::make('password'), 'role' => 'operator', 'is_active' => true, 'email_verified_at' => now()]);
        $participant = User::updateOrCreate(['email' => 'peserta@example.com'], ['name' => 'Peserta Demo', 'password' => Hash::make('password'), 'role' => 'peserta', 'is_active' => true, 'email_verified_at' => now()]);

        $package = QuestionPackage::updateOrCreate(['name' => 'Paket TPA Demo'], ['description' => 'Paket internal untuk pengujian alur dasar.', 'status' => 'published', 'total_duration_minutes' => 30, 'total_questions' => 2, 'created_by' => $admin->id]);
        $section = ExamSection::updateOrCreate(['question_package_id' => $package->id, 'order_number' => 1], ['name' => 'Penalaran Umum', 'description' => 'Contoh subtes internal', 'duration_minutes' => 30, 'question_count' => 2]);

        $questions = [
            ['text' => 'Jika semua A adalah B dan semua B adalah C, maka semua A adalah ...', 'correct' => 'C', 'options' => ['A', 'B', 'C', 'D']],
            ['text' => 'Deret berikutnya dari 2, 4, 8, 16 adalah ...', 'correct' => '32', 'options' => ['18', '24', '30', '32']],
        ];
        foreach ($questions as $i => $item) {
            $question = Question::updateOrCreate(['question_package_id' => $package->id, 'exam_section_id' => $section->id, 'order_number' => $i + 1], ['type' => 'single_choice', 'question_text' => $item['text'], 'score' => 1, 'is_active' => true]);
            $question->options()->delete();
            foreach ($item['options'] as $j => $option) {
                $question->options()->create(['option_label' => chr(65 + $j), 'option_text' => $option, 'is_correct' => $option === $item['correct'], 'order_number' => $j + 1]);
            }
        }

        $schedule = Schedule::updateOrCreate(['title' => 'TPA Demo Aktif'], ['question_package_id' => $package->id, 'description' => 'Jadwal seed untuk mencoba exam player.', 'start_at' => now()->subHour(), 'end_at' => now()->addDay(), 'registration_start_at' => now()->subDay(), 'registration_end_at' => now()->addHours(12), 'price' => 0, 'quota' => 100, 'status' => 'published', 'created_by' => $admin->id]);
        ExamRegistration::updateOrCreate(['user_id' => $participant->id, 'schedule_id' => $schedule->id], ['status' => 'approved', 'access_status' => 'unlocked', 'registered_at' => now()]);
    }
}
