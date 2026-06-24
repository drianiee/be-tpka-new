<!doctype html>
<html lang="id">
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.6; margin: 0; padding: 24px; background: #f8fafc;">
  <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px;">
    <h1 style="font-size: 20px; margin: 0 0 16px;">Verifikasi Email Akun TPKA</h1>
    <p>Halo {{ $user->name }},</p>
    <p>Terima kasih sudah mendaftar akun TPKA. Silakan verifikasi email Anda agar akun dapat digunakan.</p>
    <p style="margin: 24px 0;">
      <a href="{{ $verificationUrl }}" style="display: inline-block; background: #0f4c97; color: #ffffff; text-decoration: none; padding: 12px 18px; border-radius: 10px; font-weight: 700;">
        Verifikasi Email
      </a>
    </p>
    <p>Link ini berlaku selama 2 jam. Setelah verifikasi berhasil, Anda perlu melengkapi profil dan identitas/KTP sebelum mendaftar jadwal ujian.</p>
    <p>Jika tombol tidak bisa dibuka, salin link berikut ke browser:</p>
    <p style="word-break: break-all; color: #475569;">{{ $verificationUrl }}</p>
    <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <p style="font-size: 12px; color: #64748b;">TPKA ITS</p>
  </div>
</body>
</html>
  