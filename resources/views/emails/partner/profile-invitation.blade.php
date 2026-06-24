<!doctype html>
<html lang="id">
<body style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.6; margin: 0; padding: 24px; background: #f8fafc;">
  <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 24px;">
    <h1 style="font-size: 20px; margin: 0 0 16px;">Konfirmasi Peserta Mitra TPKA</h1>
    <p>Halo {{ $name }},</p>
    <p>Email Anda didaftarkan sebagai peserta ujian TPA melalui mitra/admin.</p>
    <div style="background: #f1f5f9; border-radius: 12px; padding: 14px; margin: 16px 0;">
      <strong>{{ $schedule->title }}</strong><br>
      Tanggal: {{ $schedule->start_at?->format('d/m/Y') }}<br>
      Jam: {{ $schedule->start_at?->format('H:i') }} - {{ $schedule->end_at?->format('H:i') }} WIB
    </div>
    <p>Anda tidak perlu melakukan pembayaran. Silakan konfirmasi dan lengkapi profil/identitas sebelum mengikuti ujian.</p>
    <p style="margin: 24px 0;">
      <a href="{{ $onboardingUrl }}" style="display: inline-block; background: #0f4c97; color: #ffffff; text-decoration: none; padding: 12px 18px; border-radius: 10px; font-weight: 700;">
        Konfirmasi dan Lengkapi Profil
      </a>
    </p>
    <p>Link ini berlaku selama 2 hari.</p>
    <p style="word-break: break-all; color: #475569;">{{ $onboardingUrl }}</p>
    <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <p style="font-size: 12px; color: #64748b;">TPKA ITS</p>
  </div>
</body>
</html>
