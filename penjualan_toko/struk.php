<?php

require __DIR__ . '/includes/helpers.php';
requireRole(['admin', 'cashier']);
require __DIR__ . '/config/database.php';

$transactionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$transactionId || $transactionId < 1) {
    http_response_code(400);
    exit('Nomor transaksi tidak valid.');
}

$saleQuery = $pdo->prepare(
    'SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.subtotal, t.diskon, t.pajak,
            t.uang_dibayar, t.kembalian, t.metode_pembayaran,
            p.nama_pelanggan, p.kota, m.card_code, m.rfid_uid, t.id_kasir
     FROM tbl_transaksi t
     JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     LEFT JOIN tbl_member m ON m.id_member = t.id_member
     WHERE t.id_transaksi = ?'
);
$saleQuery->execute([$transactionId]);
$sale = $saleQuery->fetch();

if (!$sale) {
    http_response_code(404);
    exit('Transaksi tidak ditemukan.');
}
if (currentRole() === 'cashier' && (int) $sale['id_kasir'] !== currentUserId()) {
    http_response_code(403);
    exit('Kasir hanya dapat membuka struk transaksi miliknya sendiri.');
}

$detailQuery = $pdo->prepare(
    'SELECT nama_produk, jumlah, harga, (jumlah * harga) AS subtotal
     FROM tbl_detail_transaksi
     WHERE id_transaksi = ?
     ORDER BY id_detail'
);
$detailQuery->execute([$transactionId]);
$items = $detailQuery->fetchAll();

if ($sale['subtotal'] === null) {
    $sale['subtotal'] = array_sum(array_map(static function ($item) {
        return (float) $item['subtotal'];
    }, $items));
}
if ($sale['uang_dibayar'] === null) {
    $sale['uang_dibayar'] = $sale['total_belanja'];
}

$locationBarcode = 'MKLOYY-SLAHUNG';
$transactionBarcode = 'TRX-' . str_pad((string) $sale['id_transaksi'], 8, '0', STR_PAD_LEFT);
$pageTitle = 'Struk #' . $transactionId;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | MKLOYY</title>
    <link rel="stylesheet" href="assets/css/style.css?v=ui-polish-receipt-20261119">
