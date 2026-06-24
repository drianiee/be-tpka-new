# Backend TPA

Laravel API untuk sistem TPA internal tanpa Moodle. Backend ini menjadi exam engine sendiri: jadwal, paket soal, attempt, autosave jawaban, submit, scoring, dan data sertifikat membaca database internal.

## Instalasi

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Setup `.env`

Contoh konfigurasi MySQL/MariaDB lokal:

```env
APP_URL=http://127.0.0.1:8000
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=tpka
DB_USERNAME=andrian
DB_PASSWORD=Nazwa2011

MIDTRANS_MERCHANT_ID=
MIDTRANS_CLIENT_KEY=
MIDTRANS_SERVER_KEY=
MIDTRANS_IS_PRODUCTION=false
MIDTRANS_IS_SANITIZED=true
MIDTRANS_IS_3DS=true

FRONTEND_URL=http://localhost:3000
BACKEND_URL=http://127.0.0.1:8000

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="TPKA ITS"
```

Pastikan database `tpka` sudah ada di MySQL/MariaDB. Untuk menjalankan MariaDB via Homebrew:

```bash
brew services start mariadb
```

### Gmail SMTP

Pengiriman email memakai Gmail SMTP. Isi nilai `MAIL_USERNAME`, `MAIL_PASSWORD`, dan `MAIL_FROM_ADDRESS` di `.env` lokal. `MAIL_PASSWORD` harus memakai Gmail App Password, bukan password Gmail biasa.

Setelah mengubah konfigurasi mail:

```bash
php artisan config:clear
php artisan cache:clear
```

Jika nanti queue dipakai untuk email:

```bash
php artisan queue:restart
```

## Migration dan Seeder

```bash
php artisan migrate:fresh --seed
```

Seeder hanya membuat user awal:

- admin
- operator
- peserta

## Menjalankan Server

```bash
php artisan serve
```

Default API berjalan di:

```text
http://127.0.0.1:8000
```

## Akun Demo

Password seluruh akun: `password`.

| Email | Role |
| --- | --- |
| `admin@tpka.com` | `administrator` |
| `operator@tpka.com` | `operator` |
| `peserta@tpka.com` | `peserta` |

## Endpoint Penting

Auth:

- `POST /api/auth/login`
- `POST /api/auth/register`
- `GET|POST /api/auth/email/verify`
- `POST /api/auth/email/resend`
- `GET /api/me`
- `POST /api/logout`

Dashboard:

- `GET /api/dashboard`
- `GET /api/dashboard/revenue-chart`
- `GET /api/dashboard/participants-chart`

Schedule dan paket soal:

- `GET /api/schedules`
- `GET /api/schedules/{id}`
- `GET /api/question-packages`
- `GET /api/question-packages/{id}`

Peserta:

- `GET /api/participant/schedules`
- `POST /api/participant/schedules/{scheduleId}/register`
- `GET /api/participant/my-schedules`
- `GET /api/participant/my-schedules/{scheduleId}`
- `GET /api/participant/scores`

Partner onboarding:

- `GET /api/partner/onboarding/preview?token=...`
- `POST /api/partner/onboarding/claim`
- `POST /api/schedules/{scheduleId}/partner/import`

Exam engine internal:

- `POST /api/participant/schedules/{scheduleId}/attempts`
- `GET /api/participant/attempts/{attemptId}`
- `PUT /api/participant/attempts/{attemptId}/answers/{questionId}`
- `POST /api/participant/attempts/{attemptId}/answers/batch`
- `POST /api/participant/attempts/{attemptId}/heartbeat`
- `POST /api/participant/attempts/{attemptId}/submit`
- `GET /api/participant/attempts/{attemptId}/result`

Payment dan sertifikat:

- `GET /api/payments`
- `GET /api/payments/{id}`
- `POST /api/payments/midtrans/callback`
- `GET /api/certificates/data/{uuid}`
- `GET /api/certificates/pdf/{uuid}`

## Midtrans Sandbox

Key Midtrans wajib disimpan di `.env`, bukan di source code. `.env.example` hanya memakai placeholder kosong.

Flow pembayaran:

- Peserta umum daftar sendiri ke jadwal berbayar lewat `POST /api/participant/schedules/{scheduleId}/register`.
- Backend membuat `exam_registration` status `pending_payment`, `payment` status `pending`, lalu membuat transaksi Snap.
- Frontend memakai `midtrans.redirect_url` atau `payment.snap_redirect_url` untuk lanjut pembayaran.
- Midtrans mengirim callback ke `POST /api/payments/midtrans/callback`.
- Callback memvalidasi signature `sha512(order_id + status_code + gross_amount + MIDTRANS_SERVER_KEY)`.
- Status `settlement` atau `capture` membuka akses ujian dengan `access_status = unlocked`.
- Jadwal gratis langsung `approved/unlocked` tanpa payment.
- Jadwal mitra/admin-import langsung `approved/unlocked` tanpa Midtrans.

Untuk development lokal, jalankan backend:

```bash
php artisan serve
```

Lalu expose backend dengan ngrok:

```bash
ngrok http 8000
```

Set Payment Notification URL di Midtrans Dashboard ke URL ngrok backend:

```text
https://URL-NGROK/api/payments/midtrans/callback
```

Set redirect URL Midtrans ke frontend:

```text
Finish:   http://localhost:3000/payment/finish
Unfinish: http://localhost:3000/payment/finish
Error:    http://localhost:3000/payment/finish
```

