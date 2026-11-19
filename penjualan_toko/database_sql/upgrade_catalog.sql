-- Add category, product inventory, and unified activity log tables.
-- Safe to rerun: existing tables and seed records are preserved.

CREATE TABLE IF NOT EXISTS `tbl_kategori` (
  `id_kategori` int(11) NOT NULL AUTO_INCREMENT,
  `nama_kategori` varchar(100) NOT NULL,
  `deskripsi` varchar(255) DEFAULT NULL,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_kategori`),
  UNIQUE KEY `uq_tbl_kategori_nama` (`nama_kategori`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_produk` (
  `id_produk` int(11) NOT NULL AUTO_INCREMENT,
  `id_kategori` int(11) DEFAULT NULL,
  `nama_produk` varchar(100) NOT NULL,
  `harga` decimal(12,2) NOT NULL,
  `stok` int(11) NOT NULL DEFAULT 0,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_produk`),
  UNIQUE KEY `uq_tbl_produk_nama` (`nama_produk`),
  KEY `idx_tbl_produk_kategori` (`id_kategori`),
  CONSTRAINT `fk_tbl_produk_kategori`
    FOREIGN KEY (`id_kategori`) REFERENCES `tbl_kategori` (`id_kategori`)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `chk_tbl_produk_harga` CHECK (`harga` >= 0),
  CONSTRAINT `chk_tbl_produk_stok` CHECK (`stok` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tbl_log` (
  `id_log` bigint(20) NOT NULL AUTO_INCREMENT,
  `nama_tabel` varchar(64) NOT NULL,
  `id_referensi` int(11) DEFAULT NULL,
  `aktivitas` varchar(20) NOT NULL,
  `keterangan` varchar(255) NOT NULL,
  `waktu` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_log`),
  KEY `idx_tbl_log_waktu` (`waktu`),
  KEY `idx_tbl_log_tabel_referensi` (`nama_tabel`, `id_referensi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `tbl_kategori` (`nama_kategori`, `deskripsi`)
VALUES ('Umum', 'Kategori awal untuk produk yang sebelumnya tercatat pada detail transaksi');

INSERT IGNORE INTO `tbl_produk` (`id_kategori`, `nama_produk`, `harga`, `stok`)
SELECT k.`id_kategori`, d.`nama_produk`, ROUND(AVG(d.`harga`), 2), 0
FROM `tbl_detail_transaksi` d
JOIN `tbl_kategori` k ON k.`nama_kategori` = 'Umum'
WHERE d.`nama_produk` IS NOT NULL AND d.`nama_produk` <> ''
GROUP BY k.`id_kategori`, d.`nama_produk`;

SET @schema_name = DATABASE();
SET @image_column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_produk' AND COLUMN_NAME = 'gambar'
);
SET @migration_sql = IF(
  @image_column_exists = 0,
  'ALTER TABLE `tbl_produk` ADD COLUMN `gambar` varchar(255) DEFAULT NULL AFTER `stok`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'subtotal') = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `subtotal` decimal(12,2) DEFAULT NULL AFTER `total_belanja`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'diskon') = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `diskon` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'pajak') = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `pajak` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `diskon`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'uang_dibayar') = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `uang_dibayar` decimal(12,2) DEFAULT NULL AFTER `pajak`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'kembalian') = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `kembalian` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `uang_dibayar`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'metode_pembayaran') = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `metode_pembayaran` varchar(20) NOT NULL DEFAULT ''Tunai'' AFTER `kembalian`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_tbl_kategori_log_insert`$$
CREATE TRIGGER `trg_tbl_kategori_log_insert`
AFTER INSERT ON `tbl_kategori` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_kategori', NEW.`id_kategori`, 'INSERT', CONCAT('Kategori ditambahkan: ', NEW.`nama_kategori`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_kategori_log_update`$$
CREATE TRIGGER `trg_tbl_kategori_log_update`
AFTER UPDATE ON `tbl_kategori` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_kategori', NEW.`id_kategori`, 'UPDATE', CONCAT('Kategori diubah: ', OLD.`nama_kategori`, ' -> ', NEW.`nama_kategori`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_kategori_log_delete`$$
CREATE TRIGGER `trg_tbl_kategori_log_delete`
AFTER DELETE ON `tbl_kategori` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_kategori', OLD.`id_kategori`, 'DELETE', CONCAT('Kategori dihapus: ', OLD.`nama_kategori`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_produk_log_insert`$$
CREATE TRIGGER `trg_tbl_produk_log_insert`
AFTER INSERT ON `tbl_produk` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_produk', NEW.`id_produk`, 'INSERT', CONCAT('Produk ditambahkan: ', NEW.`nama_produk`, ', stok ', NEW.`stok`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_produk_log_update`$$
CREATE TRIGGER `trg_tbl_produk_log_update`
AFTER UPDATE ON `tbl_produk` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_produk', NEW.`id_produk`, 'UPDATE', CONCAT('Produk diperbarui: ', NEW.`nama_produk`, ', stok ', OLD.`stok`, ' -> ', NEW.`stok`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_produk_log_delete`$$
CREATE TRIGGER `trg_tbl_produk_log_delete`
AFTER DELETE ON `tbl_produk` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_produk', OLD.`id_produk`, 'DELETE', CONCAT('Produk dihapus: ', OLD.`nama_produk`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_transaksi_log_insert`$$
CREATE TRIGGER `trg_tbl_transaksi_log_insert`
AFTER INSERT ON `tbl_transaksi` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_transaksi', NEW.`id_transaksi`, 'INSERT', CONCAT('Transaksi dibuat untuk pelanggan #', NEW.`id_pelanggan`, ', total ', NEW.`total_belanja`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_transaksi_log_update`$$
CREATE TRIGGER `trg_tbl_transaksi_log_update`
AFTER UPDATE ON `tbl_transaksi` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_transaksi', NEW.`id_transaksi`, 'UPDATE', CONCAT('Total transaksi diubah: ', OLD.`total_belanja`, ' -> ', NEW.`total_belanja`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_transaksi_log_delete`$$
CREATE TRIGGER `trg_tbl_transaksi_log_delete`
AFTER DELETE ON `tbl_transaksi` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_transaksi', OLD.`id_transaksi`, 'DELETE', CONCAT('Transaksi dihapus untuk pelanggan #', OLD.`id_pelanggan`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_detail_log_insert`$$
CREATE TRIGGER `trg_tbl_detail_log_insert`
AFTER INSERT ON `tbl_detail_transaksi` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_detail_transaksi', NEW.`id_detail`, 'INSERT', CONCAT('Detail transaksi #', NEW.`id_transaksi`, ': ', NEW.`nama_produk`, ' x ', NEW.`jumlah`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_detail_log_update`$$
CREATE TRIGGER `trg_tbl_detail_log_update`
AFTER UPDATE ON `tbl_detail_transaksi` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_detail_transaksi', NEW.`id_detail`, 'UPDATE', CONCAT('Detail produk diubah: ', OLD.`nama_produk`, ' -> ', NEW.`nama_produk`));
END$$

DROP TRIGGER IF EXISTS `trg_tbl_detail_log_delete`$$
CREATE TRIGGER `trg_tbl_detail_log_delete`
AFTER DELETE ON `tbl_detail_transaksi` FOR EACH ROW
BEGIN
  INSERT INTO `tbl_log` (`nama_tabel`, `id_referensi`, `aktivitas`, `keterangan`)
  VALUES ('tbl_detail_transaksi', OLD.`id_detail`, 'DELETE', CONCAT('Detail transaksi #', OLD.`id_transaksi`, ' dihapus: ', OLD.`nama_produk`));
END$$

DELIMITER ;
