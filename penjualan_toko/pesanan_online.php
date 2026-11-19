<?php

require __DIR__ . '/includes/helpers.php';
requireRole(['admin', 'cashier']);
require __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['status'] ?? '');
        $paymentAction = (string) ($_POST['payment_action'] ?? '');
        if (!$orderId
            || (!in_array($newStatus, ['Siap diambil', 'Selesai', 'Dibatalkan'], true)
                && !in_array($paymentAction, ['verify', 'reject'], true))) {
            throw new InvalidArgumentException('Perubahan status pesanan tidak valid.');
        }

        $pdo->beginTransaction();
        $orderQuery = $pdo->prepare('SELECT * FROM tbl_pesanan_online WHERE id_pesanan = ? FOR UPDATE');
        $orderQuery->execute([$orderId]);
        $order = $orderQuery->fetch();
        if (!$order) {
            throw new InvalidArgumentException('Pesanan tidak ditemukan.');
        }

        if ($paymentAction !== '') {
            if ($order['metode_pembayaran'] !== 'QRIS'
                || $order['status_pembayaran'] !== 'Menunggu verifikasi'
                || !$order['bukti_pembayaran']) {
                throw new InvalidArgumentException('Bukti QRIS belum tersedia atau sudah diproses.');
            }

            if ($paymentAction === 'verify') {
                $verifyPayment = $pdo->prepare(
                    "UPDATE tbl_pesanan_online
                     SET status_pembayaran = 'Dibayar', diverifikasi_pada = CURRENT_TIMESTAMP,
                         diverifikasi_role = ?, diverifikasi_id = ?
                     WHERE id_pesanan = ?"
                );
                $verifyPayment->execute([currentRole(), currentUserId(), $orderId]);
                $paymentMessage = 'Bukti QRIS diverifikasi. Pesanan dapat diselesaikan setelah barang diserahkan.';
            } else {
                $rejectPayment = $pdo->prepare(
                    "UPDATE tbl_pesanan_online SET status_pembayaran = 'Ditolak'
                     WHERE id_pesanan = ?"
                );
                $rejectPayment->execute([$orderId]);
                $paymentMessage = 'Bukti QRIS ditolak. Pelanggan dapat mengunggah bukti pembayaran yang benar.';
            }

            $pdo->commit();
            setFlash('success', $paymentMessage);
            header('Location: pesanan_online.php');
            exit;
        }

        $currentStatus = (string) $order['status'];
        $allowedTransitions = [
            'Menunggu' => ['Siap diambil', 'Dibatalkan'],
            'Siap diambil' => ['Selesai', 'Dibatalkan'],
        ];
        if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            throw new InvalidArgumentException('Status pesanan sudah berubah atau transisi ini tidak diizinkan.');
        }

        if ($newStatus === 'Dibatalkan' && $order['status_pembayaran'] === 'Dibayar') {
            throw new InvalidArgumentException('Pesanan yang sudah dibayar QRIS tidak dapat dibatalkan dari halaman ini.');
        }

        if ($newStatus === 'Selesai' && $order['metode_pembayaran'] === 'QRIS'
            && $order['status_pembayaran'] !== 'Dibayar') {
            throw new InvalidArgumentException('Verifikasi pembayaran QRIS sebelum menyelesaikan pesanan.');
        }

        $transactionId = null;
        if ($newStatus === 'Dibatalkan') {
            $detailsQuery = $pdo->prepare(
                'SELECT id_produk, jumlah FROM tbl_detail_pesanan_online WHERE id_pesanan = ?'
            );
            $detailsQuery->execute([$orderId]);
            $restoreStock = $pdo->prepare('UPDATE tbl_produk SET stok = stok + ? WHERE id_produk = ?');
            foreach ($detailsQuery->fetchAll() as $detail) {
                if ($detail['id_produk'] !== null) {
                    $restoreStock->execute([(int) $detail['jumlah'], (int) $detail['id_produk']]);
                }
            }
        } elseif ($newStatus === 'Selesai') {
            $cashierId = currentRole() === 'cashier' ? currentUserId() : null;
            $createSale = $pdo->prepare(
                'INSERT INTO tbl_transaksi
                    (tanggal, id_pelanggan, id_member, id_kasir, total_belanja, subtotal, diskon, pajak, uang_dibayar, kembalian, metode_pembayaran)
                 VALUES (CURRENT_DATE(), ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
            );
            $createSale->execute([
                (int) $order['id_pelanggan'],
                (int) $order['id_member'],
                $cashierId,
                (float) $order['total'],
                (float) $order['subtotal'],
                (float) $order['diskon'],
                (float) $order['pajak'],
                (float) $order['total'],
                $order['metode_pembayaran'] === 'QRIS' ? 'QRIS' : 'Tunai',
            ]);
            $transactionId = (int) $pdo->lastInsertId();
            $copyDetails = $pdo->prepare(
                'INSERT INTO tbl_detail_transaksi (id_transaksi, nama_produk, jumlah, harga)
                 SELECT ?, nama_produk, jumlah, harga
                 FROM tbl_detail_pesanan_online WHERE id_pesanan = ?'
            );
            $copyDetails->execute([$transactionId, $orderId]);
        }

        $updateOrder = $pdo->prepare(
            "UPDATE tbl_pesanan_online
             SET status = ?, id_transaksi = ?,
                 status_pembayaran = IF(? = 'Dibatalkan', 'Dibatalkan', status_pembayaran)
             WHERE id_pesanan = ?"
        );
        $updateOrder->execute([$newStatus, $transactionId, $newStatus, $orderId]);
        $pdo->commit();
        setFlash('success', $newStatus === 'Selesai'
            ? 'Pesanan selesai dan pembayaran ' . ($order['metode_pembayaran'] === 'QRIS' ? 'QRIS' : 'tunai') . ' tercatat ke riwayat transaksi.'
            : ($newStatus === 'Dibatalkan' ? 'Pesanan dibatalkan dan stok dikembalikan.' : 'Pesanan ditandai siap diambil.'));
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof InvalidArgumentException) {
            setFlash('error', $exception->getMessage());
        } elseif ($exception instanceof PDOException) {
            error_log('Online order administration failed: ' . $exception->getMessage());
            setFlash('error', 'Pesanan gagal diperbarui. Periksa migrasi dan struktur tabel database.');
        } else {
            error_log('Unexpected online order administration error: ' . $exception->getMessage());
            setFlash('error', 'Terjadi kesalahan saat memperbarui pesanan.');
        }
    }

    header('Location: pesanan_online.php');
    exit;
}