## Flow Testing Manual

1. Login peserta:

```bash
curl -s -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"peserta@tpka.com","password":"password"}'
```

2. Simpan token dari response, lalu cek user:

```bash
curl -s http://127.0.0.1:8000/api/me \
  -H "Authorization: Bearer TOKEN"
```

3. Lihat jadwal peserta:

```bash
curl -s http://127.0.0.1:8000/api/participant/my-schedules \
  -H "Authorization: Bearer TOKEN"
```

4. Start attempt:

```bash
curl -s -X POST http://127.0.0.1:8000/api/participant/schedules/1/attempts \
  -H "Authorization: Bearer TOKEN"
```

5. Simpan jawaban:

```bash
curl -s -X PUT http://127.0.0.1:8000/api/participant/attempts/1/answers/1 \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"question_option_id":1}'
```

6. Submit attempt:

```bash
curl -s -X POST http://127.0.0.1:8000/api/participant/attempts/1/submit \
  -H "Authorization: Bearer TOKEN"
```

7. Lihat result:

```bash
curl -s http://127.0.0.1:8000/api/participant/attempts/1/result \
  -H "Authorization: Bearer TOKEN"
```

### Testing Payment Manual

1. Login peserta di frontend.
2. Buka menu jadwal peserta.
3. Pilih jadwal umum berbayar, lalu klik daftar.
4. Pastikan frontend diarahkan ke Snap Sandbox.
5. Selesaikan pembayaran dengan data kartu sandbox Midtrans.
6. Pastikan callback masuk ke backend lewat ngrok.
7. Cek `GET /api/participant/my-schedules`; status payment harus berubah dan `registration.access_status` menjadi `unlocked`.
8. Klik mulai tes; backend hanya membuat attempt jika registration sudah unlocked.
9. Untuk jadwal gratis, daftar harus langsung unlocked tanpa record payment.
10. Untuk jadwal mitra/admin-import, tambahkan peserta lewat admin/operator; tidak boleh membuat transaksi Midtrans.

## Catatan Implementasi

- Response attempt peserta tidak mengirim `is_correct`.
- Tidak ada Moodle API client, Moodle user ID, Moodle course ID, Moodle quiz ID, Moodle group, `quiz_url`, atau provisioning Moodle.
- Scoring dihitung dari `questions` dan `question_options` internal, lalu disimpan ke `exam_scores`.
- Data certificate berasal dari attempt submitted/expired dan `exam_scores`.
- Email verification peserta mandiri memakai token hash, single-use, berlaku 2 jam.
- Invitation peserta mitra memakai token hash, single-use untuk onboarding, berlaku 2 hari.
- Jadwal mitra tidak tampil di public upcoming dan daftar jadwal umum peserta, serta tidak melalui Midtrans.

## Paket Soal dan Bank Soal

Saat admin/operator membuat paket soal melalui `POST /api/question-packages`, backend otomatis membuat 4 section:

| Code | Nama | Urutan |
| --- | --- | --- |
| `verbal` | Verbal | 1 |
| `kuantitatif` | Kuantitatif | 2 |
| `logika` | Logika | 3 |
| `spasial` | Spasial | 4 |

Endpoint utama:

- `GET /api/question-packages/{id}/sections`
- `POST /api/question-packages/{id}/sections`
- `PUT /api/sections/{sectionId}`
- `DELETE /api/sections/{sectionId}`
- `GET /api/question-packages/{id}/questions`
- `POST /api/question-packages/{id}/questions`
- `GET /api/questions/{questionId}`
- `PUT /api/questions/{questionId}`
- `DELETE /api/questions/{questionId}`
- `GET /api/question-packages/{id}/questions/import-template`
- `POST /api/question-packages/{id}/questions/import`
- `POST /api/admin/question-images`

Format CSV import:

```csv
section_code,order_number,question_text,question_image,option_a_text,option_a_image,option_b_text,option_b_image,option_c_text,option_c_image,option_d_text,option_d_image,correct_option,score,difficulty,explanation
verbal,1,"Contoh soal verbal",,"Pilihan A",,"Pilihan B",,"Pilihan C",,"Pilihan D",,A,1,mudah,"Pembahasan opsional"
```

Aturan import:

- `section_code` wajib salah satu: `verbal`, `kuantitatif`, `logika`, `spasial`.
- `correct_option` wajib `A`, `B`, `C`, atau `D`.
- Soal wajib memiliki `question_text` atau `question_image`.
- Setiap opsi A-D wajib memiliki teks atau gambar.
- Import CSV bersifat all-or-nothing. Jika ada row error, tidak ada row yang disimpan.
- Gambar tahap awal memakai URL/path di kolom `question_image` dan `option_*_image`.

Upload gambar manual:

```bash
php artisan storage:link
```

```bash
curl -X POST http://127.0.0.1:8000/api/admin/question-images \
  -H "Authorization: Bearer TOKEN_ADMIN" \
  -F "image=@/path/to/question.png"
```

Response:

```json
{
  "url": "http://127.0.0.1:8000/storage/question-images/filename.png",
  "path": "question-images/filename.png"
}
```

## TODO

- Integrasi Midtrans nyata dan verifikasi signature callback.
- Generator PDF sertifikat.
- Export XLSX peserta/nilai.
- Import peserta mitra dari file.
- Import ZIP berisi CSV + folder images.
