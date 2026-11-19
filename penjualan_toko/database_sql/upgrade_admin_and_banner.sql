CREATE TABLE IF NOT EXISTS `tbl_admin` (
  `id_admin` tinyint unsigned NOT NULL,
  `username` varchar(50) NOT NULL,
  `nama_admin` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_admin`),
  UNIQUE KEY `uq_tbl_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_iklan_dashboard` (
  `id_iklan` tinyint unsigned NOT NULL,
  `judul` varchar(120) NOT NULL,
  `deskripsi` varchar(255) NOT NULL,
  `teks_tombol` varchar(40) NOT NULL DEFAULT 'Lihat Produk',
  `id_produk` int(11) DEFAULT NULL,
  `aktif` tinyint(1) NOT NULL DEFAULT 1,
  `diperbarui_pada` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_iklan`),
  KEY `idx_tbl_iklan_produk` (`id_produk`),
  CONSTRAINT `fk_tbl_iklan_produk`
    FOREIGN KEY (`id_produk`) REFERENCES `tbl_produk` (`id_produk`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `tbl_iklan_dashboard`
  (`id_iklan`, `judul`, `deskripsi`, `teks_tombol`, `id_produk`, `aktif`)
VALUES
  (1, 'Produk Pilihan, Belanja Lebih Mudah!', 'Temukan produk favorit Anda hari ini di MKLOYY.', 'Lihat Produk', NULL, 1);
