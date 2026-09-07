<?php

namespace Tests\Feature;

use App\Mail\PartnerProfileInvitationMail;
use App\Mail\PartnerScheduleConfirmationMail;
use App\Models\ExamRegistration;
use App\Models\ExamSection;
use App\Models\Partner;
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
        // Harus jadwal yang belum mulai: endpoint "upcoming" memang menyaring start_at > now().
        $partnerSchedule = $this->createFutureSchedule(true);
        $publicSchedule = $this->createFutureSchedule(false);

        $this->getJson('/api/schedules/upcoming')
            ->assertOk()
            ->assertJsonMissing(['title' => $partnerSchedule->title, 'is_partner' => true])
            ->assertJsonFragment(['id' => $publicSchedule->id, 'title' => $publicSchedule->title]);

        $this->actingAs($participant, 'sanctum')
            ->getJson('/api/participant/schedules')
            ->assertOk()
            ->assertJsonMissing(['id' => $partnerSchedule->id, 'title' => $partnerSchedule->title])
            ->assertJsonFragment(['id' => $publicSchedule->id, 'title' => $publicSchedule->title]);

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
        $schedule = $this->createFutureSchedule(true);

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
        $schedule = $this->createFutureSchedule(true);
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
        $schedule = $this->createFutureSchedule(true);
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

    public function test_partner_detail_returns_affiliated_schedules(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(true);
        $partner = Partner::findOrFail($schedule->partner_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/partners/{$partner->id}")
            ->assertOk()
            ->assertJsonPath('partner.id', $partner->id)
            ->assertJsonPath('partner.name', $partner->name)
            ->assertJsonPath('partner.schedules_count', 1)
            ->assertJsonPath('schedules.data.0.id', $schedule->id)
            ->assertJsonPath('schedules.data.0.partner_id', $partner->id)
            ->assertJsonPath('schedules.data.0.is_partner', true);
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

    public function test_admin_can_add_existing_participant_to_regular_schedule_and_send_confirmation_email(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $participant = User::create([
            'name' => 'Peserta Existing',
            'email' => 'existing@example.com',
            'password' => 'Password=1',
            'role' => 'peserta',
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed_at' => now(),
        ]);
        $schedule = $this->createFutureSchedule(false);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/schedules/{$schedule->id}/participants", [
                'user_id' => $participant->id,
                'email' => $participant->email,
                'name' => $participant->name,
            ])
            ->assertOk()
            ->assertJsonPath('participant.id', $participant->id)
            ->assertJsonPath('participant.created_user', false)
            ->assertJsonPath('participant.sent_confirmation', true)
            ->assertJsonPath('participant.sent_invitation', false);

        $this->assertDatabaseHas('exam_registrations', [
            'user_id' => $participant->id,
            'schedule_id' => $schedule->id,
            'status' => 'approved',
            'access_status' => 'unlocked',
            'registration_source' => 'admin',
        ]);
        Mail::assertSent(PartnerScheduleConfirmationMail::class, fn ($mail) => $mail->hasTo('existing@example.com'));
    }

    public function test_admin_can_add_new_participant_to_regular_schedule_and_send_onboarding_email(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createFutureSchedule(false);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/schedules/{$schedule->id}/participants", [
                'email' => 'manual@example.com',
                'name' => 'Peserta Manual',
            ])
            ->assertOk()
            ->assertJsonPath('participant.email', 'manual@example.com')
            ->assertJsonPath('participant.created_user', true)
            ->assertJsonPath('participant.sent_invitation', true)
            ->assertJsonPath('participant.sent_confirmation', false);

        $user = User::where('email', 'manual@example.com')->firstOrFail();
        $this->assertDatabaseHas('exam_registrations', [
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'approved',
            'access_status' => 'unlocked',
            'registration_source' => 'admin',
        ]);
        $this->assertDatabaseHas('partner_invitations', [
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
        ]);
        Mail::assertSent(PartnerProfileInvitationMail::class, fn ($mail) => $mail->hasTo('manual@example.com'));
    }

    public function test_admin_cannot_add_manual_participant_after_schedule_started(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(false)->forceFill([
            'start_at' => now()->subMinute(),
            'end_at' => now()->addHour(),
        ]);
        $schedule->save();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/schedules/{$schedule->id}/participants", [
                'email' => 'blocked@example.com',
                'name' => 'Blocked User',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Peserta tidak dapat ditambahkan setelah waktu tes dimulai.');
    }

    public function test_admin_cannot_import_partner_participants_after_schedule_started(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(true)->forceFill([
            'start_at' => now()->subMinute(),
            'end_at' => now()->addHour(),
        ]);
        $schedule->save();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/schedules/{$schedule->id}/partner/import", [
                'file' => $this->csvUpload("name,email\nPeserta Mitra,mitra-late@example.com\n"),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Peserta tidak dapat diimport setelah waktu tes dimulai.');
    }

    public function test_schedule_detail_filters_participants_by_search_query(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createFutureSchedule(false);
        $matchedUser = User::create([
            'name' => 'Peserta Alpha',
            'email' => 'alpha@example.com',
            'password' => 'Password=1',
            'role' => 'peserta',
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed_at' => now(),
        ]);
        $otherUser = User::create([
            'name' => 'Peserta Beta',
            'email' => 'beta@example.com',
            'password' => 'Password=1',
            'role' => 'peserta',
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed_at' => now(),
        ]);

        foreach ([$matchedUser, $otherUser] as $user) {
            ExamRegistration::create([
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'status' => 'approved',
                'access_status' => 'unlocked',
                'registration_source' => 'admin',
                'registered_at' => now(),
            ]);
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/schedules/{$schedule->id}?q=alpha")
            ->assertOk()
            ->assertJsonCount(1, 'participants.data')
            ->assertJsonPath('participants.data.0.email', 'alpha@example.com');
    }

    public function test_admin_can_export_filtered_schedule_participants_as_xlsx(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createFutureSchedule(false);
        $user = User::create([
            'name' => 'Peserta Export',
            'email' => 'export@example.com',
            'password' => 'Password=1',
            'role' => 'peserta',
            'is_active' => true,
            'email_verified_at' => now(),
            'profile_completed_at' => now(),
        ]);

        ExamRegistration::create([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'approved',
            'access_status' => 'unlocked',
            'registration_source' => 'admin',
            'registered_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/schedules/{$schedule->id}/participants/export?q=export");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('content-disposition');
    }

    public function test_admin_can_export_partner_schedules_as_xlsx(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createFutureSchedule(true);
        $partner = Partner::findOrFail($schedule->partner_id);

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/partners/{$partner->id}/schedules/export");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('content-disposition');
    }

    public function test_admin_can_filter_schedules_by_status_partner_and_date(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();

        $future = $this->createFutureSchedule(true);
        $future->partner->update(['name' => 'Partner Filter A']);

        $ongoing = $this->createSchedule(false)->forceFill([
            'start_at' => now()->subMinutes(10),
            'end_at' => now()->addMinutes(20),
        ]);
        $ongoing->save();

        $finished = $this->createSchedule(false)->forceFill([
            'start_at' => now()->subHours(2),
            'end_at' => now()->subHour(),
        ]);
        $finished->save();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/schedules?status=belum-dimulai&q=Partner%20Filter%20A&date_from='.$future->start_at->toDateString().'&date_to='.$future->start_at->toDateString())
            ->assertOk()
            ->assertJsonPath('data.0.id', $future->id)
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/schedules?status=sedang-berlangsung')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ongoing->id)
            ->assertJsonPath('data.0.status', 'sedang-berlangsung')
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/schedules?status=telah-selesai')
            ->assertOk()
            ->assertJsonFragment(['id' => $finished->id, 'status' => 'telah-selesai']);
    }

    public function test_admin_can_export_filtered_schedules_as_xlsx(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $schedule = $this->createFutureSchedule(true);
        $schedule->partner->update(['name' => 'Partner Export']);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/schedules?export=1&status=belum-dimulai&q=Partner%20Export');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('content-disposition');
    }

    private function createSchedule(bool $partner): Schedule
    {
        $admin = User::where('role', 'administrator')->firstOrFail();
        $partnerModel = $partner
            ? Partner::create([
                'name' => 'Mitra Test',
                'contact_name' => 'PIC Mitra',
                'contact_email' => 'mitra@example.com',
                'contact_phone' => '08123456789',
                'is_active' => true,
            ])
            : null;
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
            'partner_id' => $partnerModel?->id,
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

    private function createFutureSchedule(bool $partner): Schedule
    {
        $schedule = $this->createSchedule($partner);
        $schedule->forceFill([
            'start_at' => now()->addHour(),
            'end_at' => now()->addHours(2),
            'registration_start_at' => now()->subDay(),
            'registration_end_at' => now()->addDay(),
        ])->save();

        return $schedule->fresh();
    }

    private function csvUpload(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'partner-import-');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'partner-import.csv', 'text/csv', null, true);
    }
}
