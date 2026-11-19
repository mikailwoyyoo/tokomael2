# Menyiapkan pembayaran Duitku

Integrasi ini menggunakan halaman pembayaran Duitku. Pesanan online dibuat saat checkout, tetapi baru berstatus **Siap diambil** setelah callback pembayaran yang valid diterima.

## 1. Aktifkan proyek Duitku

Aktifkan akun/proyek merchant terlebih dahulu dan siapkan Merchant Code serta API Key sandbox dari portal Duitku. Jangan kirim API Key melalui chat atau menyimpannya di source control.

## 2. Atur konfigurasi lokal

Di folder proyek, salin template:

```powershell
Copy-Item config\duitku.local.php.example config\duitku.local.php
```

Isi `merchant_code`, `api_key`, dan `environment` (`sandbox` untuk pengujian). Atur `base_url` menjadi URL HTTPS publik yang tepat menuju folder aplikasi, tanpa garis miring di akhir. Contohnya:

```php
'base_url' => 'https://toko.example.id/aplikasi',
```

Jangan gunakan `localhost`, alamat IP lokal, atau URL dengan query string. File `config/duitku.local.php` dikecualikan dari Git.

Pastikan ekstensi PHP cURL aktif dan sertifikat CA/TLS PHP berfungsi.

## 3. Metode pembayaran

Login sebagai Admin, lalu buka **Integrasi Duitku** untuk melihat kanal non-tunai yang aktif. Pelanggan akan dapat memilih kanal yang aktif untuk nominal keranjangnya, seperti transfer/virtual account bank, e-wallet, kartu, atau QRIS jika tersedia. Metode tunai dan kanal pembayaran tunai di gerai tidak ditampilkan. Kode kanal tidak perlu disalin ke konfigurasi.

Daftar kanal bisa dimuat dari `localhost` setelah Merchant Code dan API Key sandbox valid. Namun, tombol checkout baru aktif jika `base_url` menggunakan HTTPS publik, karena Duitku harus bisa mengirim callback ke aplikasi. Untuk pengujian lokal, arahkan tunnel HTTPS publik ke folder aplikasi dan gunakan URL tunnel sebagai `base_url`.

## 4. Atur URL callback

Daftarkan URL berikut pada pengaturan proyek Duitku:

- Callback: `https://toko.example.id/aplikasi/duitku_callback.php`
- Return: `https://toko.example.id/aplikasi/duitku_return.php`

Kedua URL harus mengarah ke instalasi aplikasi yang sama. Callback harus dapat dijangkau dari server Duitku melalui HTTPS.

## 5. Perbarui database

Jalankan `database_sql/upgrade_online_orders.sql` setelah migrasi katalog, member, dan role/portal yang menjadi prasyaratnya. Untuk database yang migrasi pesanan online-nya sudah pernah dijalankan, jalankan juga `database_sql/upgrade_duitku_methods.sql` agar nama metode pembayaran pilihan tersimpan.

## 6. Uji sebelum produksi

1. Gunakan kredensial sandbox dan kanal non-tunai sandbox yang aktif.
2. Buat checkout dari akun Pelanggan dengan alamat email valid.
3. Pastikan pelanggan diarahkan ke halaman pembayaran Duitku.
4. Catat **Referensi Duitku** yang ditampilkan pada riwayat/status pesanan setelah invoice dibuat. Pastikan callback mencapai `duitku_callback.php`, lalu periksa status pesanan menjadi **Dibayar / Siap diambil** dan transaksi tercatat satu kali dengan metode pembayaran yang dipilih. Nomor pesanan juga tampil sebagai referensi toko jika invoice belum berhasil dibuat.
5. Setelah pengujian sukses, ganti kredensial, environment, dan metode pembayaran ke data production yang disediakan Duitku.

Status di halaman redirect tidak dianggap sebagai bukti pembayaran. Hanya callback dengan signature, merchant, nominal, dan nomor pesanan yang cocok yang dapat mengonfirmasi pembayaran. Jika pembuatan invoice gagal sebelum invoice tersimpan, pesanan dibatalkan dan stok yang telah dicadangkan dikembalikan.
