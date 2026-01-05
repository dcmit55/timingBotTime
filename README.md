# ProductionWebSystem

Web dashboard sederhana untuk mencatat output produksi per operator, menerima data dari ESP32 via HTTP POST, menampilkan tabel real-time, dan ekspor ke Excel (CSV).

## Cara pakai (XAMPP)

1. Ekstrak folder `ProductionWebSystem` ke:
   ```
   C:\xampp\htdocs\ProductionWebSystem\
   ```
2. Start **Apache** & **MySQL** dari XAMPP Control Panel.
3. Import database:
   - Buka `http://localhost/phpmyadmin`
   - Import file `sql/production.sql`
4. Buka UI:
   - `http://localhost/ProductionWebSystem/`
5. Test API simpan data:
   - `curl -X POST http://localhost/ProductionWebSystem/server/save_data.php -H "Content-Type: application/json" -d "{"operator":"Test","project":"P1","output":1}"`

## Konfigurasi
- Koneksi DB: `server/db.php` (default: user `root`, tanpa password, database `production`)
- CSV export: `server/export_excel.php`
- API get data (today): `server/get_data.php?today=1`

## ESP32 (contoh cepat)
Lihat `esp32_example/ESP32_Production.ino`. Ganti `ssid`, `password`, dan `serverUrl` sesuai IP server Anda.
