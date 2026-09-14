<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.idle_expiration' => 120, 'sanctum.expiration' => 1440]);
    }

    /** Guard Sanctum meng-cache user per instance aplikasi; lupakan supaya token divalidasi ulang. */
    private function me(array $headers)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/me', $headers);
    }

    private function login(): string
    {
        $this->seed(DatabaseSeeder::class);

        return $this->postJson('/api/auth/login', ['email' => 'admin@tpka.com', 'password' => 'password'])
            ->assertOk()
            ->json('token');
    }

    public function test_session_ends_after_idle_period(): void
    {
        Carbon::setTestNow('2026-09-14 08:00:00');
        $token = $this->login();
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->me($headers)->assertOk();

        // 119 menit tanpa aktivitas: masih hidup, dan tenggatnya ikut bergeser.
        Carbon::setTestNow('2026-09-14 09:59:00');
        $this->me($headers)->assertOk();

        // 121 menit sejak aktivitas terakhir: sesi berakhir.
        Carbon::setTestNow('2026-09-14 12:00:00');
        $this->me($headers)->assertUnauthorized();
    }

    public function test_activity_keeps_session_alive_beyond_idle_period(): void
    {
        Carbon::setTestNow('2026-09-14 08:00:00');
        $token = $this->login();
        $headers = ['Authorization' => 'Bearer '.$token];

        // Aktif tiap jam selama 5 jam: melewati batas idle 2 jam tapi tetap hidup.
        foreach (range(1, 5) as $hour) {
            Carbon::setTestNow(sprintf('2026-09-14 %02d:00:00', 8 + $hour));
            $this->me($headers)->assertOk();
        }

        $expiresAt = PersonalAccessToken::first()->expires_at;
        $this->assertTrue($expiresAt->equalTo(Carbon::parse('2026-09-14 15:00:00')));
    }

    public function test_session_never_outlives_absolute_maximum(): void
    {
        Carbon::setTestNow('2026-09-14 08:00:00');
        $token = $this->login();
        $headers = ['Authorization' => 'Bearer '.$token];

        // Aktif terus tiap jam; masih hidup sampai jam ke-23, lalu batas mutlak 24 jam menutupnya.
        foreach (range(1, 23) as $hour) {
            Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00')->addHours($hour));
            $this->me($headers)->assertOk();
        }

        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00')->addHours(24)->addMinute());
        $this->me($headers)->assertUnauthorized();
    }
}
