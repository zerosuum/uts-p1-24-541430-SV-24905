# MulaiDulu

**Mulai satu hal dulu, sisanya bisa menyusul.**

MulaiDulu adalah aplikasi pengelolaan tugas sederhana yang membantu pengguna mencatat hal-hal yang perlu dikerjakan, memilih satu tugas untuk difokuskan, dan menentukan langkah pertama yang lebih mudah dilakukan.

Project ini dikembangkan untuk Ujian Tengah Semester mata kuliah **Konstruksi dan Evolusi Perangkat Lunak**, Program Studi D4 Teknologi Rekayasa Perangkat Lunak, Universitas Gadjah Mada, tahun akademik 2026/2027.

## Tentang aplikasi

Tidak semua tugas harus langsung dijadwalkan atau diselesaikan sekaligus. MulaiDulu dirancang agar pengguna bisa mencatat tugas terlebih dahulu, kemudian memilih satu hal yang ingin dikerjakan tanpa harus mengatur banyak detail di awal.

Alur utama aplikasi:

1. **Inbox** — mencatat dan melihat tugas.
2. **Langkah kecil** — menambahkan langkah pertama jika diperlukan.
3. **Fokus** — menampilkan satu tugas yang sedang dikerjakan.
4. **Selesai** — menyimpan tugas yang sudah diselesaikan.

Pengguna juga bisa mengubah, menghapus, menunda, dan mengembalikan tugas ke Inbox.

## Teknologi

| Bagian | Teknologi |
|---|---|
| Backend | Laravel 12, PHP 8.4 |
| Autentikasi API | Laravel Sanctum |
| Frontend | Vue 3, TypeScript, Vite |
| HTTP Client | Axios |
| Database | PostgreSQL 16 |
| Web Server Frontend | Nginx |
| Application Server | FrankenPHP |
| Containerization | Docker, Docker Compose |
| CI/CD | GitHub Actions |
| Container Registry | GitHub Container Registry |
| API Testing | Postman, Laravel Feature Tests |

## Struktur repository

```text
.
├── .github/
│   └── workflows/
│       └── ci.yml
├── backend/
│   ├── app/
│   ├── database/
│   ├── routes/
│   ├── tests/
│   ├── Dockerfile
│   ├── Caddyfile
│   └── docker-start.sh
├── frontend/
│   ├── src/
│   ├── Dockerfile
│   └── nginx.conf
├── postman/
├── docker-compose.yml
├── .env.example
└── README.md
```

Folder `backend/` menangani autentikasi, validasi, REST API, dan akses database. Folder `frontend/` menangani antarmuka pengguna dan komunikasi API melalui Axios.

## Menjalankan aplikasi

### Persyaratan

Pastikan Git, PHP, Composer, Node.js, npm, dan Docker Desktop sudah tersedia.

### Menjalankan dengan Docker Compose

Cara utama menjalankan keseluruhan aplikasi adalah melalui Docker Compose.

1. Salin `.env.example` menjadi `.env` pada root repository.
2. Isi `APP_KEY`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD`.
3. Untuk akun pengujian lokal, isi `DEMO_EMAIL` dan `DEMO_PASSWORD`.
4. Jalankan:

```bash
docker compose up -d --build
```

5. Buat akun pengujian jika diperlukan:

```bash
docker compose exec backend php artisan db:seed --class=DemoUserSeeder
```

6. Buka `http://localhost:8080`.

Untuk memeriksa container:

```bash
docker compose ps
docker compose logs backend
```

Untuk menghentikan aplikasi tanpa menghapus data:

```bash
docker compose down
```

Database menggunakan Docker named volume, sehingga data tetap tersimpan ketika container dihentikan dan dijalankan kembali tanpa menghapus volume.

### Menjalankan secara lokal untuk development

Backend:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Atur `DB_CONNECTION=sqlite` pada `backend/.env`, siapkan file database SQLite dan akun pengujian lokal, kemudian jalankan:

```bash
php artisan migrate
php artisan db:seed --class=DemoUserSeeder
php artisan serve
```

Frontend, dari terminal terpisah:

```bash
cd frontend
npm ci
cp .env.example .env
npm run dev
```

Frontend development tersedia pada `http://localhost:5173`, sedangkan API Laravel berjalan pada `http://localhost:8000`.

Alamat API dikonfigurasi melalui `VITE_API_BASE_URL`, bukan ditulis langsung di komponen.

## REST API

MulaiDulu menggunakan autentikasi Bearer Token melalui Laravel Sanctum.

| Method | Endpoint | Keterangan |
|---|---|---|
| POST | `/api/login` | Login dan mendapatkan token |
| POST | `/api/logout` | Logout dan mencabut token |
| GET | `/api/tasks` | Menampilkan daftar tugas |
| GET | `/api/tasks/{id}` | Menampilkan detail tugas |
| POST | `/api/tasks` | Menambahkan tugas |
| PUT/PATCH | `/api/tasks/{id}` | Mengubah tugas |
| DELETE | `/api/tasks/{id}` | Menghapus tugas |

Seluruh endpoint selain login memerlukan token autentikasi.

API menggunakan JSON, termasuk respons validasi dan error. Setiap pengguna hanya dapat mengakses tugas miliknya sendiri.

Postman Collection tersedia di folder `postman/`.

## Pengujian

Pengujian backend:

```bash
cd backend
php artisan test
```

Pengujian build frontend:

```bash
cd frontend
npm ci
npm run build
```

Pengujian API secara manual dilakukan menggunakan Postman, mencakup login, CRUD, validasi input, dan akses tanpa token.

## Git Workflow dan CI/CD

Pengembangan menggunakan tiga jenis branch:

- `main` — kode yang sudah melewati integrasi dan pengujian.
- `dev` — branch integrasi pengembangan.
- `feat/*` — branch untuk perubahan atau fitur tertentu.

Perubahan ke `dev` dan `main` digabungkan melalui Pull Request.

GitHub Actions menjalankan pengujian Laravel dan build Vue. Setelah konfigurasi deployment aktif, push ke `main` akan membangun image Docker backend dan frontend, kemudian menerbitkannya ke GitHub Container Registry menggunakan tag berbasis commit SHA.

Publikasi image ke GHCR tidak berarti aplikasi otomatis di-host pada server publik.

## Konfigurasi dan keamanan

File `.env` digunakan untuk menyimpan konfigurasi lokal serta nilai rahasia dan tidak disertakan dalam repository.

File `.env.example` disediakan sebagai referensi variabel yang perlu diisi.

Jangan menyimpan password, token autentikasi, atau application key di source code maupun Dockerfile.

## Status pengembangan

Project masih dalam tahap implementasi dan pengujian untuk memenuhi kebutuhan UTS. Hasil akhir setiap fitur, workflow, dan pengujian akan diverifikasi melalui aplikasi yang berjalan, GitHub Actions, dan dokumentasi laporan.

---

**MulaiDulu** — satu tugas, satu langkah pertama.
