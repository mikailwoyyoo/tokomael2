<?php

require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/duitku.php';
require __DIR__ . '/config/database.php';

function duitkuCallbackResponse(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    duitkuCallbackResponse(405, 'Method not allowed');
}

try {
    $config = assertDuitkuConfigured();
    $merchantCode = trim((string) ($_POST['merchantCode'] ?? ''));
    $amountString = trim((string) ($_POST['amount'] ?? ''));
    $merchantOrderId = trim((string) ($_POST['merchantOrderId'] ?? ''));
    $signature = strtolower(trim((string) ($_POST['signature'] ?? '')));
    $resultCode = trim((string) ($_POST['resultCode'] ?? ''));
    $reference = trim((string) ($_POST['reference'] ?? ''));
    $paymentCode = trim((string) ($_POST['paymentCode'] ?? ''));

    if ($merchantCode !== $config['merchant_code']
        || preg_match('/^[0-9]{1,12}$/D', $amountString) !== 1
        || $merchantOrderId === ''
        || $reference === ''
        || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
        || !in_array($resultCode, ['00', '01'], true)) {
        duitkuCallbackResponse(400, 'Invalid callback parameters');
    }

    $expectedSignature = hash_hmac(
        'sha256',
        $merchantCode . $amountString . $merchantOrderId,
        $config['api_key']
    );
    if (!hash_equals($expectedSignature, $signature)) {
        error_log('Duitku callback rejected: invalid signature.');
        duitkuCallbackResponse(403, 'Invalid signature');
    }

    $pdo->beginTransaction();
    $orderQuery = $pdo->prepare(
        'SELECT * FROM tbl_pesanan_online WHERE nomor_pesanan = ? FOR UPDATE'
    );
    $orderQuery->execute([$merchantOrderId]);
    $order = $orderQuery->fetch();
    if (!$order
        || $order['metode_pembayaran'] !== 'Duitku'
        || $order['duitku_payment_method'] === null
        || $paymentCode !== $order['duitku_payment_method']
        || (int) round((float) $order['total']) !== (int) $amountString) {
        $pdo->rollBack();
        error_log('Duitku callback rejected: order or amount mismatch.');
        duitkuCallbackResponse(404, 'Order not found');
    }

    if ($order['status_pembayaran'] === 'Dibayar') {
        if ($order['duitku_reference'] !== null && $order['duitku_reference'] !== $reference) {
            $pdo->rollBack();
            error_log('Duitku duplicate callback rejected: reference mismatch.');
            duitkuCallbackResponse(409, 'Payment reference mismatch');
        }
        $pdo->commit();
        duitkuCallbackResponse(200, 'OK');
    }

    if ($resultCode !== '00') {
        if ($order['status_pembayaran'] !== 'Menunggu pembayaran') {
            $pdo->commit();
            duitkuCallbackResponse(200, 'OK');
        }

        $detailsQuery = $pdo->prepare(
            'SELECT id_produk, jumlah FROM tbl_detail_pesanan_online WHERE id_pesanan = ?'
        );
        $detailsQuery->execute([(int) $order['id_pesanan']]);
        $restoreStock = $pdo->prepare('UPDATE tbl_produk SET stok = stok + ? WHERE id_produk = ?');
        foreach ($detailsQuery->fetchAll() as $detail) {
            if ($detail['id_produk'] !== null) {
                $restoreStock->execute([(int) $detail['jumlah'], (int) $detail['id_produk']]);
            }
        }

        $updateFailure = $pdo->prepare(
            'UPDATE tbl_pesanan_online
             SET status = ?, status_pembayaran = ?, duitku_reference = ?, duitku_payment_code = ?
             WHERE id_pesanan = ?'
        );
        $updateFailure->execute([
            'Dibatalkan',
            'Gagal',
            $reference,
            $paymentCode !== '' ? $paymentCode : null,
            (int) $order['id_pesanan'],
        ]);
        $pdo->commit();
        duitkuCallbackResponse(200, 'OK');
    }

    if ($order['status'] === 'Dibatalkan') {
        $pdo->rollBack();
        error_log('Duitku reported successful payment for an already-cancelled order.');
        duitkuCallbackResponse(409, 'Order already cancelled');
    }

    $sale = $pdo->prepare(
        'INSERT INTO tbl_transaksi
            (tanggal, id_pelanggan, id_member, id_kasir, total_belanja, subtotal, diskon, pajak, uang_dibayar, kembalian, metode_pembayaran)
         VALUES (CURRENT_DATE(), ?, ?, NULL, ?, ?, ?, ?, ?, 0, ?)'
    );
    $sale->execute([
        (int) $order['id_pelanggan'],
        (int) $order['id_member'],
        (float) $order['total'],
        (float) $order['subtotal'],
        (float) $order['diskon'],
        (float) $order['pajak'],
        (float) $order['total'],
        (string) ($order['duitku_payment_name'] ?: 'Duitku ' . $paymentCode),
    ]);
    $transactionId = (int) $pdo->lastInsertId();

    $copyDetails = $pdo->prepare(
        'INSERT INTO tbl_detail_transaksi (id_transaksi, nama_produk, jumlah, harga)
         SELECT ?, nama_produk, jumlah, harga
         FROM tbl_detail_pesanan_online WHERE id_pesanan = ?'
    );
    $copyDetails->execute([$transactionId, (int) $order['id_pesanan']]);

    $markPaid = $pdo->prepare(
        "UPDATE tbl_pesanan_online
         SET status = 'Siap diambil', status_pembayaran = 'Dibayar',
             duitku_reference = ?, duitku_payment_code = ?, duitku_paid_at = CURRENT_TIMESTAMP,
             id_transaksi = ?
         WHERE id_pesanan = ?"
    );
    $markPaid->execute([
        $reference,
        $paymentCode !== '' ? $paymentCode : null,
        $transactionId,
        (int) $order['id_pesanan'],
    ]);
    $pdo->commit();
    duitkuCallbackResponse(200, 'OK');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Duitku callback processing failed: ' . $exception->getMessage());
    duitkuCallbackResponse(500, 'Callback processing failed');
}
