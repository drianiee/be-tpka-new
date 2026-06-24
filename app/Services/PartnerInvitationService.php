<?php

namespace App\Services;

use App\Mail\PartnerProfileInvitationMail;
use App\Mail\PartnerScheduleConfirmationMail;
use App\Models\ExamRegistration;
use App\Models\PartnerInvitation;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class PartnerInvitationService
{
    public function import(Schedule $schedule, UploadedFile $file, User $actor): array
    {
        abort_unless($schedule->is_partner || $schedule->partner_id, 422, 'Jadwal ini bukan jadwal mitra.');
        [$rows, $errors] = $this->readRows($file);

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => collect($errors)->pluck('message')->all()]);
        }

        $summary = ['created_users' => 0, 'attached_to_schedule' => 0, 'sent_invitations' => 0, 'sent_confirmations' => 0, 'skipped_rows' => 0];

        DB::transaction(function () use ($rows, $schedule, $actor, &$summary) {
            foreach ($rows as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $name = trim((string) ($row['name'] ?? ''));
                if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
                    $summary['skipped_rows']++;

                    continue;
                }

                $user = User::where('email', $email)->first();
                if (! $user) {
                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'password' => Hash::make(Str::random(48)),
                        'role' => 'peserta',
                        'is_active' => true,
                        'phone' => filled($row['phone'] ?? null) ? $row['phone'] : null,
                        'identity_number' => filled($row['identity_number'] ?? null) ? $row['identity_number'] : null,
                    ]);
                    $summary['created_users']++;
                }

                ExamRegistration::updateOrCreate(
                    ['user_id' => $user->id, 'schedule_id' => $schedule->id],
                    ['payment_id' => null, 'status' => 'approved', 'access_status' => 'unlocked', 'registration_source' => 'import', 'registered_at' => now()],
                );
                $summary['attached_to_schedule']++;

                if (! $user->profile_completed_at) {
                    $token = $this->createInvitation($schedule, $user, $actor, $row);
                    Mail::to($email)->send(new PartnerProfileInvitationMail($user->name, $schedule, $this->onboardingUrl($token)));
                    $summary['sent_invitations']++;
                } else {
                    Mail::to($email)->send(new PartnerScheduleConfirmationMail($user, $schedule, $this->scheduleUrl($schedule)));
                    $summary['sent_confirmations']++;
                }
            }
        });

        return $summary;
    }

    public function createInvitation(Schedule $schedule, User $user, User $actor, array $row = []): string
    {
        PartnerInvitation::where('schedule_id', $schedule->id)->where('email', $user->email)->whereNull('claimed_at')->update(['claimed_at' => now()]);

        $token = Str::random(64);
        PartnerInvitation::create([
            'schedule_id' => $schedule->id,
            'partner_id' => $schedule->partner_id,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $row['phone'] ?? $user->phone,
            'identity_number' => $row['identity_number'] ?? $user->identity_number,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(2),
            'created_by' => $actor->id,
        ]);

        return $token;
    }

    public function findValidByToken(string $token): PartnerInvitation
    {
        $invitation = PartnerInvitation::where('token_hash', hash('sha256', $token))->with(['schedule.partner', 'user'])->first();
        abort_if(! $invitation, 422, 'Undangan onboarding tidak valid.');
        abort_if(now()->gt($invitation->expires_at), 422, 'Undangan onboarding sudah kedaluwarsa. Silakan hubungi admin.');

        return $invitation;
    }

    private function readRows(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return $ext === 'xlsx' ? $this->readXlsx($file) : $this->readCsv($file);
    }

    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return [[], [['message' => 'File tidak dapat dibaca.']]];
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return [[], [['message' => 'Header file kosong.']]];
        }
        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
        $missing = array_values(array_diff(['name', 'email'], $header));
        if ($missing !== []) {
            fclose($handle);

            return [[], [['message' => 'Kolom wajib belum ada: '.implode(', ', $missing)]]];
        }

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, array_pad($values, count($header), ''));
        }
        fclose($handle);

        return [$rows, []];
    }

    private function readXlsx(UploadedFile $file): array
    {
        if (! class_exists(ZipArchive::class)) {
            return [[], [['message' => 'Server belum mendukung pembacaan XLSX. Gunakan CSV.']]];
        }

        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return [[], [['message' => 'File XLSX tidak dapat dibuka.']]];
        }

        $shared = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (! $sheetXml) {
            return [[], [['message' => 'Sheet pertama XLSX tidak ditemukan.']]];
        }

        $xml = simplexml_load_string($sheetXml);
        $grid = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $value = (string) $cell->v;
                $cells[] = ((string) $cell['t']) === 's' ? ($shared[(int) $value] ?? '') : $value;
            }
            $grid[] = $cells;
        }
        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $grid[0] ?? []);
        $missing = array_values(array_diff(['name', 'email'], $header));
        if ($missing !== []) {
            return [[], [['message' => 'Kolom wajib belum ada: '.implode(', ', $missing)]]];
        }

        $rows = [];
        foreach (array_slice($grid, 1) as $values) {
            $rows[] = array_combine($header, array_pad($values, count($header), ''));
        }

        return [$rows, []];
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! $xml) {
            return [];
        }

        $data = simplexml_load_string($xml);
        $strings = [];
        foreach ($data->si as $item) {
            $strings[] = (string) ($item->t ?? $item->r->t ?? '');
        }

        return $strings;
    }

    private function onboardingUrl(string $token): string
    {
        return rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/partner/onboarding?token='.$token;
    }

    private function scheduleUrl(Schedule $schedule): string
    {
        return rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/dashboard/peserta/my-schedules/'.$schedule->id;
    }
}