</head>
<body class="receipt-page">
    <div class="receipt-actions">
        <a class="button button-secondary" href="<?= currentRole() === 'cashier' ? 'kasir.php' : 'transaksi.php' ?>">← Kembali ke kasir</a>
        <span class="receipt-size-note">Kertas thermal · lebar 80 mm</span>
        <button class="button button-primary" type="button" data-print-receipt>Cetak struk</button>
    </div>
    <p class="receipt-print-help">Gunakan kertas thermal gulung 80 mm. Di pengaturan cetak pilih <strong>Portrait</strong>, margin <strong>None</strong>, skala <strong>100%</strong>, dan aktifkan grafik latar bila tersedia.</p>
    <main class="receipt">
        <svg class="receipt-batik" viewBox="0 0 360 28" preserveAspectRatio="none" aria-hidden="true">
            <defs><pattern id="receipt-batik-top" width="36" height="28" patternUnits="userSpaceOnUse">
                <g fill="none" stroke="#111" stroke-width="1">
                    <path d="M18 2C21 6 21 9 18 12C15 9 15 6 18 2ZM18 16C21 19 21 22 18 26C15 22 15 19 18 16ZM2 14C6 11 9 11 12 14C9 17 6 17 2 14ZM24 14C27 11 30 11 34 14C30 17 27 17 24 14Z"/>
                    <circle cx="18" cy="14" r="2"/>
                    <path d="M0 2v3m0 18v3m36-24v3m0 18v3"/>
                </g>
            </pattern></defs>
            <rect width="360" height="28" fill="url(#receipt-batik-top)"/>
        </svg>
        <header class="receipt-brand">
            <svg class="receipt-logo" viewBox="8 6 48 52" role="img" aria-label="Logo MKLOYY">
                <path d="M14 25h36l-4 30H18l-4-30Z" fill="none" stroke="#172b4d" stroke-width="4" stroke-linejoin="round"/>
                <path d="M22 27v-7a10 10 0 0 1 20 0v7" fill="none" stroke="#172b4d" stroke-width="4" stroke-linecap="round"/>
                <path d="M25 38h14M32 31v14" fill="none" stroke="#172b4d" stroke-width="4" stroke-linecap="round"/>
            </svg>
            <div class="receipt-brand-copy">
                <h1>MKLOYY</h1>
                <p class="receipt-tagline">Belanja mudah, harga bersahabat</p>
            </div>
        </header>
        <address class="receipt-address">Jl. Ponorogo-Pacitan, Kec. Slahung, Kab. Ponorogo</address>
        <div class="receipt-barcode-block receipt-location-block">
            <span class="receipt-barcode-label">QR CODE LOKASI TOKO</span>
            <?= locationQrSvg($locationBarcode) ?>
            <span class="receipt-location-code"><?= h($locationBarcode) ?></span>
        </div>
        <div class="receipt-thank-you">TERIMA KASIH TELAH BERBELANJA</div>
        <div class="receipt-divider"></div>
        <div class="receipt-meta">
            <div><span>No.</span><strong>#<?= (int) $sale['id_transaksi'] ?></strong></div>
            <div><span>Tanggal</span><strong><?= h(date('d/m/Y H:i', strtotime($sale['tanggal']))) ?></strong></div>
            <div class="receipt-customer"><span>Pelanggan</span><strong><?= h($sale['nama_pelanggan']) ?><?= !empty($sale['kota']) ? ' · ' . h($sale['kota']) : '' ?></strong></div>
            <?php if (!empty($sale['card_code'])): ?><div><span>Member</span><strong><?= h($sale['card_code']) ?></strong></div><?php endif; ?>
            <?php if (!empty($sale['rfid_uid'])): ?><div><span>UID RFID</span><strong><?= h($sale['rfid_uid']) ?></strong></div><?php endif; ?>
        </div>
        <div class="receipt-divider"></div>
        <table class="receipt-items">
            <thead><tr><th>PRODUK</th><th>QTY</th><th>JUMLAH</th></tr></thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="receipt-item-name"><?= h($item['nama_produk']) ?><small><?= rupiah($item['harga']) ?> / item</small></td>
                        <td><?= number_format((int) $item['jumlah'], 0, ',', '.') ?></td>
                        <td><?= rupiah($item['subtotal']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="receipt-divider"></div>
        <div class="receipt-totals">
            <div><span>Subtotal</span><strong><?= rupiah($sale['subtotal']) ?></strong></div>
            <?php if ((float) $sale['diskon'] > 0): ?>
                <div><span>Diskon</span><strong>− <?= rupiah($sale['diskon']) ?></strong></div>
            <?php endif; ?>
            <?php if ((float) $sale['pajak'] > 0): ?>
                <div><span>Pajak</span><strong><?= rupiah($sale['pajak']) ?></strong></div>
            <?php endif; ?>
            <div class="receipt-grand-total"><span>Total</span><strong><?= rupiah($sale['total_belanja']) ?></strong></div>
            <div class="receipt-payment"><span>Metode bayar</span><strong><?= h($sale['metode_pembayaran']) ?></strong></div>
            <div><span>Dibayar</span><strong><?= rupiah($sale['uang_dibayar']) ?></strong></div>
            <?php if ((float) $sale['kembalian'] > 0): ?>
                <div><span>Kembalian</span><strong><?= rupiah($sale['kembalian']) ?></strong></div>
            <?php endif; ?>
        </div>
        <div class="receipt-barcode-block receipt-transaction-barcode">
            <span class="receipt-barcode-label">BARCODE TRANSAKSI</span>
            <?= code128Svg($transactionBarcode) ?>
        </div>
        <div class="receipt-thanks"><strong>Semoga hari Anda menyenangkan!</strong><span>Simpan struk ini sebagai bukti pembayaran.</span></div>
        <div class="receipt-footer">MKLOYY · <?= h(date('d/m/Y H:i', strtotime($sale['tanggal']))) ?></div>
        <svg class="receipt-batik receipt-batik-bottom" viewBox="0 0 360 28" preserveAspectRatio="none" aria-hidden="true">
            <defs><pattern id="receipt-batik-bottom-pattern" width="36" height="28" patternUnits="userSpaceOnUse">
                <g fill="none" stroke="#111" stroke-width="1">
                    <path d="M18 2C21 6 21 9 18 12C15 9 15 6 18 2ZM18 16C21 19 21 22 18 26C15 22 15 19 18 16ZM2 14C6 11 9 11 12 14C9 17 6 17 2 14ZM24 14C27 11 30 11 34 14C30 17 27 17 24 14Z"/>
                    <circle cx="18" cy="14" r="2"/>
                    <path d="M0 2v3m0 18v3m36-24v3m0 18v3"/>
                </g>
            </pattern></defs>
            <rect width="360" height="28" fill="url(#receipt-batik-bottom-pattern)"/>
        </svg>
    </main>
    <script>
        (function () {
            var printStyle = document.createElement('style');
            printStyle.id = 'receipt-print-page-size';
            document.head.appendChild(printStyle);

            function configureReceiptPaper() {
                var receipt = document.querySelector('.receipt');
                var height = Math.ceil((receipt.scrollHeight * 25.4 / 96) + 2);
                printStyle.textContent = '@page { size: 80mm ' + height + 'mm; margin: 0; }';
            }

            window.addEventListener('beforeprint', configureReceiptPaper);
            document.querySelector('[data-print-receipt]').addEventListener('click', function () {
                window.print();
            });
        }());
    </script>
</body>
</html>
