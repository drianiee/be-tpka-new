<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\MidtransService;
use App\Services\QuestionPackageAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private QuestionPackageAssignmentService $packageAssignments,
    ) {}

    public function index(Request $request)
    {
        $query = Payment::query()->with(['user', 'schedule', 'registration'])->latest();

        $paidStatuses = ['settlement', 'capture', 'paid'];
        $failedStatuses = ['deny', 'cancel', 'expire', 'failure'];

        $query
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->string('q'));

                $q->where(function ($inner) use ($term) {
                    $inner
                        ->where('order_id', 'like', "%{$term}%")
                        ->orWhere('transaction_id', 'like', "%{$term}%")
                        ->orWhere('payment_type', 'like', "%{$term}%")
                        ->orWhere('transaction_status', 'like', "%{$term}%")
                        ->orWhereHas('user', function ($userQuery) use ($term) {
                            $userQuery
                                ->where('name', 'like', "%{$term}%")
                                ->orWhere('email', 'like', "%{$term}%");
                        })
                        ->orWhereHas('schedule', function ($scheduleQuery) use ($term) {
                            $scheduleQuery->where('title', 'like', "%{$term}%");
                        });
                });
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('transaction_status', (string) $request->string('status'));
            })
            ->when($request->filled('from'), function ($q) use ($request) {
                $q->whereDate('created_at', '>=', (string) $request->string('from'));
            })
            ->when($request->filled('to'), function ($q) use ($request) {
                $q->whereDate('created_at', '<=', (string) $request->string('to'));
            });

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
        $orderId = (string) ($payload['order_id'] ?? '');
        $transactionStatus = (string) ($payload['transaction_status'] ?? '');

        if (! $midtrans->validSignature($payload)) {
            Log::warning('Midtrans callback ditolak: signature tidak valid.', [
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
            ]);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $payment = Payment::where('order_id', $orderId)->first();

        // Order yang tidak dikenal tetap dijawab 200 supaya Midtrans berhenti mengirim ulang.
        // Efek sampingnya penting: setelah ini, 404 dari endpoint callback hanya bisa berarti
        // satu hal, yaitu rutenya tidak terjangkau.
        if (! $payment) {
            Log::warning('Midtrans callback untuk order yang tidak dikenal.', [
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
            ]);

            return response()->json([
                'status' => 'ignored',
                'message' => 'Order tidak dikenal.',
                'order_id' => $orderId,
            ]);
        }

        try {
            $this->applyPaymentStatus($payment, $payload);
        } catch (\Throwable $e) {
            // Sengaja dijawab 500 supaya Midtrans mengirim ulang, karena kegagalan
            // di sini biasanya sementara (database, jaringan) dan datanya belum tersimpan.
            Log::error('Midtrans callback gagal diproses.', [
                'order_id' => $orderId,
                'transaction_status' => $transactionStatus,
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Callback gagal diproses, silakan kirim ulang.',
                'order_id' => $orderId,
            ], 500);
        }

        Log::info('Midtrans callback diproses.', [
            'order_id' => $orderId,
            'transaction_status' => $transactionStatus,
            'payment_id' => $payment->id,
        ]);

        return ['status' => 'ok', 'message' => 'Callback processed.', 'order_id' => $payment->order_id];
    }

    public function sync(Request $request, MidtransService $midtrans)
    {
        if ($request->user() && $request->user()->role === 'peserta') {
            abort(403, 'Sinkronisasi pembayaran peserta hanya diproses melalui callback Midtrans.');
        }

        $data = $request->validate([
            'order_id' => ['required', 'string'],
        ]);

        $payment = Payment::where('order_id', $data['order_id'])
            ->with('registration')
            ->firstOrFail();

        if (
            $request->user()
            && ! in_array($request->user()->role, ['administrator', 'operator'], true)
            && (int) $payment->user_id !== (int) $request->user()->id
        ) {
            abort(403, 'Anda tidak memiliki akses ke pembayaran ini.');
        }

        $payload = $midtrans->status($payment->order_id);
        $this->applyPaymentStatus($payment, $payload);

        $payment->refresh();
        $payment->load('registration');

        return [
            'message' => 'Status pembayaran disinkronkan.',
            'payment' => $payment,
            'registration' => $payment->registration,
        ];
    }

    private function applyPaymentStatus(Payment $payment, array $payload): void
    {
        $incoming = (string) ($payload['transaction_status'] ?? $payment->transaction_status);

        // Notifikasi Midtrans bisa datang terlambat atau tidak berurutan. Tanpa penjagaan ini,
        // notifikasi "expire" yang menyusul setelah pembayaran lunas akan mengunci peserta
        // yang sebenarnya sudah membayar.
        if ($this->isSettled($payment) && in_array($incoming, ['deny', 'cancel', 'expire', 'failure'], true)) {
            Log::warning('Notifikasi Midtrans diabaikan karena pembayaran sudah lunas.', [
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'status_tersimpan' => $payment->transaction_status,
                'status_masuk' => $incoming,
            ]);

            return;
        }

        DB::transaction(function () use ($payment, $payload) {
            $status = (string) ($payload['transaction_status'] ?? $payment->transaction_status);
            $fraud = $payload['fraud_status'] ?? null;

            $payment->update([
                'transaction_id' => $payload['transaction_id'] ?? $payment->transaction_id,
                'transaction_status' => $status,
                'payment_type' => $payload['payment_type'] ?? $payment->payment_type,
                'fraud_status' => $fraud,
                'status_code' => isset($payload['status_code']) ? (string) $payload['status_code'] : $payment->status_code,
                'status_message' => $payload['status_message'] ?? $payment->status_message,
                'bank' => $payload['bank'] ?? $payload['permata_va_number'] ?? $payment->bank,
                'va_number' => $payload['va_number'] ?? $payload['permata_va_number'] ?? $this->firstVaNumber($payload) ?? $payment->va_number,
                'va_numbers' => $payload['va_numbers'] ?? $payment->va_numbers,
                'transaction_time' => $payload['transaction_time'] ?? $payment->transaction_time,
                'settlement_time' => $payload['settlement_time'] ?? $payment->settlement_time,
                'payload' => $payload !== [] ? $payload : $payment->payload,
            ]);

            $registration = $payment->registration;
            if (! $registration) {
                return;
            }

            if ($status === 'settlement' || ($status === 'capture' && in_array($fraud, [null, 'accept'], true))) {
                $payment->update(['paid_at' => $payment->paid_at ?? now()]);

                // Patokannya akhir ujian, bukan awal. Settlement kartu kredit sering baru
                // tiba berjam-jam setelah peserta membayar; selama ujiannya masih berjalan
                // peserta tetap berhak masuk.
                $schedule = $registration->schedule()->first();
                if (
                    $schedule
                    && $schedule->end_at
                    && now()->gte($schedule->end_at)
                    && $registration->status !== 'approved'
                    && $registration->access_status !== 'unlocked'
                ) {
                    $registration->update([
                        'status' => 'expired',
                        'access_status' => 'locked',
                    ]);

                    return;
                }

                // Peserta yang sudah membayar tetap diloloskan meski paket soal belum siap.
                // Kalau ini sampai gagal, callback tidak boleh ikut gagal: pembayarannya sah.
                $packageId = $registration->question_package_id;
                if (! $packageId) {
                    try {
                        $packageId = $this->packageAssignments->pickRandomPackage($schedule)->id;
                    } catch (\Throwable $e) {
                        Log::error('Pembayaran lunas tetapi paket soal gagal diacak.', [
                            'order_id' => $payment->order_id,
                            'registration_id' => $registration->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                $registration->update([
                    'status' => 'approved',
                    'access_status' => 'unlocked',
                    'question_package_id' => $packageId,
                ]);

                return;
            }

            if (in_array($status, ['deny', 'cancel', 'expire', 'failure'], true)) {
                $registration->update([
                    'status' => $status === 'expire' ? 'expired' : 'cancelled',
                    'access_status' => 'locked',
                    'question_package_id' => null,
                ]);
            }
        });
    }

    private function isSettled(Payment $payment): bool
    {
        return $payment->paid_at !== null
            || in_array(strtolower((string) $payment->transaction_status), ['settlement', 'capture', 'paid'], true);
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
