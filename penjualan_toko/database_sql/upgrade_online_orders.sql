-- Add pickup orders, QRIS proof review, and private payment-proof metadata without changing POS tables.
-- Safe to rerun: existing products, members, and transactions are preserved.
-- Run after upgrade_catalog.sql, upgrade_member_cards.sql, and upgrade_roles_portal.sql.

CREATE TABLE IF NOT EXISTS `tbl_pesanan_online` (
  `id_pesanan` int(11) NOT NULL AUTO_INCREMENT,
  `nomor_pesanan` varchar(32) NOT NULL,
  `id_pelanggan` int(11) NOT NULL,
  `id_member` int(11) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Menunggu',
  `subtotal` decimal(12,2) NOT NULL,
  `diskon` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pajak` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL,
  `id_transaksi` int(11) DEFAULT NULL,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_pesanan`),
  UNIQUE KEY `uq_tbl_pesanan_online_nomor` (`nomor_pesanan`),
  UNIQUE KEY `uq_tbl_pesanan_online_transaksi` (`id_transaksi`),
  KEY `idx_tbl_pesanan_online_pelanggan` (`id_pelanggan`, `dibuat_pada`),
  KEY `idx_tbl_pesanan_online_status` (`status`, `dibuat_pada`),
  CONSTRAINT `fk_tbl_pesanan_online_pelanggan`
    FOREIGN KEY (`id_pelanggan`) REFERENCES `tbl_pelanggan` (`id_pelanggan`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_tbl_pesanan_online_member`
    FOREIGN KEY (`id_member`) REFERENCES `tbl_member` (`id_member`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_tbl_pesanan_online_transaksi`
    FOREIGN KEY (`id_transaksi`) REFERENCES `tbl_transaksi` (`id_transaksi`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_detail_pesanan_online` (
  `id_detail_pesanan` int(11) NOT NULL AUTO_INCREMENT,
  `id_pesanan` int(11) NOT NULL,
  `id_produk` int(11) DEFAULT NULL,
  `nama_produk` varchar(100) NOT NULL,
  `jumlah` int(11) NOT NULL,
  `harga` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id_detail_pesanan`),
  KEY `idx_tbl_detail_pesanan` (`id_pesanan`),
  KEY `idx_tbl_detail_pesanan_produk` (`id_produk`),
  CONSTRAINT `fk_tbl_detail_pesanan_order`
    FOREIGN KEY (`id_pesanan`) REFERENCES `tbl_pesanan_online` (`id_pesanan`)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_tbl_detail_pesanan_produk`
    FOREIGN KEY (`id_produk`) REFERENCES `tbl_produk` (`id_produk`)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @schema_name = DATABASE();
SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_pesanan_online' AND COLUMN_NAME = 'metode_pembayaran') = 0,
  'ALTER TABLE `tbl_pesanan_online` ADD COLUMN `metode_pembayaran` varchar(32) NOT NULL DEFAULT ''Tunai saat ambil'' AFTER `total`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

ALTER TABLE `tbl_pesanan_online`
  MODIFY COLUMN `metode_pembayaran` varchar(32) NOT NULL DEFAULT 'QRIS';

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_pesanan_online' AND COLUMN_NAME = 'status_pembayaran') = 0,
  'ALTER TABLE `tbl_pesanan_online` ADD COLUMN `status_pembayaran` varchar(32) NOT NULL DEFAULT ''Menunggu pembayaran'' AFTER `metode_pembayaran`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_pesanan_online' AND COLUMN_NAME = 'bukti_pembayaran') = 0,
  'ALTER TABLE `tbl_pesanan_online` ADD COLUMN `bukti_pembayaran` varchar(255) DEFAULT NULL AFTER `status_pembayaran`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_pesanan_online' AND COLUMN_NAME = 'diverifikasi_pada') = 0,
  'ALTER TABLE `tbl_pesanan_online` ADD COLUMN `diverifikasi_pada` timestamp NULL DEFAULT NULL AFTER `bukti_pembayaran`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_pesanan_online' AND COLUMN_NAME = 'diverifikasi_role') = 0,
  'ALTER TABLE `tbl_pesanan_online` ADD COLUMN `diverifikasi_role` varchar(16) DEFAULT NULL AFTER `diverifikasi_pada`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_pesanan_online' AND COLUMN_NAME = 'diverifikasi_id') = 0,
  'ALTER TABLE `tbl_pesanan_online` ADD COLUMN `diverifikasi_id` int(11) DEFAULT NULL AFTER `diverifikasi_role`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE `tbl_pesanan_online`
SET `status_pembayaran` = IF(`status` = 'Selesai', 'Dibayar', 'Menunggu pembayaran')
WHERE `metode_pembayaran` = 'Tunai saat ambil'
  AND `status_pembayaran` = 'Menunggu pembayaran';

CREATE TABLE IF NOT EXISTS `tbl_pengaturan_qris` (
  `id_qris` tinyint(4) NOT NULL,
  `gambar_path` varchar(255) DEFAULT NULL,
  `diperbarui_pada` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_qris`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `tbl_pengaturan_qris` (`id_qris`, `gambar_path`) VALUES (1, NULL);
