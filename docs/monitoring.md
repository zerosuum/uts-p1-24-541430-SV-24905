# Monitoring MulaiDulu

Monitoring dijalankan dalam `docker-compose.yml` yang sama dengan aplikasi. Prometheus mengumpulkan metrik, Grafana menampilkannya, cAdvisor memantau statistik cgroup, exporter `docker-stats` menyediakan penggunaan CPU dan memori per container berdasarkan Docker Engine, sedangkan node-exporter menyediakan metrik Linux dari VM Docker Desktop.

## Menjalankan

Isi variabel pada `.env` lokal berdasarkan `.env.example`. `GRAFANA_ADMIN_PASSWORD` harus menggunakan password yang berbeda dari bawaan Grafana; nilai rahasia tidak disimpan di Git.

```bash
docker compose config --quiet
docker compose up -d --build
docker compose ps
```

| Komponen | Alamat lokal |
| --- | --- |
| MulaiDulu | `http://localhost:8088` |
| Prometheus | `http://localhost:9090/targets` |
| Prometheus Alerts | `http://localhost:9090/alerts` |
| Grafana | `http://localhost:3000` |

Jika port Grafana diubah melalui `GRAFANA_PORT`, gunakan port tersebut sebagai pengganti `3000`.

Prometheus dan Grafana memakai named volumes `prometheus_data` dan `grafana_data`. Volume PostgreSQL `postgres_data` tetap digunakan. Hindari `docker compose down -v` bila data ingin dipertahankan.

## Konfigurasi dan metrik

- `monitoring/prometheus.yml`: lima target (`prometheus`, `cadvisor`, `node-exporter`, `mulaidulu`, `docker-stats`) dan interval scrape.
- `monitoring/alerts.yml`: aturan ketersediaan backend.
- `monitoring/grafana/dashboards/mulaidulu-overview.json`: panel Grafana yang diprovisioning otomatis.
- `backend/app/Http/Middleware/CountHttpRequests.php`: menghitung respons HTTP API berdasarkan metode dan status.

Endpoint `/metrics` menyediakan `mulaidulu_http_requests_total`. Label hanya berisi metode dan status HTTP; isi request, email, dan identitas pengguna tidak menjadi label. Route `/health`, `/metrics`, dan `/up` tidak dihitung sebagai trafik aplikasi.

Dashboard menggunakan `rate(mulaidulu_http_requests_total[1m])` untuk permintaan per detik, `rate(mulaidulu_container_cpu_usage_seconds_total[2m]) * 100` untuk CPU dalam persen satu core, serta `mulaidulu_container_memory_working_set_bytes` untuk memori per container. Status target memakai metrik `up` dari Prometheus.

Counter HTTP disimpan dalam berkas lokal backend. Nilainya dapat kembali ke nol saat container backend dibuat ulang. Pendekatan ini digunakan untuk satu instance lokal; penggunaan beberapa replica memerlukan penyimpanan dan agregasi metrik yang berbeda.

## Pemeriksaan dan pemulihan

Bila dashboard tidak menampilkan data, periksa `http://localhost:9090/targets` terlebih dahulu. Target `DOWN` menampilkan kesalahan scrape yang perlu ditangani sebelum memeriksa panel Grafana. cAdvisor dan node-exporter di macOS memantau lingkungan Linux Docker Desktop, bukan seluruh macOS.

Aturan `MulaiDuluBackendTidakTersedia` berubah menjadi `FIRING` jika target backend gagal di-scrape selama 45 detik. Anotasi aturan meminta operator memeriksa `docker compose ps`, lalu log backend dan status database.

Pengujian gangguan dilakukan dengan menghentikan backend tanpa menghentikan PostgreSQL:

```bash
docker compose stop backend
# Periksa status di http://localhost:9090/alerts setelah masa pending.
docker compose start backend
docker compose ps
```

Setelah backend menyala dan scrape berhasil, alarm akan kembali `inactive`. Jangan menghentikan Prometheus ketika menguji aturan ini.

## Akun Grafana

Nama pengguna awal adalah `admin`. Password dari `GRAFANA_ADMIN_PASSWORD` berlaku saat volume Grafana pertama kali dibuat. Jika volume sudah pernah dipakai, perubahan nilai `.env` tidak otomatis mengganti password pengguna lama; ubah melalui menu profil dan keamanan Grafana.

Port Grafana serta Prometheus dibatasi ke `127.0.0.1` di Compose. Endpoint `/metrics` aplikasi hanya diakses melalui jaringan internal container.

## Statistik container dari Docker Engine

Pada Docker Desktop, metrik cAdvisor dapat tersedia tanpa label nama service Compose. Exporter `monitoring/docker-stats` membaca statistik container dari Docker Engine dan menerbitkan penghitung CPU serta pemakaian memori berdasarkan nama container. Endpoint exporter hanya diakses Prometheus melalui jaringan Compose; tidak ada port yang dipublikasikan ke host.

Exporter memerlukan akses ke Docker socket. Akses ini memiliki hak istimewa tinggi meskipun bind mount diberi opsi `:ro`. Pengaturan ini ditujukan untuk lingkungan development lokal; jangan menjalankan exporter tersebut di host bersama pengguna yang tidak dipercaya tanpa pembatasan tambahan.

Untuk memeriksa hasil, buka Prometheus dan jalankan `mulaidulu_container_memory_working_set_bytes{job="docker-stats"}`. Panel CPU menggunakan `rate` sehingga memerlukan beberapa sampel setelah exporter dinyalakan.
