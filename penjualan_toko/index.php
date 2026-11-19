<?php

require __DIR__ . '/includes/helpers.php';
requireLogin();
require __DIR__ . '/config/database.php';

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$error = null;
$stats = ['sales' => 0, 'transactions' => 0, 'customers' => 0, 'products' => 0];
$recent = [];
$monthlySales = [];
$dashboardAd = null;
$stockAlerts = [];
$emptyProductCount = 0;
$lowStockProductCount = 0;

try {
    $stats['sales'] = (float) $pdo->query('SELECT COALESCE(SUM(total_belanja), 0) FROM tbl_transaksi')->fetchColumn();
    $stats['transactions'] = (int) $pdo->query('SELECT COUNT(*) FROM tbl_transaksi')->fetchColumn();
    $stats['customers'] = (int) $pdo->query('SELECT COUNT(*) FROM tbl_pelanggan')->fetchColumn();
    $stats['products'] = (int) $pdo->query('SELECT COUNT(*) FROM tbl_produk')->fetchColumn();
    $emptyProductCount = (int) $pdo->query('SELECT COUNT(*) FROM tbl_produk WHERE stok = 0')->fetchColumn();
    $lowStockProductCount = (int) $pdo->query('SELECT COUNT(*) FROM tbl_produk WHERE stok BETWEEN 1 AND 5')->fetchColumn();
    $stockAlerts = $pdo->query(
        'SELECT id_produk, nama_produk, stok
         FROM tbl_produk
         WHERE stok <= 5
         ORDER BY stok ASC, nama_produk
         LIMIT 20'
    )->fetchAll();

    $dashboardAd = $pdo->query(
        'SELECT i.judul, i.deskripsi, i.teks_tombol, i.gambar_iklan,
                p.id_produk, p.nama_produk, p.harga, p.gambar
         FROM tbl_iklan_dashboard i
         LEFT JOIN tbl_produk p ON p.id_produk = i.id_produk
         WHERE i.id_iklan = 1 AND i.aktif = 1'
    )->fetch() ?: null;

    $recent = $pdo->query(
        'SELECT id_transaksi, tanggal, nama_pelanggan, kota, total_belanja
         FROM view_laporan_transaksi
         ORDER BY tanggal DESC, id_transaksi DESC
         LIMIT 6'
    )->fetchAll();

    $monthlyRows = $pdo->query(
        'SELECT DATE_FORMAT(tanggal, "%Y-%m") AS bulan, SUM(total_belanja) AS total
         FROM tbl_transaksi
         WHERE tanggal >= DATE_SUB(CURRENT_DATE(), INTERVAL 5 MONTH)
         GROUP BY DATE_FORMAT(tanggal, "%Y-%m")
         ORDER BY bulan'
    )->fetchAll();
    foreach ($monthlyRows as $row) {
        $monthlySales[$row['bulan']] = (float) $row['total'];
    }
} catch (PDOException $exception) {
    $error = databaseError($exception);
}

$months = [];
for ($offset = 5; $offset >= 0; $offset--) {
    $date = new DateTimeImmutable('first day of this month');
    $date = $date->modify('-' . $offset . ' months');
    $key = $date->format('Y-m');
    $months[] = [
        'label' => $date->format('M'),
        'sales' => $monthlySales[$key] ?? 0,
    ];
}
$maxSales = max(array_merge([1], array_column($months, 'sales')));

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div>
        <div class="eyebrow">Ringkasan toko</div>
        <h1>Selamat datang, <?= h($_SESSION['admin_name']) ?></h1>
        <p>Berikut ringkasan aktivitas penjualan toko Anda.</p>
    </div>
    <div class="page-heading-actions">
        <a class="button button-secondary" href="admin.php">Kelola iklan</a>
        <a class="button button-primary" href="transaksi.php">＋ Transaksi Baru</a>
    </div>
</section>

<?php if ($error): ?>
    <div class="notice error"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($dashboardAd): ?>
    <section class="dashboard-promo" aria-label="Iklan produk">
        <div class="dashboard-promo-copy">
            <span class="promo-kicker">PROMO MKLOYY</span>
            <h2><?= h($dashboardAd['judul']) ?></h2>
            <p><?= h($dashboardAd['deskripsi']) ?></p>
            <?php if (!empty($dashboardAd['nama_produk'])): ?>
                <div class="dashboard-promo-product">
                    <strong><?= h($dashboardAd['nama_produk']) ?></strong>
                    <span><?= h(rupiah($dashboardAd['harga'])) ?></span>
                </div>
            <?php endif; ?>
            <a class="promo-button" href="<?= !empty($dashboardAd['id_produk']) ? 'transaksi.php' : 'produk.php' ?>"><?= h($dashboardAd['teks_tombol']) ?> <span aria-hidden="true">→</span></a>
        </div>
        <div class="dashboard-promo-visual">
            <?php if (!empty($dashboardAd['gambar_iklan']) || !empty($dashboardAd['gambar'])): ?>
                <img src="<?= h($dashboardAd['gambar_iklan'] ?: $dashboardAd['gambar']) ?>" alt="<?= h($dashboardAd['nama_produk'] ?? 'Produk promosi') ?>">
            <?php else: ?>
                <div class="promo-placeholder" aria-hidden="true"><span>MKLOYY</span><strong>Belanja pilihan<br>hari ini</strong></div>
            <?php endif; ?>
            <?php if (!empty($dashboardAd['nama_produk'])): ?><div class="promo-product-label"><strong><?= h($dashboardAd['nama_produk']) ?></strong><span><?= h(rupiah($dashboardAd['harga'])) ?></span></div><?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="stats-grid" aria-label="Statistik ringkasan">
    <article class="stat-card">
        <div class="stat-top"><span>Total Penjualan</span><span class="stat-icon">◈</span></div>
        <div class="stat-value"><?= rupiah($stats['sales']) ?></div>
        <div class="stat-foot">Akumulasi seluruh transaksi</div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span>Total Transaksi</span><span class="stat-icon" style="color:#4775e8;background:#eef2ff">↔</span></div>
        <div class="stat-value"><?= number_format($stats['transactions'], 0, ',', '.') ?></div>
        <div class="stat-foot">Transaksi yang tercatat</div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span>Pelanggan</span><span class="stat-icon" style="color:#159b78;background:#e8f7f2">♙</span></div>
        <div class="stat-value"><?= number_format($stats['customers'], 0, ',', '.') ?></div>
        <div class="stat-foot">Pelanggan terdaftar</div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span>Produk</span><span class="stat-icon" style="color:#8464d8;background:#f2effc">▣</span></div>
        <div class="stat-value"><?= number_format($stats['products'], 0, ',', '.') ?></div>
        <div class="stat-foot">Produk dalam katalog</div>
    </article>
