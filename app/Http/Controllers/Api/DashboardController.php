<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        return ['totals' => ['participants' => User::where('role', 'peserta')->count(), 'income_paid' => (float) Payment::whereIn('transaction_status', ['paid', 'settlement', 'capture'])->sum('amount'), 'paid_count' => Payment::whereIn('transaction_status', ['paid', 'settlement', 'capture'])->count(), 'total_schedules' => Schedule::count()], 'latest_schedules' => Schedule::latest()->limit(10)->get()->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'date' => $s->start_at->format('Y-m-d H:i:s'), 'start_time' => $s->start_at->format('H:i:s'), 'end_time' => $s->end_at->format('H:i:s'), 'price' => (string) $s->price, 'is_partner' => false, 'capacity' => $s->quota, 'current_participants' => $s->registrations()->count(), 'status' => $s->status])];
    }

    public function revenue()
    {
        return ['year' => (int) request('year', date('Y')), 'currency' => 'IDR', 'series' => collect(range(1, 12))->map(fn ($m) => ['month' => $m, 'amount' => 0, 'count' => 0]), 'total_amount' => 0, 'total_count' => 0];
    }

    public function participants()
    {
        return ['year' => (int) request('year', date('Y')), 'series' => collect(range(1, 12))->map(fn ($m) => ['month' => $m, 'participants' => 0]), 'total_participants' => User::where('role', 'peserta')->count(), 'source' => 'internal'];
    }
}
