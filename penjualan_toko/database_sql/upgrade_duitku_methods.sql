-- Store the selected non-cash Duitku channel on online orders and transactions.
-- Safe to rerun; existing orders and transactions are preserved.

SET @schema_name = DATABASE();
SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @schema_name
     AND TABLE_NAME = 'tbl_pesanan_online'
     AND COLUMN_NAME = 'duitku_payment_name') = 0,
  'ALTER TABLE `tbl_pesanan_online` ADD COLUMN `duitku_payment_name` varchar(80) DEFAULT NULL AFTER `duitku_payment_method`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

ALTER TABLE `tbl_transaksi`
  MODIFY COLUMN `metode_pembayaran` varchar(80) NOT NULL DEFAULT 'Tunai';

UPDATE `tbl_pesanan_online`
SET `metode_pembayaran` = 'Duitku'
WHERE `duitku_payment_method` IS NOT NULL
  AND `metode_pembayaran` = 'QRIS';