</section>

<section class="panel section-card stock-alert-panel">
    <div class="panel-header">
        <div><h2>Stok kosong & hampir habis</h2><p class="panel-subtitle">Peringatan produk dengan stok 5 unit atau kurang.</p></div>
        <div class="stock-alert-counts"><span class="stock-count-empty"><?= $emptyProductCount ?> kosong</span><span class="stock-count-low"><?= $lowStockProductCount ?> hampir habis</span></div>
    </div>
    <div class="table-wrap">
        <table><thead><tr><th>Produk</th><th>Sisa stok</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
            <?php if (!$stockAlerts): ?><tr><td class="table-empty" colspan="4">Semua stok produk masih aman.</td></tr>
            <?php else: foreach ($stockAlerts as $stockProduct): ?>
                <tr>
                    <td><strong><?= h($stockProduct['nama_produk']) ?></strong></td>
                    <td><?= (int) $stockProduct['stok'] ?></td>
                    <td><span class="badge <?= (int) $stockProduct['stok'] === 0 ? 'stock-empty-badge' : 'orange' ?>"><?= (int) $stockProduct['stok'] === 0 ? 'Kosong' : 'Hampir habis' ?></span></td>
                    <td><a class="small-link" href="produk.php">Kelola stok →</a></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody></table>
    </div>
</section>

<section class="dashboard-grid">
    <article class="panel">
        <div class="panel-header">
            <div>
                <h2>Tren Penjualan</h2>
                <p class="panel-subtitle">Ringkasan enam bulan terakhir</p>
            </div>
            <div class="chart-legend"><span class="legend-item"><i class="legend-dot"></i>Penjualan</span></div>
        </div>
        <div class="panel-body">
            <?php if (!$error): ?>
                <div class="chart-area" aria-label="Grafik penjualan enam bulan">
                    <?php foreach ($months as $month): ?>
                        <div class="chart-column" title="<?= h($month['label']) ?>: <?= h(rupiah($month['sales'])) ?>">
                            <div class="chart-bar sales" style="height:<?= max(4, (int) round($month['sales'] / $maxSales * 88)) ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-labels">
                    <?php foreach ($months as $month): ?><span><?= h($month['label']) ?></span><?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="table-empty">Grafik belum tersedia.</div>
            <?php endif; ?>
        </div>
    </article>
    <article class="panel">
        <div class="panel-header"><div><h2>Informasi Toko</h2><p class="panel-subtitle">Data operasional saat ini</p></div></div>
        <div class="panel-body">
            <div class="info-metrics">
                <div class="info-metric"><span>Pelanggan</span><strong><?= number_format($stats['customers'], 0, ',', '.') ?></strong></div>
                <div class="info-metric"><span>Transaksi</span><strong><?= number_format($stats['transactions'], 0, ',', '.') ?></strong></div>
                <div class="info-metric"><span>Produk</span><strong><?= number_format($stats['products'], 0, ',', '.') ?></strong></div>
            </div>
            <div class="notice info" style="margin:16px 0 0">Semua kebutuhan penjualan harian tersedia langsung dari menu Kasir POS.</div>
            <a class="small-link" href="transaksi.php">Buka kasir POS →</a>
        </div>
    </article>
</section>

<section class="panel">
    <div class="panel-header">
        <div><h2>Transaksi Terbaru</h2><p class="panel-subtitle">Aktivitas penjualan terkini</p></div>
        <a class="small-link" href="laporan.php">Lihat laporan →</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Tanggal</th><th>Pelanggan</th><th>Kota</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?>
                <tr><td class="table-empty" colspan="6"><?= $error ? 'Data tidak tersedia.' : 'Belum ada transaksi.' ?></td></tr>
            <?php else: foreach ($recent as $row): ?>
                <tr>
                    <td><strong>#<?= (int) $row['id_transaksi'] ?></strong></td>
                    <td><?= h(date('d M Y', strtotime($row['tanggal']))) ?></td>
                    <td><?= h($row['nama_pelanggan']) ?></td>
                    <td><?= h($row['kota']) ?></td>
                    <td><strong><?= rupiah($row['total_belanja']) ?></strong></td>
                    <td><span class="badge">Selesai</span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
