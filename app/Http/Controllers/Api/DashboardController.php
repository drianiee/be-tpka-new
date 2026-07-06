<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamRegistration;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        return [
            'totals' => [
                'participants' => User::where('role', 'peserta')->count(),
                'income_paid' => (float) Payment::whereIn('transaction_status', ['paid', 'settlement', 'capture'])->sum('amount'),
                'paid_count' => Payment::whereIn('transaction_status', ['paid', 'settlement', 'capture'])->count(),
                'total_schedules' => Schedule::count(),
            ],
            'latest_schedules' => Schedule::latest()
                ->limit(10)
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'date' => $s->start_at->format('Y-m-d H:i:s'),
                    'start_time' => $s->start_at->format('H:i:s'),
                    'end_time' => $s->end_at->format('H:i:s'),
                    'price' => (string) $s->price,
                    'is_partner' => (bool) $s->is_partner,
                    'partner_id' => $s->partner_id,
                    'capacity' => $s->quota,
                    'current_participants' => $s->registrations()->count(),
                    'status' => $s->status,
                ]),
        ];
    }

    public function revenue()
    {
        $year = (int) request('year', date('Y'));

        $paidStatuses = ['paid', 'settlement', 'capture'];
        $rows = Payment::query()
            ->selectRaw('MONTH(COALESCE(paid_at, settlement_time, transaction_time, created_at)) as month')
            ->selectRaw('SUM(amount) as amount')
            ->selectRaw('COUNT(*) as count')
            ->whereIn('transaction_status', $paidStatuses)
            ->whereYear(DB::raw('COALESCE(paid_at, settlement_time, transaction_time, created_at)'), $year)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy(fn ($row) => (int) $row->month);

        $series = collect(range(1, 12))->map(function ($month) use ($rows) {
            $row = $rows->get($month);

            return [
                'month' => $month,
                'amount' => (float) ($row->amount ?? 0),
                'count' => (int) ($row->count ?? 0),
            ];
        })->values();

        return [
            'year' => $year,
            'currency' => 'IDR',
            'series' => $series,
            'total_amount' => (float) $series->sum('amount'),
            'total_count' => (int) $series->sum('count'),
        ];
    }

    public function participants()
    {
        $year = (int) request('year', date('Y'));

        $rows = ExamRegistration::query()
            ->selectRaw('MONTH(COALESCE(registered_at, created_at)) as month')
            ->selectRaw('COUNT(*) as participants')
            ->whereYear(DB::raw('COALESCE(registered_at, created_at)'), $year)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy(fn ($row) => (int) $row->month);

        $series = collect(range(1, 12))->map(function ($month) use ($rows) {
            $row = $rows->get($month);

            return [
                'month' => $month,
                'participants' => (int) ($row->participants ?? 0),
            ];
        })->values();

        return [
            'year' => $year,
            'series' => $series,
            'total_participants' => (int) $series->sum('participants'),
            'source' => 'internal',
        ];
    }
}
