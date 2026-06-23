# Backend TPA

Laravel API untuk exam engine internal TPA.

## Menjalankan

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Konfigurasi database melalui `.env`. SQLite bawaan dapat digunakan untuk development; migration juga kompatibel dengan MySQL/MariaDB dan PostgreSQL.

## Akun Demo

Password seluruh akun: `password`.

- `admin@example.com` (`administrator`)
- `operator@example.com` (`operator`)
- `peserta@example.com` (`peserta`)

## Batas Tahap Awal

- Exam engine, autosave, heartbeat, submit idempoten, dan scoring internal sudah aktif.
- Integrasi Midtrans mengembalikan `503` sampai kredensial dan verifikasi signature dikonfigurasi.
- Generator PDF/verifikasi sertifikat, import peserta mitra, dan export XLSX masih endpoint terkontrol dengan pesan yang jelas.
- CRUD section dan bank soal tersedia melalui API admin; editor visual frontend dapat dilanjutkan pada iterasi berikutnya.
