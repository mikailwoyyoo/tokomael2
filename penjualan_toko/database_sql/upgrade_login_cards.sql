-- Add PIN-protected RFID login cards for administrators, cashiers, and members.
-- Safe to rerun: existing credentials and member card UIDs are preserved.
-- Run after upgrade_admin_and_banner.sql, upgrade_roles_portal.sql, and upgrade_member_cards.sql.

CREATE TABLE IF NOT EXISTS `tbl_login_kartu` (
  `id_login_kartu` int(11) NOT NULL AUTO_INCREMENT,
  `uid_kartu` varchar(64) NOT NULL,
  `role` varchar(16) NOT NULL,
  `id_akun` int(11) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `aktif` tinyint(1) NOT NULL DEFAULT 1,
  `percobaan_gagal` tinyint unsigned NOT NULL DEFAULT 0,
  `terkunci_sampai` datetime DEFAULT NULL,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `diperbarui_pada` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_login_kartu`),
  UNIQUE KEY `uq_tbl_login_kartu_uid` (`uid_kartu`),
  UNIQUE KEY `uq_tbl_login_kartu_akun_role` (`role`, `id_akun`),
  KEY `idx_tbl_login_kartu_role_akun` (`role`, `id_akun`, `aktif`),
  CONSTRAINT `chk_tbl_login_kartu_role` CHECK (`role` IN ('admin', 'cashier', 'member'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @schema_name = DATABASE();
SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_login_kartu' AND COLUMN_NAME = 'percobaan_gagal') = 0,
  'ALTER TABLE `tbl_login_kartu` ADD COLUMN `percobaan_gagal` tinyint unsigned NOT NULL DEFAULT 0 AFTER `aktif`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_login_kartu' AND COLUMN_NAME = 'terkunci_sampai') = 0,
  'ALTER TABLE `tbl_login_kartu` ADD COLUMN `terkunci_sampai` datetime DEFAULT NULL AFTER `percobaan_gagal`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
