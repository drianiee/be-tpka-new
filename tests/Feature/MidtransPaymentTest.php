<?php

namespace Tests\Feature;

use App\Models\ExamRegistration;
use App\Models\ExamSection;
use App\Models\Payment;
use App\Models\Question;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MidtransPaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $serverKey = 'test-midtrans-server-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['midtrans.server_key' => $this->serverKey]);
    }

    public function test_free_schedule_registration_unlocks_access_without_payment(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(0);

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$schedule->id}/register")
            ->assertSuccessful()
            ->assertJsonPath('mode', 'free')
            ->assertJsonPath('registration.status', 'approved')
            ->assertJsonPath('registration.access_status', 'unlocked')
            ->assertJsonPath('payment', null);

        $this->assertDatabaseHas('exam_registrations', [
            'user_id' => $participant->id,
            'schedule_id' => $schedule->id,
            'status' => 'approved',
            'access_status' => 'unlocked',
            'registration_source' => 'self',
        ]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paid_registration_waits_for_midtrans_callback_before_attempt_can_start(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(150000);

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$schedule->id}/register")
            ->assertSuccessful()
            ->assertJsonPath('mode', 'paid')
            ->assertJsonPath('registration.status', 'pending_payment')
            ->assertJsonPath('registration.access_status', 'locked')
            ->assertJsonPath('payment.transaction_status', 'pending')
            ->assertJsonStructure(['midtrans' => ['client_key', 'snap_token', 'redirect_url']]);

        $payment = Payment::firstOrFail();
        $this->assertStringStartsWith('test-snap-token-', (string) $payment->snap_token);

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$schedule->id}/attempts")
            ->assertForbidden()
            ->assertJsonPath('message', 'Pembayaran belum selesai.');

        $invalidPayload = $this->callbackPayload($payment);
        $invalidPayload['signature_key'] = 'invalid';
        $this->postJson('/api/payments/midtrans/callback', $invalidPayload)->assertForbidden();

        $payload = $this->callbackPayload($payment);
        $this->postJson('/api/payments/midtrans/callback', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('order_id', $payment->order_id);

        $this->postJson('/api/payments/midtrans/callback', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $payment->refresh();
        $registration = ExamRegistration::where('user_id', $participant->id)->where('schedule_id', $schedule->id)->firstOrFail();

        $this->assertSame('settlement', $payment->transaction_status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('approved', $registration->status);
        $this->assertSame('unlocked', $registration->access_status);

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$schedule->id}/attempts")
            ->assertSuccessful()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonMissing(['is_correct']);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_partner_schedule_is_not_self_registerable_but_admin_registration_unlocks_access(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(150000, true);

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$schedule->id}/register")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Jadwal ini khusus peserta mitra.');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/schedules/{$schedule->id}/participants", ['user_id' => $participant->id])
            ->assertOk()
            ->assertJsonPath('message', 'Peserta ditambahkan.');

        $registration = ExamRegistration::where('user_id', $participant->id)->where('schedule_id', $schedule->id)->firstOrFail();
        $this->assertSame('approved', $registration->status);
        $this->assertSame('unlocked', $registration->access_status);
        $this->assertSame('partner', $registration->registration_source);
        $this->assertNull($registration->payment_id);

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$schedule->id}/attempts")
            ->assertSuccessful()
            ->assertJsonPath('status', 'in_progress');
    }

    public function test_expired_payment_can_be_retried_with_new_order(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createSchedule(150000);

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$schedule->id}/register")
            ->assertSuccessful();

        $firstPayment = Payment::firstOrFail();
        $this->postJson('/api/payments/midtrans/callback', $this->callbackPayload($firstPayment, 'expire'))
            ->assertOk();

        $this->assertDatabaseHas('exam_registrations', [
            'user_id' => $participant->id,
            'schedule_id' => $schedule->id,
            'status' => 'expired',
            'access_status' => 'locked',
        ]);

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/participant/schedules/{$schedule->id}/register")
            ->assertSuccessful()
            ->assertJsonPath('registration.status', 'pending_payment')
            ->assertJsonPath('registration.access_status', 'locked');

        $this->assertDatabaseCount('payments', 2);
        $this->assertNotSame($firstPayment->order_id, Payment::latest('id')->firstOrFail()->order_id);
    }

    private function callbackPayload(Payment $payment, string $transactionStatus = 'settlement'): array
    {
        $grossAmount = number_format((float) $payment->amount, 2, '.', '');
        $statusCode = '200';

        return [
            'order_id' => $payment->order_id,
            'transaction_id' => 'midtrans-test-'.$payment->id,
            'transaction_status' => $transactionStatus,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'payment_type' => 'bank_transfer',
            'transaction_time' => now()->format('Y-m-d H:i:s'),
            'settlement_time' => now()->format('Y-m-d H:i:s'),
            'va_numbers' => [['bank' => 'bca', 'va_number' => '12345678901']],
            'signature_key' => hash('sha512', $payment->order_id.$statusCode.$grossAmount.$this->serverKey),
        ];
    }

    private function createSchedule(float $price, bool $partner = false): Schedule
    {
        $admin = User::where('role', 'administrator')->firstOrFail();
        $package = QuestionPackage::create([
            'name' => 'Paket TPA Midtrans Test',
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
            'title' => 'Jadwal Midtrans Test',
            'start_at' => now()->subMinute(),
            'end_at' => now()->addMinutes($package->total_duration_minutes),
            'registration_start_at' => now()->subDay(),
            'registration_end_at' => now()->addDay(),
            'price' => $price,
            'quota' => 100,
            'status' => 'published',
            'is_partner' => $partner,
            'created_by' => $admin->id,
        ]);
    }
}
