<?php

require __DIR__ . '/includes/helpers.php';
requireRole(['admin', 'cashier', 'member']);
require __DIR__ . '/config/database.php';

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
if (!$orderId || $orderId < 1) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$orderQuery = $pdo->prepare(
    'SELECT id_member, bukti_pembayaran FROM tbl_pesanan_online WHERE id_pesanan = ?'
);
$orderQuery->execute([$orderId]);
$order = $orderQuery->fetch();
$role = currentRole();
if (!$order
    || ($role === 'member' && (int) $order['id_member'] !== currentUserId())
    || !is_string($order['bukti_pembayaran'])) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$prefix = 'qris_proofs/';
$relativePath = $order['bukti_pembayaran'];
if (strpos($relativePath, $prefix) !== 0) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$filename = substr($relativePath, strlen($prefix));
if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $filename) !== 1) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$filePath = privateUploadDirectory('qris_proofs') . DIRECTORY_SEPARATOR . $filename;
$imageInfo = is_file($filePath) ? getimagesize($filePath) : false;
$mimeTypes = [
    IMAGETYPE_JPEG => 'image/jpeg',
    IMAGETYPE_PNG => 'image/png',
    IMAGETYPE_WEBP => 'image/webp',
];
if ($imageInfo === false || !isset($mimeTypes[$imageInfo[2]])) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

header('Content-Type: ' . $mimeTypes[$imageInfo[2]]);
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: inline; filename="bukti-qris-' . $orderId . '.' . pathinfo($filename, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
