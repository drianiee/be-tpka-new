<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\MidtransService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::query()->with(['user', 'schedule', 'registration'])->latest();

        $paidStatuses = ['settlement', 'capture', 'paid'];
        $failedStatuses = ['deny', 'cancel', 'expire', 'failure'];

        return [
            'summary' => [
                'income_paid_total' => (float) Payment::whereIn('transaction_status', $paidStatuses)->sum('amount'),
                'count_paid' => Payment::whereIn('transaction_status', $paidStatuses)->count(),
                'count_pending' => Payment::where('transaction_status', 'pending')->count(),
                'count_failed' => Payment::whereIn('transaction_status', $failedStatuses)->count(),
            ],
            'payments' => $query->paginate($request->integer('per_page', 10)),
        ];
    }

    public function show(Payment $payment)
    {
        return ['payment' => $payment->load(['user', 'schedule', 'registration'])];
    }

    public function callback(Request $request, MidtransService $midtrans)
    {
        $payload = $request->all();
        if (! $midtrans->validSignature($payload)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $payment = Payment::where('order_id', $payload['order_id'] ?? null)->firstOrFail();

        DB::transaction(function () use ($payment, $payload) {
            $status = (string) ($payload['transaction_status'] ?? $payment->transaction_status);
            $fraud = $payload['fraud_status'] ?? null;

            $payment->update([
                'transaction_id' => $payload['transaction_id'] ?? $payment->transaction_id,
                'transaction_status' => $status,
                'payment_type' => $payload['payment_type'] ?? $payment->payment_type,
                'fraud_status' => $fraud,
                'status_code' => $payload['status_code'] ?? $payment->status_code,
                'status_message' => $payload['status_message'] ?? $payment->status_message,
                'bank' => $payload['bank'] ?? $payload['permata_va_number'] ?? $payment->bank,
                'va_number' => $payload['va_number'] ?? $payload['permata_va_number'] ?? $this->firstVaNumber($payload) ?? $payment->va_number,
                'va_numbers' => $payload['va_numbers'] ?? $payment->va_numbers,
                'transaction_time' => $payload['transaction_time'] ?? $payment->transaction_time,
                'settlement_time' => $payload['settlement_time'] ?? $payment->settlement_time,
                'payload' => $payload,
            ]);

            $registration = $payment->registration;
            if (! $registration) {
                return;
            }

            if ($status === 'settlement' || ($status === 'capture' && in_array($fraud, [null, 'accept'], true))) {
                $payment->update(['paid_at' => $payment->paid_at ?? now()]);
                $registration->update(['status' => 'approved', 'access_status' => 'unlocked']);

                return;
            }

            if (in_array($status, ['deny', 'cancel', 'expire', 'failure'], true)) {
                $registration->update([
                    'status' => $status === 'expire' ? 'expired' : 'cancelled',
                    'access_status' => 'locked',
                ]);
            }
        });

        return ['status' => 'ok', 'message' => 'Callback processed.', 'order_id' => $payment->order_id];
    }

    private function firstVaNumber(array $payload): ?string
    {
        $rows = $payload['va_numbers'] ?? [];
        if (! is_array($rows) || ! isset($rows[0]) || ! is_array($rows[0])) {
            return null;
        }

        return $rows[0]['va_number'] ?? null;
    }
}
