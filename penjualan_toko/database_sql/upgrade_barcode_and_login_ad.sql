-- Run after upgrade_catalog.sql and upgrade_admin_and_banner.sql.
-- Adds scanner-ready product EAN-13 values and a separate custom login/dashboard ad image.
SET @schema_name = DATABASE();

SET @barcode_column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_produk' AND COLUMN_NAME = 'barcode'
);
SET @migration_sql = IF(
  @barcode_column_exists = 0,
  'ALTER TABLE `tbl_produk` ADD COLUMN `barcode` varchar(64) DEFAULT NULL AFTER `gambar`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @barcode_column_length = (
  SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_produk' AND COLUMN_NAME = 'barcode'
);
SET @migration_sql = IF(
  @barcode_column_length < 64,
  'ALTER TABLE `tbl_produk` MODIFY COLUMN `barcode` varchar(64) DEFAULT NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @barcode_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_produk' AND INDEX_NAME = 'uq_tbl_produk_barcode'
);
SET @migration_sql = IF(
  @barcode_index_exists = 0,
  'ALTER TABLE `tbl_produk` ADD UNIQUE KEY `uq_tbl_produk_barcode` (`barcode`)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @ad_image_column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'tbl_iklan_dashboard' AND COLUMN_NAME = 'gambar_iklan'
);
SET @migration_sql = IF(
  @ad_image_column_exists = 0,
  'ALTER TABLE `tbl_iklan_dashboard` ADD COLUMN `gambar_iklan` varchar(255) DEFAULT NULL AFTER `teks_tombol`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
