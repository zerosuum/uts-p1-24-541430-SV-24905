# Tugas 6 / P10 — MulaiDulu: logging, health, healthcheck, log rotation

Panduan ini berbasis ZIP `uts-p1-24-541430-SV-24905-main.zip`, **bukan** berdasarkan asumsi pada proyek lain. Tidak ada file `.env` atau password di dalam patch. **Jangan `docker compose down -v`**, karena akan menghapus volume PostgreSQL.

## Hasil yang diimplementasikan

| Nomor soal | Lokasi file | Penerapan |
|---|---|---|
| 1 | `backend/config/logging.php`, `docker-compose.yml` | Laravel Monolog `stdout` JSON (`php://stdout`) + `LOG_CHANNEL=stdout`; dapat diperiksa melalui `docker compose logs backend` |
| 2 | `backend/app/Http/Controllers/Api/TaskController.php`, `backend/app/Support/SafeLogContext.php`, `backend/app/Http/Controllers/HealthController.php` | `INFO task.created`, `WARNING task.validation_failed`, `ERROR health.dependency_failed` (+ `ERROR task.database_write_failed` jika operasi create gagal); actor pseudonim HMAC, action, reason, tanpa data request, email, password, atau token |
| 3 | `backend/app/Http/Controllers/HealthController.php`, `backend/bootstrap/app.php`, `frontend/nginx.conf` | Public `GET /health`, memeriksa `SELECT 1` dan round-trip cache, JSON 200/503 dan alasan gagal; lewat port 8088 Nginx |
| 4–5 | `docker-compose.yml`, `backend/healthcheck.php` | Backend healthcheck `interval: 10s`, `timeout: 5s`, `retries: 3`, `start_period: 20s`; `stop db` → backend `(unhealthy)` → `start db` → `(healthy)` |
| 6 | `docker-compose.yml` | `logging: json-file`, `max-size: "5m"`, `max-file: "3"` pada semua 3 service |
| PDF | `scripts/p10_capture_evidence.sh`, `scripts/p10_make_report.py` | Mengumpulkan output Docker asli serta membangun PDF setelah semua kondisi benar-benar terbukti; gagal jika bukti tidak lengkap |

**Khusus dependensi cache:** repo UTS menggunakan `CACHE_STORE=database`, sehingga ketika PostgreSQL mati, cache juga biasanya gagal. Endpoint tetap menguji keduanya secara terpisah, bukan sekadar membaca satu status dan mengasumsikan status yang lain.

## A. Dari membuka terminal, sampai aplikasi ready

1. Buka **Docker Desktop** di macOS. Bisa lewat Spotlight, atau dari terminal:

   ```bash
   open -a Docker
   ```

2. Di **VS Code** pilih Terminal → New Terminal (cukup **dua terminal** jika ingin memantau log di terminal kedua). Masuk ke folder proyek yang **sudah dipakai pada UTS**, bukan folder ZIP yang baru diekstrak:

   ```bash
   cd /Users/dellanurizki/Documents/uts-p1-24-541430-SV-24905
   pwd
   git status --short
   ```

3. Pastikan file `.env` lama ada, jangan upload file ini ke GitHub. Jika belum ada `.env`, buat dari `.env.example` dan isi rahasia lokal sendiri; jangan ganti `DB_PASSWORD` secara sembarangan apabila database Docker lama masih memakai volume yang sama:

   ```bash
   test -f .env && echo '.env lokal ada' || echo 'PERLU siapkan .env'
   ```

4. **Buat branch tanpa menghilangkan perubahan UTS.** Jika `git status --short` menunjukkan file yang dimodifikasi, simpan dulu perubahan tersebut:

   ```bash
   git stash push -u -m 'backup lokal sebelum patch P10'
   git switch main
   git pull --ff-only origin main
   git switch -c feat/p10-observability
   ```

   Jika `git status --short` kosong, lewati baris `git stash push` (tidak wajib). Perintah `stash` tidak menyertakan `.env` karena `.env` di-ignore. **Jangan gunakan `git reset --hard`.**