$pageTitle = 'Pesanan Online';
$activePage = 'online-orders';
$flash = takeFlash();
$error = null;
$orders = [];
try {
    $orders = $pdo->query(
        'SELECT o.id_pesanan, o.nomor_pesanan, o.id_pelanggan, o.status, o.subtotal, o.diskon, o.pajak, o.total,
                o.metode_pembayaran, o.status_pembayaran, o.bukti_pembayaran, o.dibuat_pada,
                p.nama_pelanggan
         FROM tbl_pesanan_online o
         JOIN tbl_pelanggan p ON p.id_pelanggan = o.id_pelanggan
         ORDER BY FIELD(o.status, "Menunggu", "Siap diambil", "Selesai", "Dibatalkan"), o.dibuat_pada DESC
         LIMIT 200'
    )->fetchAll();
    $detailsQuery = $pdo->prepare(
        'SELECT nama_produk, jumlah, harga FROM tbl_detail_pesanan_online WHERE id_pesanan = ?'
    );
} catch (PDOException $exception) {
    $error = databaseError($exception);
}

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Kelola penjualan online</div><h1>Pesanan Online</h1><p>Siapkan pesanan, verifikasi bukti QRIS, lalu selesaikan pesanan setelah pembayaran dan penyerahan barang dipastikan.</p></div>
    <a class="button button-secondary" href="<?= currentRole() === 'cashier' ? 'kasir.php' : 'index.php' ?>">← Kembali ke dashboard</a>
