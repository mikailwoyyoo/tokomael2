-- Add digital member cards and an optional transaction-to-member relation.
-- Safe to rerun: existing customer and transaction data are preserved.

CREATE TABLE IF NOT EXISTS `tbl_member` (
  `id_member` int(11) NOT NULL AUTO_INCREMENT,
  `id_pelanggan` int(11) NOT NULL,
  `card_code` varchar(25) NOT NULL,
  `diskon_persen` decimal(5,2) NOT NULL DEFAULT 5.00,
  `aktif` tinyint(1) NOT NULL DEFAULT 1,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_member`),
  UNIQUE KEY `uq_tbl_member_pelanggan` (`id_pelanggan`),
  UNIQUE KEY `uq_tbl_member_card_code` (`card_code`),
  CONSTRAINT `fk_tbl_member_pelanggan`
    FOREIGN KEY (`id_pelanggan`) REFERENCES `tbl_pelanggan` (`id_pelanggan`)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `chk_tbl_member_diskon` CHECK (`diskon_persen` >= 0 AND `diskon_persen` <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @schema_name = DATABASE();
SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'id_member') = 0,
  'ALTER TABLE `tbl_transaksi` ADD COLUMN `id_member` int(11) DEFAULT NULL AFTER `id_pelanggan`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_member' AND COLUMN_NAME = 'rfid_uid') = 0,
  'ALTER TABLE `tbl_member` ADD COLUMN `rfid_uid` varchar(64) DEFAULT NULL AFTER `card_code`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_member' AND INDEX_NAME = 'uq_tbl_member_rfid_uid') = 0,
  'ALTER TABLE `tbl_member` ADD UNIQUE KEY `uq_tbl_member_rfid_uid` (`rfid_uid`)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND INDEX_NAME = 'idx_tbl_transaksi_member') = 0,
  'ALTER TABLE `tbl_transaksi` ADD KEY `idx_tbl_transaksi_member` (`id_member`)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_transaksi' AND CONSTRAINT_NAME = 'fk_tbl_transaksi_member') = 0,
  'ALTER TABLE `tbl_transaksi` ADD CONSTRAINT `fk_tbl_transaksi_member` FOREIGN KEY (`id_member`) REFERENCES `tbl_member` (`id_member`) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