5. Unduh patch file dari ChatGPT lalu letakkan, misalnya, ke folder **Downloads**. Patch dibuat menggunakan isi ZIP `main` yang dilampirkan. Jalankan:

   ```bash
   git apply --check ~/Downloads/P10_MulaiDulu_Tugas6.patch
   git apply ~/Downloads/P10_MulaiDulu_Tugas6.patch
   git diff --check
   ```

   Bila `git apply --check` gagal: **JANGAN memaksa patch**, karena branch lokal mungkin punya perubahan lain. Kirim output error; alternatif aman adalah menerapkan patch ke `main` yang cocok dengan ZIP. Jangan overwrite `.env` atau seluruh folder proyek.

6. Pastikan Docker Desktop aktif, lalu build dan jalankan aplikasi versi P10:

   ```bash
   docker info >/dev/null && echo 'Docker ready'
   docker compose config --quiet
   docker compose up -d --build
   docker compose ps --all
   ```

   Target: `db` dan `backend` berstatus `(healthy)`, `frontend` berstatus `Up`.

7. Buka UI dan health endpoint melalui browser:

   ```bash
   open http://localhost:8088
   open http://localhost:8088/health
   ```

   Di terminal:

   ```bash
   curl -i http://localhost:8088/health
   docker compose logs --tail=40 backend
   ```

   Contoh **bentuk** respons (bukan klaim hasil lokal):

   ```json
   {"status":"healthy","checks":{"database":{"status":"healthy"},"cache":{"status":"healthy"}}}
   ```

## B. Menghasilkan bukti seluruh rubrik (otomatis dan aman)

**Syarat:** demo account (`DEMO_EMAIL`, `DEMO_PASSWORD` pada `.env` lokal) sudah dapat login. Karena tidak ada fitur register di UTS, jika demo account lama lupa password, setelah environment disinkronkan jalankan:

```bash
docker compose exec backend php artisan db:seed --class=DemoUserSeeder --force
```

Seeder yang sudah ada memakai `updateOrCreate`, sehingga tidak menghapus task maupun database. Jika password di `.env` baru diedit, jalankan `docker compose up -d --no-deps --force-recreate backend` lebih dulu agar env backend diperbarui. **Jangan menampilkan isi `.env` saat screen recording.**

Setelah healthy:

```bash
bash scripts/p10_capture_evidence.sh
```

Script tersebut menjalankan *alur nyata*, bukan membuat dummy output:

1. Menunggu backend `healthy`, menyimpan `docker compose ps --all`, GET `/health` (HTTP 200).
2. Login memakai akun demo tanpa mencetak password/token, membuat tugas sementara (INFO), dan mencoba data tidak valid (WARNING). Tugas sementara dihapus.
3. Menyimpan log config container dari `docker inspect`.
4. Menjalankan `docker compose stop db`, menunggu backend `unhealthy`, menyimpan output `ps`, `/health` HTTP 503, dan log error.
5. Menyalakan ulang db dengan `docker compose start db`, menunggu `healthy`, lalu menyimpan bukti pemulihan HTTP 200.
6. Menyimpan potongan log JSON terstruktur di `evidence-p10/structured-logs.txt`.

**Perhatian:** aplikasi sengaja kehilangan akses database sebentar saat tes (bukan menghapus data). Jalankan di luar penggunaan aktif; script memasang trap untuk menyalakan database kembali jika gagal. Bila password akun tidak cocok, script berhenti **sebelum** mematikan database.

Cek bukti terminal:

```bash
cat evidence-p10/healthy-ps.txt
cat evidence-p10/healthy-health.json
cat evidence-p10/unhealthy-ps.txt
cat evidence-p10/unhealthy-health.json
cat evidence-p10/restored-ps.txt
cat evidence-p10/restored-health.json
cat evidence-p10/structured-logs.txt
cat evidence-p10/log-rotation.json
```

### Opsional: tangkap layar terminal

Buka masing-masing file di terminal, ambil gambar menggunakan macOS `Command + Shift + 4`, dan simpan pribadi. Rubrik meminta **output** `docker compose ps` dan `/health`; PDF generator di bawah memasukkan output asli dalam bentuk teks yang bisa diperiksa, sehingga gambar tambahan tidak wajib.

## C. Buat laporan PDF bernama persis P10_NIM_Nama.pdf

Laporan **tidak boleh dibuat dari hasil palsu**. Generator mengecek bukti sehat → sakit → pulih sebelum menulis PDF. Dibutuhkan Python 3 dan reportlab.

```bash
python3 -m venv .venv-p10
.venv-p10/bin/python -m pip install --upgrade pip
.venv-p10/bin/python -m pip install reportlab
.venv-p10/bin/python scripts/p10_make_report.py
open P10_24-541430-SV-24905_Della-Nurizki.pdf
```

