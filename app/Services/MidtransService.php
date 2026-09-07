<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;

class MidtransService
{
    public function createSnapTransaction(Payment $payment): array
    {
        if (app()->environment('testing')) {
            return [
                'token' => 'test-snap-token-'.$payment->order_id,
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/test-snap-token-'.$payment->order_id,
            ];
        }

        $this->configure();

        $user = $payment->user;
        $schedule = $payment->schedule;

        $payload = [
            'transaction_details' => [
                'order_id' => $payment->order_id,
                'gross_amount' => (int) round((float) $payment->amount),
            ],
            'customer_details' => [
                'first_name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'item_details' => [[
                'id' => 'schedule-'.$schedule->id,
                'price' => (int) round((float) $payment->amount),
                'quantity' => 1,
                'name' => $schedule->title,
            ]],
            'callbacks' => [
                'finish' => rtrim((string) config('app.frontend_url'), '/').'/payment/finish',
            ],
        ];

        $expiryAt = $schedule->registration_end_at ?: $schedule->start_at;
        if ($expiryAt && now()->lt($expiryAt)) {
            $payload['expiry'] = [
                'start_time' => now()->format('Y-m-d H:i:s O'),
                'unit' => 'minute',
                'duration' => (int) max(1, now()->diffInMinutes($expiryAt)),
            ];
        }

        try {
            $transaction = Snap::createTransaction($payload);
        } catch (\Throwable $e) {
            Log::error('Gagal membuat transaksi Snap Midtrans.', [
                'order_id' => $payment->order_id,
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'payment' => 'Tidak dapat menghubungi Midtrans saat ini. Coba beberapa saat lagi.',
            ]);
        }

        return [
            'token' => $transaction->token,
            'redirect_url' => $transaction->redirect_url,
        ];
    }

    public function validSignature(array $payload): bool
    {
        $signature = (string) ($payload['signature_key'] ?? '');
        if ($signature === '') {
            return false;
        }

        $raw = (string) ($payload['order_id'] ?? '')
            .(string) ($payload['status_code'] ?? '')
            .(string) ($payload['gross_amount'] ?? '')
            .(string) config('midtrans.server_key');

        return hash_equals(hash('sha512', $raw), $signature);
    }

    public function status(string $orderId): array
    {
        $this->configure();

        try {
            $response = Transaction::status($orderId);
        } catch (\Throwable $e) {
            Log::error('Gagal mengambil status transaksi Midtrans.', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'order_id' => 'Status pembayaran tidak dapat diambil dari Midtrans. Pastikan order id benar dan coba lagi.',
            ]);
        }

        return json_decode(json_encode($response), true) ?: [];
    }

    public function cancel(string $orderId): array
    {
        if (app()->environment('testing')) {
            return ['status_code' => '200', 'transaction_status' => 'cancel'];
        }

        $this->configure();

        try {
            $response = Transaction::cancel($orderId);
        } catch (\Throwable $e) {
            Log::error('Gagal membatalkan transaksi Midtrans.', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'order_id' => 'Pembatalan pembayaran ditolak Midtrans. Periksa status transaksinya terlebih dahulu.',
            ]);
        }

        return json_decode(json_encode($response), true) ?: [];
    }

    private function configure(): void
    {
        Config::$serverKey = (string) config('midtrans.server_key');
        Config::$isProduction = (bool) config('midtrans.is_production');
        Config::$isSanitized = (bool) config('midtrans.is_sanitized');
        Config::$is3ds = (bool) config('midtrans.is_3ds');
    }
}