</section>
<?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<section class="panel">
    <div class="panel-header"><div><h2>Daftar pesanan</h2><p class="panel-subtitle">Maksimal 200 pesanan terbaru. Barang dipesan dan stok dicadangkan saat pelanggan checkout.</p></div><span class="badge"><?= count($orders) ?> pesanan</span></div>
    <div class="table-wrap"><table><thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Barang</th><th>Pembayaran</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
        <?php if (!$orders): ?><tr><td class="table-empty" colspan="7"><?= $error ? 'Data tidak tersedia.' : 'Belum ada pesanan.' ?></td></tr>
        <?php else: foreach ($orders as $order): ?>
            <?php $detailsQuery->execute([(int) $order['id_pesanan']]); $details = $detailsQuery->fetchAll(); ?>
            <tr>
                <td><strong><?= h($order['nomor_pesanan']) ?></strong><br><span class="member-city"><?= h($order['dibuat_pada']) ?></span></td>
                <td><?= h($order['nama_pelanggan']) ?></td>
                <td><?= implode('<br>', array_map(static function (array $detail): string {
                    return h($detail['nama_produk']) . ' × ' . (int) $detail['jumlah'];
                }, $details)) ?></td>
                <td><?= h($order['metode_pembayaran']) ?><br><span class="badge <?= in_array($order['status_pembayaran'], ['Ditolak', 'Dibatalkan'], true) ? 'orange' : '' ?>"><?= h($order['status_pembayaran']) ?></span>
                    <?php if ($order['bukti_pembayaran']): ?>
                    <br><a class="small-link" href="payment_proof.php?order_id=<?= (int) $order['id_pesanan'] ?>" target="_blank" rel="noopener">Lihat bukti QRIS</a>
                    <?php if ($order['status_pembayaran'] === 'Menunggu verifikasi'): ?>
                    <div class="online-payment-review">
                        <form method="post" data-confirm="Pastikan nominal dan penerima QRIS sesuai, lalu verifikasi pembayaran?"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id_pesanan'] ?>"><input type="hidden" name="payment_action" value="verify"><button class="button button-primary" type="submit">Verifikasi</button></form>
                        <form method="post" data-confirm="Tolak bukti pembayaran ini? Pelanggan dapat mengunggah bukti baru."><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id_pesanan'] ?>"><input type="hidden" name="payment_action" value="reject"><button class="button button-secondary" type="submit">Tolak bukti</button></form>
                    </div>
                    <?php endif; endif; ?>
                </td>
                <td><strong><?= rupiah($order['total']) ?></strong><br><span class="member-city">Termasuk diskon member & pajak</span></td>
                <td><span class="badge <?= $order['status'] === 'Dibatalkan' ? 'orange' : '' ?>"><?= h($order['status']) ?></span></td>
                <td>
                    <?php if (in_array($order['status'], ['Menunggu', 'Siap diambil'], true)): ?>
                    <div class="online-order-actions">
                        <?php if ($order['status'] === 'Menunggu'): ?>
                        <form method="post" data-confirm="Setujui pesanan dan mulai siapkan barang?"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id_pesanan'] ?>"><input type="hidden" name="status" value="Siap diambil"><button class="button button-primary" type="submit">Acc & siapkan</button></form>
                        <?php else: ?>
                        <form method="post" data-confirm="<?= $order['metode_pembayaran'] === 'QRIS' ? 'Pastikan pembayaran QRIS sudah diverifikasi dan barang telah diserahkan. Selesaikan pesanan?' : 'Pastikan pembayaran tunai sudah diterima. Selesaikan pesanan?' ?>"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id_pesanan'] ?>"><input type="hidden" name="status" value="Selesai"><button class="button button-primary" type="submit" <?= $order['metode_pembayaran'] === 'QRIS' && $order['status_pembayaran'] !== 'Dibayar' ? 'disabled title="Verifikasi pembayaran QRIS terlebih dahulu"' : '' ?>>Selesaikan & catat</button></form>
                        <?php endif; ?>
                        <form method="post" data-confirm="Batalkan pesanan dan kembalikan stok?"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id_pesanan'] ?>"><input type="hidden" name="status" value="Dibatalkan"><button class="button button-secondary" type="submit">Batalkan</button></form>
                    </div>
                    <?php else: ?><span class="member-city">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