Output akhir: `P10_24-541430-SV-24905_Della-Nurizki.pdf`, berisi tiga potongan log berlevel, output Compose sehat/unhealthy/pulih, JSON `/health` saat kedua keadaan, alasan per log, dan konfigurasi rotasi log. Nama file menggunakan tanda `-` karena NIM asli mengandung slash yang tidak bisa dipakai dalam filename.

## D. Uji backend tanpa mengganggu data produksi

Jalankan test dari **backend lokal** (image Docker backend memakai `composer install --no-dev`, jadi tidak memiliki PHPUnit):

```bash
cd backend
php artisan test
cd ..
```

Kalau `vendor` belum ada di Mac: `cd backend && composer install && php artisan test && cd ..` (Composer harus terpasang). Tes tambahan `HealthEndpointTest.php` memeriksa 200/503 untuk database dan cache; `TaskLoggingTest.php` memeriksa INFO/WARNING dan bahwa data input tidak dicatat.

## E. Commit dan kirim

**Jangan commit file `.env`, `evidence-p10/`, `.venv-p10/`, atau PDF jika tidak diminta.**

```bash
git status --short
git diff --check
git add .gitignore .env.example docker-compose.yml \
  backend/bootstrap/app.php backend/config/logging.php \
  backend/app/Http/Controllers/Api/TaskController.php \
  backend/app/Http/Controllers/HealthController.php \
  backend/app/Support/SafeLogContext.php backend/healthcheck.php \
  backend/tests/Feature/HealthEndpointTest.php \
  backend/tests/Feature/TaskLoggingTest.php \
  frontend/nginx.conf scripts/ PANDUAN_P10.md

git diff --cached --stat
git commit -m 'feat: add structured stdout logs and dependency healthchecks'
git push -u origin feat/p10-observability
```

Buat Pull Request `feat/p10-observability → dev`. Setelah CI lulus, merge menurut aturan repo; kalau perlu, lanjut PR `dev → main`. Kumpulkan tautan repo dan PDF melalui platform yang ditentukan dosen.

## F. FAQ ketika ditanya asprak/dosen

- **Mengapa stdout?** Dalam container, Docker menangkap stdout/stderr. Log tidak mengandalkan file `storage/logs/laravel.log` di image yang ephemeral.
- **Mengapa JSON?** Memiliki field terstruktur: `level_name`, `message`, `context.actor_type`, `actor_ref`, `action`, `reason`, `component`; mudah difilter dan dibaca alat observability.
- **Siapa aktornya?** `authenticated_user` dengan `actor_ref` HMAC untuk log task, `system_healthcheck` untuk readiness probe. ID/email asli tidak dicatat.
- **Mengapa INFO/WARNING/ERROR?** INFO untuk kejadian normal sukses, WARNING untuk input tak valid yang bisa diperbaiki pengguna, ERROR untuk dependensi yang gagal dan menghalangi layanan.
- **Mengapa dua komponen pada `/health`?** DB `SELECT 1` membuktikan koneksi/query, cache put/get/forget membuktikan fungsi cache. Karena cache memakai driver database, ketika db mati keduanya biasanya unhealthy.
- **Mengapa route `/health` di bootstrap?** Untuk menggunakan API middleware tanpa session berbasis database pada grup web; kegagalan database tetap menghasilkan HTTP 503 JSON, bukan error HTTP 500 dari session middleware.
- **Mengapa healthcheck ada di backend?** Container backend hanya healthy jika proses HTTP dapat melayani permintaan **dan** database/cache siap, bukan sekadar proses PHP masih berjalan.
- **Kenapa tidak `down -v`?** `-v` menghapus volume dan merusak bukti persistensi.
- **Mengapa `max-size: 5m`, `max-file: 3`?** Untuk membatasi rotasi log tiap container menjadi kira-kira 15 MiB total file ter-rotate (bergantung overhead Docker), tanpa menghilangkan seluruh riwayat log sekaligus.

**Catatan pengujian:** patch telah diperiksa dengan `php -l` untuk file PHP dan parser YAML pada lingkungan penyiapan, tetapi Docker/FrankenPHP dan PostgreSQL belum bisa dijalankan dalam lingkungan ini. Bukti runtime harus diambil dari Mac menggunakan script pengumpulan, sebelum PDF dianggap final.
