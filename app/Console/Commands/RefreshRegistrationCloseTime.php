<?php

namespace App\Console\Commands;

use App\Models\Schedule;
use Illuminate\Console\Command;

class RefreshRegistrationCloseTime extends Command
{
    protected $signature = 'schedules:refresh-registration-close
                            {--dry-run : Tampilkan perubahan tanpa menyimpan}
                            {--include-past : Ikutkan jadwal yang sudah lewat}';

    protected $description = 'Selaraskan waktu tutup pendaftaran dengan REGISTRATION_CLOSE_OFFSET_MINUTES.';

    public function handle(): int
    {
        $offset = max(0, (int) config('app.registration_close_offset_minutes'));
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Offset aktif: {$offset} menit sebelum ujian dimulai.");

        $schedules = Schedule::query()
            ->when(! $this->option('include-past'), fn ($query) => $query->where('start_at', '>', now()))
            ->orderBy('start_at')
            ->get();

        $rows = [];
        foreach ($schedules as $schedule) {
            if (! $schedule->start_at) {
                continue;
            }

            $target = $schedule->start_at->copy()->subMinutes($offset);
            if ($schedule->registration_end_at && $schedule->registration_end_at->equalTo($target)) {
                continue;
            }

            $rows[] = [
                $schedule->id,
                $schedule->title,
                $schedule->start_at->format('d/m/Y H:i'),
                $schedule->registration_end_at?->format('d/m/Y H:i') ?? '-',
                $target->format('d/m/Y H:i'),
            ];

            if (! $dryRun) {
                $schedule->update(['registration_end_at' => $target]);
            }
        }

        if ($rows === []) {
            $this->info('Semua jadwal sudah selaras, tidak ada yang diubah.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Judul', 'Mulai ujian', 'Tutup (lama)', 'Tutup (baru)'], $rows);
        $this->info($dryRun
            ? count($rows).' jadwal akan diubah. Jalankan tanpa --dry-run untuk menyimpan.'
            : count($rows).' jadwal diperbarui.');

        return self::SUCCESS;
    }
}
