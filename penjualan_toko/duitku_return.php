<?php

require __DIR__ . '/includes/helpers.php';
requireRole(['member']);
require __DIR__ . '/config/database.php';

$merchantOrderId = trim((string) ($_GET['merchantOrderId'] ?? ''));
$order = null;
if ($merchantOrderId !== '') {
    $orderQuery = $pdo->prepare(
        'SELECT o.nomor_pesanan, o.status, o.status_pembayaran, o.subtotal, o.diskon, o.pajak, o.total,
                o.metode_pembayaran, o.duitku_payment_name, o.duitku_payment_url, o.duitku_reference,
                m.card_code, m.diskon_persen
         FROM tbl_pesanan_online o
         JOIN tbl_member m ON m.id_member = o.id_member
         WHERE o.nomor_pesanan = ? AND o.id_member = ?'
    );
    $orderQuery->execute([$merchantOrderId, currentUserId()]);
    $order = $orderQuery->fetch() ?: null;
}

$pageTitle = 'Status Pembayaran Duitku';
$activePage = 'portal';
require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Pembayaran online</div><h1>Status pembayaran</h1><p>Pantau pembayaran dan rincian belanja Anda. Status berhasil diperbarui setelah konfirmasi resmi dari Duitku.</p></div>
    <a class="button button-secondary" href="dashboard_pelanggan.php">← Kembali ke dashboard</a>
</section>
<?php if (!$order): ?>
    <div class="notice error">Pesanan tidak ditemukan pada akun ini.</div>
<?php else: ?>
    <?php
    $paymentPaid = $order['status_pembayaran'] === 'Dibayar';
    $paymentPending = $order['status_pembayaran'] === 'Menunggu pembayaran';
    $paymentStatusClass = $paymentPaid ? 'is-paid' : ($paymentPending ? 'is-pending' : 'is-failed');
    ?>
    <section class="payment-status-layout">
        <div class="payment-status-card <?= $paymentStatusClass ?>">
            <div class="payment-status-glow" aria-hidden="true"></div>
            <div class="payment-status-topline">
                <span class="payment-status-brand"><span class="brand-mark">M</span> MKLOYY</span>
                <span class="payment-status-label">PEMBAYARAN ONLINE</span>
            </div>
            <div class="payment-status-main">
                <span class="payment-status-icon" aria-hidden="true"><?= $paymentPaid ? '✓' : ($paymentPending ? '…' : '!') ?></span>
                <div>
                    <span class="payment-status-caption">STATUS PEMBAYARAN</span>
                    <h2><?= $paymentPaid ? 'Pembayaran berhasil' : ($paymentPending ? 'Menunggu pembayaran' : 'Pembayaran belum berhasil') ?></h2>
                    <p><?= $paymentPaid
                        ? 'Terima kasih! Pesanan Anda sudah dibayar dan siap diproses untuk pengambilan di toko.'
                        : ($paymentPending
                            ? 'Selesaikan pembayaran melalui kanal Duitku. Status akan diperbarui setelah konfirmasi resmi diterima.'
                            : 'Pesanan ini belum dibayar. Periksa kembali status atau hubungi toko jika Anda sudah melakukan pembayaran.') ?></p>
                </div>
            </div>
            <div class="payment-status-amount">
                <span>Total yang dibayarkan</span>
                <strong><?= rupiah($order['total']) ?></strong>
                <small><?= h($order['duitku_payment_name'] ?: $order['metode_pembayaran']) ?></small>
            </div>
        </div>

        <div class="panel payment-detail-card">
            <div class="payment-detail-heading">
                <div><span class="eyebrow">RINGKASAN</span><h2>Detail pesanan</h2></div>
                <span class="badge <?= $paymentPaid ? '' : ($paymentPending ? 'payment-pending-badge' : 'orange') ?>"><?= h($order['status_pembayaran']) ?></span>
            </div>
            <div class="payment-reference-grid">
                <div><span>Nomor pesanan</span><strong><?= h($order['nomor_pesanan']) ?></strong></div>
                <div><span>Referensi Duitku</span><strong><?= h($order['duitku_reference'] ?: 'Belum tersedia') ?></strong></div>
            </div>
            <div class="payment-breakdown">
                <div><span>Subtotal</span><strong><?= rupiah($order['subtotal']) ?></strong></div>
                <div class="payment-member-savings"><span>Diskon kartu member (<?= h(number_format((float) $order['diskon_persen'], 2, ',', '.')) ?>%)</span><strong>− <?= rupiah($order['diskon']) ?></strong></div>
                <div><span>Pajak</span><strong><?= rupiah($order['pajak']) ?></strong></div>
                <div class="payment-breakdown-total"><span>Total pembayaran</span><strong><?= rupiah($order['total']) ?></strong></div>
            </div>
            <div class="payment-member-note">
                <span class="member-discount-icon" aria-hidden="true">M</span>
                <p><strong>Diskon member sudah diterapkan</strong><span>Kartu <?= h($order['card_code']) ?> · hemat <?= rupiah($order['diskon']) ?></span></p>
            </div>
            <div class="payment-status-actions">
                <?php if ($paymentPending && $order['duitku_payment_url']): ?><a class="button button-primary" href="<?= h($order['duitku_payment_url']) ?>" rel="noopener">Lanjutkan pembayaran <span aria-hidden="true">→</span></a><?php endif; ?>
                <a class="button <?= $paymentPending && $order['duitku_payment_url'] ? 'button-secondary' : 'button-primary' ?>" href="belanja_online.php#pesanan">Lihat pesanan</a>
            </div>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
