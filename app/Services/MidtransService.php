<?php

namespace App\Services;

use App\Models\Payment;
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

        $transaction = Snap::createTransaction([
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
                'finish' => rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')), '/').'/payment/finish',
            ],
        ]);

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

        $response = Transaction::status($orderId);

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
