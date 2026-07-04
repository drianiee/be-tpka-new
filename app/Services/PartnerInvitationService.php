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
    public function __construct(
        private QuestionPackageAssignmentService $packageAssignments,
        private CertificateAssignmentService $certificates,
    ) {}

    public function registerManualParticipant(Schedule $schedule, User $actor, array $payload): array
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;

        throw_if(
            $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL),
            ValidationException::withMessages(['email' => 'Email peserta tidak valid.'])
        );

        $user = $userId ? User::whereKey($userId)->where('role', 'peserta')->first() : null;
        if ($user && strtolower((string) $user->email) !== $email) {
            throw ValidationException::withMessages(['email' => 'Email tidak cocok dengan peserta yang dipilih.']);
        }

        if (! $user) {
            $user = User::where('email', $email)->where('role', 'peserta')->first();
        }

        $createdUser = false;
        if (! $user) {
            throw_if(
                $name === '',
                ValidationException::withMessages(['name' => 'Nama peserta wajib diisi untuk email baru.'])
            );

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(48)),
                'role' => 'peserta',
                'is_active' => true,
            ]);
            $createdUser = true;
        }

        $registrationSource = (string) ($payload['registration_source'] ?? ($schedule->partner_id || $schedule->is_partner ? 'partner' : 'admin'));

        $registration = ExamRegistration::firstOrNew([
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
        ]);
        $registration->fill([
            'payment_id' => null,
            'certificate_id' => $registration->certificate_id ?: $schedule->certificate_id,
            'status' => 'approved',
            'access_status' => 'unlocked',
            'registration_source' => $registrationSource,
            'registered_at' => now(),
        ]);
        if (! $registration->question_package_id) {
            $registration->question_package_id = $this->packageAssignments->pickRandomPackage($schedule)->id;
        }
        $registration->save();
        $registration = $this->certificates->assignToRegistration($registration);

        $sentInvitation = false;
        $sentConfirmation = false;
        if (! $user->profile_completed_at) {
            $token = $this->createInvitation($schedule, $user, $actor, [
                'name' => $name !== '' ? $name : $user->name,
                'email' => $email,
                'phone' => $payload['phone'] ?? null,
                'identity_number' => $payload['identity_number'] ?? null,
            ]);
            Mail::to($email)->send(new PartnerProfileInvitationMail($user->name, $schedule, $this->onboardingUrl($token)));
            $sentInvitation = true;
        } else {
            Mail::to($email)->send(new PartnerScheduleConfirmationMail($user, $schedule, $this->scheduleUrl($schedule)));
            $sentConfirmation = true;
        }

        return [
            'user' => $user->fresh(),
            'registration' => $registration->fresh(),
            'created_user' => $createdUser,
            'sent_invitation' => $sentInvitation,
            'sent_confirmation' => $sentConfirmation,
        ];
    }

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

                $result = $this->registerManualParticipant($schedule, $actor, [
                    'email' => $email,
                    'name' => $name,
                    'phone' => $row['phone'] ?? null,
                    'identity_number' => $row['identity_number'] ?? null,
                    'registration_source' => 'import',
                ]);
                $summary['attached_to_schedule']++;
                if ($result['created_user']) {
                    $summary['created_users']++;
                }
                if ($result['sent_invitation']) {
                    $summary['sent_invitations']++;
                }
                if ($result['sent_confirmation']) {
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
            'expires_at' => now()->addDay(),
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
