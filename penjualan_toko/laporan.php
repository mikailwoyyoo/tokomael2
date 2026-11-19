<?php

require __DIR__ . '/includes/helpers.php';
requireLogin();
require __DIR__ . '/config/database.php';

$pageTitle = 'Laporan Penjualan';
$activePage = 'laporan';
$error = null;
$rows = [];
$details = [];

try {
    $rows = $pdo->query(
        'SELECT id_transaksi, tanggal, nama_pelanggan, kota, total_belanja
         FROM view_laporan_transaksi
         ORDER BY tanggal DESC, id_transaksi DESC'
    )->fetchAll();

    $details = $pdo->query(
        'SELECT t.id_transaksi, t.tanggal, p.nama_pelanggan, d.nama_produk,
                d.jumlah, d.harga, (d.jumlah * d.harga) AS subtotal
         FROM tbl_transaksi t
         JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
         JOIN tbl_detail_transaksi d ON d.id_transaksi = t.id_transaksi
         ORDER BY t.tanggal DESC, t.id_transaksi DESC
         LIMIT 50'
    )->fetchAll();
} catch (PDOException $exception) {
    $error = databaseError($exception);
}

$totalSales = array_sum(array_map(static function ($row) {
    return (float) $row['total_belanja'];
}, $rows));

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Analisis penjualan</div><h1>Laporan Penjualan</h1><p>Laporan utama dibaca dari View database; rincian item menggunakan JOIN.</p></div>
    <span class="badge"><?= count($rows) ?> transaksi</span>
</section>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<section class="stats-grid">
    <article class="stat-card"><div class="stat-top"><span>Total penjualan</span><span class="stat-icon">◈</span></div><div class="stat-value"><?= rupiah($totalSales) ?></div><div class="stat-foot">Sumber data: view_laporan_transaksi</div></article>
    <article class="stat-card"><div class="stat-top"><span>Transaksi</span><span class="stat-icon">↔</span></div><div class="stat-value"><?= number_format(count($rows), 0, ',', '.') ?></div><div class="stat-foot">Jumlah baris laporan</div></article>
</section>
<section class="panel section-card">
    <div class="panel-header"><div><h2>View Laporan Transaksi</h2><p class="panel-subtitle">view_laporan_transaksi</p></div><span class="badge">VIEW</span></div>
    <div class="table-wrap">
        <table><thead><tr><th>ID</th><th>Tanggal</th><th>Pelanggan</th><th>Kota</th><th>Total belanja</th><th>Struk</th></tr></thead>
            <tbody><?php if (!$rows): ?><tr><td class="table-empty" colspan="6"><?= $error ? 'Data tidak tersedia.' : 'Belum ada transaksi.' ?></td></tr>
            <?php else: foreach ($rows as $row): ?><tr><td>#<?= (int) $row['id_transaksi'] ?></td><td><?= h($row['tanggal']) ?></td><td><strong><?= h($row['nama_pelanggan']) ?></strong></td><td><?= h($row['kota']) ?></td><td><?= rupiah($row['total_belanja']) ?></td><td><a class="small-link" href="struk.php?id=<?= (int) $row['id_transaksi'] ?>">Lihat / cetak</a></td></tr><?php endforeach; endif; ?></tbody>
        </table>
    </div>
</section>
<section class="panel">
    <div class="panel-header"><div><h2>Detail Transaksi</h2><p class="panel-subtitle">JOIN transaksi, pelanggan, dan detail transaksi (maksimal 50 baris)</p></div><span class="badge orange">JOIN</span></div>
    <div class="table-wrap">
        <table><thead><tr><th>ID</th><th>Tanggal</th><th>Pelanggan</th><th>Produk</th><th>Jumlah</th><th>Harga</th><th>Subtotal</th></tr></thead>
            <tbody><?php if (!$details): ?><tr><td class="table-empty" colspan="7"><?= $error ? 'Data tidak tersedia.' : 'Belum ada detail transaksi.' ?></td></tr>
            <?php else: foreach ($details as $row): ?><tr><td>#<?= (int) $row['id_transaksi'] ?></td><td><?= h($row['tanggal']) ?></td><td><?= h($row['nama_pelanggan']) ?></td><td><?= h($row['nama_produk']) ?></td><td><?= number_format((int) $row['jumlah'], 0, ',', '.') ?></td><td><?= rupiah($row['harga']) ?></td><td><strong><?= rupiah($row['subtotal']) ?></strong></td></tr><?php endforeach; endif; ?></tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
