-- Add cashier logins, member portal credentials, and transaction ownership.
-- Safe to rerun: existing users and sales are preserved.

CREATE TABLE IF NOT EXISTS `tbl_kasir` (
  `id_kasir` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `nama_kasir` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `aktif` tinyint(1) NOT NULL DEFAULT 1,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_kasir`),
  UNIQUE KEY `uq_tbl_kasir_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @schema_name = DATABASE();
SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_member' AND COLUMN_NAME = 'username') = 0,
  'ALTER TABLE `tbl_member` ADD COLUMN `username` varchar(50) DEFAULT NULL AFTER `rfid_uid`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_member' AND COLUMN_NAME = 'password_hash') = 0,
  'ALTER TABLE `tbl_member` ADD COLUMN `password_hash` varchar(255) DEFAULT NULL AFTER `username`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_member' AND INDEX_NAME = 'uq_tbl_member_username') = 0,
  'ALTER TABLE `tbl_member` ADD UNIQUE KEY `uq_tbl_member_username` (`username`)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'id_kasir') = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `id_kasir` int(11) DEFAULT NULL AFTER `id_member`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND INDEX_NAME = 'idx_tbl_transaksi_kasir_tanggal') = 0,
  'ALTER TABLE `tbl_transaksi` ADD KEY `idx_tbl_transaksi_kasir_tanggal` (`id_kasir`, `tanggal`)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND CONSTRAINT_NAME = 'fk_tbl_transaksi_kasir') = 0,
  'ALTER TABLE `tbl_transaksi` ADD CONSTRAINT `fk_tbl_transaksi_kasir` FOREIGN KEY (`id_kasir`) REFERENCES `tbl_kasir` (`id_kasir`) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
