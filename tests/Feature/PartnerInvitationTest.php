<?php

namespace Tests\Feature;

use App\Mail\PartnerProfileInvitationMail;
use App\Mail\PartnerScheduleConfirmationMail;
use App\Models\ExamRegistration;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Models\User;
use App\Services\PartnerInvitationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartnerInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_schedule_is_hidden_from_public_and_participant_available_schedules(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $partnerSchedule = $this->createSchedule(true);
        $publicSchedule = $this->createSchedule(false);

        $this->getJson('/api/schedules/upcoming')
            ->assertOk()
            ->assertJsonMissing(['id' => $partnerSchedule->id])
            ->assertJsonFragment(['id' => $publicSchedule->id]);

        $this->actingAs($participant, 'sanctum')
            ->getJson('/api/participant/schedules')
            ->assertOk()
            ->assertJsonMissing(['id' => $partnerSchedule->id])
            ->assertJsonFragment(['id' => $publicSchedule->id]);

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$partnerSchedule->id}/register")
            ->assertForbidden()
            ->assertJsonPath('message', 'Jadwal ini khusus peserta mitra.');
    }

    public function test_import_new_partner_participant_creates_user_registration_invitation_and_email(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(true);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/schedules/{$schedule->id}/partner/import", [
                'file' => $this->csvUpload("name,email,phone,identity_number\nPeserta Mitra,mitra@example.com,0811111111,12345\n"),
            ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('summary.created_users', 1)
            ->assertJsonPath('summary.attached_to_schedule', 1)
            ->assertJsonPath('summary.sent_invitations', 1);

        $user = User::where('email', 'mitra@example.com')->firstOrFail();
        $registration = ExamRegistration::where('user_id', $user->id)->where('schedule_id', $schedule->id)->firstOrFail();
        $this->assertSame('approved', $registration->status);
        $this->assertSame('unlocked', $registration->access_status);
        $this->assertSame('import', $registration->registration_source);
        $this->assertNull($registration->payment_id);
        $this->assertDatabaseHas('partner_invitations', ['user_id' => $user->id, 'claimed_at' => null]);
        Mail::assertSent(PartnerProfileInvitationMail::class, fn ($mail) => $mail->hasTo('mitra@example.com'));
    }

    public function test_import_existing_completed_partner_participant_sends_schedule_confirmation(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(true);
        User::create([
            'name' => 'Peserta Lengkap',
            'email' => 'lengkap@example.com',
            'password' => 'Password=1',
            'role' => 'peserta',
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/schedules/{$schedule->id}/partner/import", [
                'file' => $this->csvUpload("name,email\nPeserta Lengkap,lengkap@example.com\n"),
            ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('summary.created_users', 0)
            ->assertJsonPath('summary.sent_confirmations', 1);

        $this->assertSame(1, User::where('email', 'lengkap@example.com')->count());
        Mail::assertSent(PartnerScheduleConfirmationMail::class, fn ($mail) => $mail->hasTo('lengkap@example.com'));
        Mail::assertNotSent(PartnerProfileInvitationMail::class);
    }

    public function test_import_existing_user_does_not_overwrite_account_name(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(true);
        $user = User::create([
            'name' => 'Andrian',
            'email' => 'andrian@example.com',
            'password' => 'Password=1',
            'role' => 'peserta',
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/schedules/{$schedule->id}/partner/import", [
                'file' => $this->csvUpload("name,email\nAri,andrian@example.com\n"),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary.created_users', 0)
            ->assertJsonPath('summary.attached_to_schedule', 1);

        $this->assertSame('Andrian', $user->fresh()->name);
        $this->assertDatabaseHas('exam_registrations', [
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'approved',
            'access_status' => 'unlocked',
        ]);
    }

    public function test_partner_onboarding_claim_completes_profile_and_allows_start_without_payment(): void
    {
        Storage::fake('public');
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(true);
        $user = User::create([
            'name' => 'Peserta Onboarding',
            'email' => 'onboarding@example.com',
            'password' => 'Password=1',
            'role' => 'peserta',
            'is_active' => true,
        ]);
        ExamRegistration::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'approved',
            'access_status' => 'unlocked',
            'registration_source' => 'import',
            'registered_at' => now(),
        ]);
        $token = app(PartnerInvitationService::class)->createInvitation($schedule, $user, $admin);

        $this->getJson('/api/partner/onboarding/preview?token='.$token)
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('requires_profile_completion', true);

        $claim = $this->postJson('/api/partner/onboarding/claim', [
            'token' => $token,
            'password' => 'Password=1',
            'password_confirmation' => 'Password=1',
            'id_type' => 'ktp',
            'birth_date' => '2000-01-01',
            'phone' => '081234567890',
            'id_image' => UploadedFile::fake()->image('ktp.jpg'),
        ])->assertOk()
            ->assertJsonPath('schedule_id', $schedule->id)
            ->assertJsonStructure(['token']);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($user->fresh()->profile_completed_at);

        $this->withHeader('Authorization', 'Bearer '.$claim->json('token'))
            ->postJson("/api/participant/schedules/{$schedule->id}/attempts")
            ->assertSuccessful()
            ->assertJsonPath('status', 'in_progress');
    }

    private function createSchedule(bool $partner): Schedule
    {
        $admin = User::where('role', 'administrator')->firstOrFail();
        $package = QuestionPackage::create([
            'name' => 'Paket Mitra Test',
            'status' => 'published',
            'total_duration_minutes' => 30,
            'total_questions' => 1,
            'target_total_questions' => 1,
            'created_by' => $admin->id,
        ]);
        $section = ExamSection::create([
            'question_package_id' => $package->id,
            'name' => 'Verbal',
            'code' => 'verbal',
            'order_number' => 1,
            'duration_minutes' => 30,
            'question_count' => 1,
            'target_question_count' => 1,
        ]);
        $question = Question::create([
            'question_package_id' => $package->id,
            'exam_section_id' => $section->id,
            'type' => 'single_choice',
            'question_text' => 'Sinonim akurat adalah ...',
            'score' => 1,
            'order_number' => 1,
        ]);
        foreach (['Tepat', 'Lambat', 'Samar', 'Berat'] as $i => $option) {
            $question->options()->create([
                'option_label' => chr(65 + $i),
                'option_text' => $option,
                'is_correct' => $option === 'Tepat',
                'order_number' => $i + 1,
            ]);
        }

        return Schedule::create([
            'question_package_id' => $package->id,
            'title' => $partner ? 'Jadwal Mitra Test' : 'Jadwal Umum Test',
            'start_at' => now()->subMinute(),
            'end_at' => now()->addMinutes(30),
            'registration_start_at' => now()->subDay(),
            'registration_end_at' => now()->addDay(),
            'price' => 0,
            'quota' => 100,
            'status' => 'published',
            'is_partner' => $partner,
            'created_by' => $admin->id,
        ]);
    }

    private function csvUpload(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'partner-import-');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'partner-import.csv', 'text/csv', null, true);
    }
}
