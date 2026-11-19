<?php

require __DIR__ . '/includes/helpers.php';
requireRole(['member']);
require __DIR__ . '/config/database.php';

$pageTitle = 'Dashboard Pelanggan';
$activePage = 'portal';
$error = null;
$member = null;
$sales = [];
$promos = [];
$products = [];
$onlineOrders = [];
$dashboardCart = [];
$dashboardCartTotal = 0.0;
$points = 0;
$totalDiscount = 0.0;
$lifetimeSpend = 0.0;
$flash = takeFlash();
$tier = ['name' => 'Reguler', 'class' => 'regular', 'next' => 500000, 'perks' => 'Selamat bergabung di program MKLOYY Member.'];

try {
    $memberQuery = $pdo->prepare(
        'SELECT m.id_member, m.card_code, m.rfid_uid, m.diskon_persen,
                p.id_pelanggan, p.nama_pelanggan, p.kota
         FROM tbl_member m
         JOIN tbl_pelanggan p ON p.id_pelanggan = m.id_pelanggan
         WHERE m.id_member = ? AND m.aktif = 1'
    );
    $memberQuery->execute([currentUserId()]);
    $member = $memberQuery->fetch();
    if (!$member) {
        throw new RuntimeException('Kartu member tidak aktif atau tidak ditemukan.');
    }

    $sessionCart = isset($_SESSION['online_cart']) && is_array($_SESSION['online_cart'])
        ? $_SESSION['online_cart']
        : [];
    $cartIds = array_values(array_filter(
        array_map('intval', array_keys($sessionCart)),
        static function (int $id): bool { return $id > 0; }
    ));
    if ($cartIds) {
        $cartPlaceholders = implode(',', array_fill(0, count($cartIds), '?'));
        $cartQuery = $pdo->prepare(
            'SELECT id_produk, nama_produk, harga, stok, gambar
             FROM tbl_produk WHERE id_produk IN (' . $cartPlaceholders . ')'
        );
        $cartQuery->execute($cartIds);
        foreach ($cartQuery->fetchAll() as $cartProduct) {
            $productId = (int) $cartProduct['id_produk'];
            $quantity = filter_var($sessionCart[$productId] ?? null, FILTER_VALIDATE_INT);
            if (!$quantity || $quantity < 1) {
                continue;
            }
            $cartProduct['jumlah'] = $quantity;
            $cartProduct['line_total'] = (float) $cartProduct['harga'] * $quantity;
            $dashboardCartTotal += $cartProduct['line_total'];
            $dashboardCart[] = $cartProduct;
        }
    }

    $salesQuery = $pdo->prepare(
        'SELECT id_transaksi, tanggal, total_belanja, diskon
         FROM tbl_transaksi
         WHERE id_pelanggan = ?
         ORDER BY tanggal DESC, id_transaksi DESC
         LIMIT 100'
    );
    $salesQuery->execute([(int) $member['id_pelanggan']]);
    $sales = $salesQuery->fetchAll();
    $loyaltyQuery = $pdo->prepare(
        'SELECT COALESCE(SUM(FLOOR(total_belanja / 10000)), 0) AS poin,
                COALESCE(SUM(diskon), 0) AS diskon,
                COALESCE(SUM(total_belanja), 0) AS total_belanja
         FROM tbl_transaksi
         WHERE id_pelanggan = ?'
    );
    $loyaltyQuery->execute([(int) $member['id_pelanggan']]);
    $loyalty = $loyaltyQuery->fetch();
    $points = (int) $loyalty['poin'];
    $totalDiscount = (float) $loyalty['diskon'];
    $lifetimeSpend = (float) $loyalty['total_belanja'];

    if ($lifetimeSpend >= 1000000) {
        $tier = ['name' => 'Gold', 'class' => 'gold', 'next' => null, 'perks' => 'Level tertinggi MKLOYY Member. Terima kasih atas loyalitas Anda!'];
    } elseif ($lifetimeSpend >= 500000) {
        $tier = ['name' => 'Silver', 'class' => 'silver', 'next' => 1000000, 'perks' => 'Nikmati status Silver dan lanjutkan belanja untuk meraih Gold.'];
    }

    $promos = $pdo->query(
        'SELECT i.id_iklan, i.judul, i.deskripsi, i.teks_tombol, i.gambar_iklan, i.id_produk,
                p.nama_produk, p.harga, p.gambar
         FROM tbl_iklan_dashboard i
         LEFT JOIN tbl_produk p ON p.id_produk = i.id_produk
         WHERE i.aktif = 1
         ORDER BY i.id_iklan'
    )->fetchAll();
    $products = $pdo->query(
        'SELECT id_produk, nama_produk, harga, stok, gambar
         FROM tbl_produk WHERE stok > 0 ORDER BY nama_produk LIMIT 60'
    )->fetchAll();
    $ordersQuery = $pdo->prepare(
        'SELECT id_pesanan, nomor_pesanan, status, status_pembayaran, total, metode_pembayaran, dibuat_pada
         FROM tbl_pesanan_online
         WHERE id_member = ?
         ORDER BY dibuat_pada DESC
         LIMIT 20'
    );
    $ordersQuery->execute([(int) $member['id_member']]);
    $onlineOrders = $ordersQuery->fetchAll();
} catch (PDOException $exception) {
    $error = databaseError($exception);
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
}

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Area pelanggan</div><h1>Dashboard Pelanggan</h1><p>Lihat kartu member, promo terbaru, dan belanja produk dengan pembayaran QRIS.</p></div>
    <a class="button button-primary" href="belanja_online.php">Mulai belanja →</a>
</section>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($member): ?>
<section class="panel dashboard-cart-panel">
    <div class="panel-header"><div><h2>Keranjang belanja</h2><p class="panel-subtitle"><?= count($dashboardCart) ?> jenis produk menunggu di keranjang Anda</p></div><a class="button button-secondary" href="belanja_online.php">Kelola keranjang</a></div>
    <?php if (!$dashboardCart): ?>
        <div class="dashboard-cart-empty"><span>🛒</span><div><strong>Keranjang Anda masih kosong</strong><p>Tambahkan produk dari katalog untuk mulai memesan.</p></div><a class="small-link" href="#katalog">Lihat katalog →</a></div>
    <?php else: ?>
        <div class="dashboard-cart-preview">
            <?php foreach (array_slice($dashboardCart, 0, 4) as $cartProduct): ?>
                <div class="dashboard-cart-item">
                    <?php if ($cartProduct['gambar']): ?><img src="<?= h($cartProduct['gambar']) ?>" alt="" loading="lazy"><?php else: ?><span class="dashboard-cart-thumb">M</span><?php endif; ?>
                    <div><strong><?= h($cartProduct['nama_produk']) ?></strong><small><?= (int) $cartProduct['jumlah'] ?> × <?= rupiah($cartProduct['harga']) ?></small></div>
                    <strong><?= rupiah($cartProduct['line_total']) ?></strong>
                </div>
            <?php endforeach; ?>
            <?php if (count($dashboardCart) > 4): ?><a class="small-link dashboard-cart-more" href="belanja_online.php">+ <?= count($dashboardCart) - 4 ?> produk lainnya</a><?php endif; ?>
            <div class="dashboard-cart-total"><span>Subtotal sementara</span><strong><?= rupiah($dashboardCartTotal) ?></strong><a class="button button-primary" href="belanja_online.php">Lanjutkan pesanan →</a></div>
        </div>
    <?php endif; ?>
</section>
<section class="member-portal-grid">
    <article class="member-digital-card tier-<?= h($tier['class']) ?>">
        <div class="member-card-orbit" aria-hidden="true"></div>
        <div class="member-card-brand"><span class="member-card-logo">M</span><strong>MKLOYY <small>MEMBER CARD</small></strong><span class="member-card-status">● AKTIF</span></div>
        <div class="member-card-level"><span>MEMBER LEVEL</span><strong><?= h(strtoupper($tier['name'])) ?></strong></div>
        <div class="member-card-main">
            <div>
                <h2><?= h($member['nama_pelanggan']) ?></h2>
                <p><?= h($member['kota']) ?></p>
                <div class="member-card-id-label">MEMBER ID</div>
                <div class="member-card-code"><?= h($member['card_code']) ?></div>
            </div>
            <div class="member-card-qr-wrap"><div class="member-card-qr"><?= locationQrSvg($member['card_code']) ?></div><small>SCAN MEMBER</small></div>
        </div>
        <div class="member-card-bottom">
            <div class="member-card-identifiers">
                <div><small>UID RFID</small><strong><?= h($member['rfid_uid'] ?: 'Belum ditautkan') ?></strong></div>
                <div><small>Diskon member</small><strong><?= h(number_format((float) $member['diskon_persen'], 2, ',', '.')) ?>%</strong></div>
                <div><small>Total belanja</small><strong><?= rupiah($lifetimeSpend) ?></strong></div>
            </div>
            <div class="member-card-barcode"><?= code128Svg($member['card_code']) ?></div>
        </div>
    </article>
    <div class="member-portal-stats">
        <article class="stat-card member-tier-stat"><div class="stat-top"><span>Level <?= h($tier['name']) ?></span><span class="stat-icon">✦</span></div><div class="stat-value"><?= h($tier['name']) ?></div><div class="stat-foot"><?= h($tier['perks']) ?></div>
            <?php if ($tier['next'] !== null): ?><div class="tier-progress"><span style="width:<?= (int) min(100, ($lifetimeSpend / $tier['next']) * 100) ?>%"></span></div><div class="stat-foot"><?= rupiah(max(0, $tier['next'] - $lifetimeSpend)) ?> lagi menuju level berikutnya</div><?php endif; ?>
        </article>
        <article class="stat-card"><div class="stat-top"><span>Total poin</span><span class="stat-icon">✦</span></div><div class="stat-value"><?= number_format($points, 0, ',', '.') ?></div><div class="stat-foot">1 poin untuk setiap Rp10.000 total transaksi</div></article>
        <article class="stat-card"><div class="stat-top"><span>Diskon terkumpul</span><span class="stat-icon">%</span></div><div class="stat-value"><?= rupiah($totalDiscount) ?></div><div class="stat-foot">Akumulasi diskon pada riwayat belanja</div></article>
    </div>
</section>
<section class="member-slider-section">
    <div class="section-heading-row"><div><div class="eyebrow">Promo pilihan</div><h2>Temukan promo untuk Anda</h2></div>
        <?php if ($promos): ?>
        <div class="slider-controls"><button type="button" aria-label="Iklan sebelumnya" data-slide-prev>←</button><button type="button" aria-label="Iklan berikutnya" data-slide-next>→</button></div>
        <?php endif; ?>
    </div>
    <?php if (!$promos): ?><div class="panel panel-body">Belum ada promo aktif saat ini.</div>
    <?php else: ?>
    <div class="member-ad-slider" data-ad-slider>
        <?php foreach ($promos as $index => $promo): $promoImage = $promo['gambar_iklan'] ?: ($promo['gambar'] ?? null); ?>
        <article class="member-ad-slide" data-ad-slide>
            <div class="member-ad-copy"><span class="promo-kicker">PROMO MKLOYY · <?= sprintf('%02d', $index + 1) ?></span><h2><?= h($promo['judul']) ?></h2><p><?= h($promo['deskripsi']) ?></p>
                <?php if ($promo['nama_produk']): ?><div class="member-ad-product"><strong><?= h($promo['nama_produk']) ?></strong><span><?= rupiah($promo['harga']) ?></span></div><?php endif; ?>
                <?php if ($promo['id_produk']): ?><a class="promo-button" href="belanja_online.php?produk=<?= (int) $promo['id_produk'] ?>"><?= h($promo['teks_tombol']) ?> →</a><?php else: ?><a class="promo-button" href="belanja_online.php"><?= h($promo['teks_tombol']) ?> →</a><?php endif; ?>
            </div>
            <div class="member-ad-visual">
                <?php if ($promoImage): ?><img src="<?= h($promoImage) ?>" alt="<?= h($promo['judul']) ?>" loading="lazy"><?php else: ?><div class="promo-placeholder"><strong>MKLOYY</strong><span>SHOP WITH JOY</span></div><?php endif; ?>
                <?php if ($promo['nama_produk']): ?><div class="promo-product-label"><strong><?= h($promo['nama_produk']) ?></strong><span><?= rupiah($promo['harga']) ?></span></div><?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <div class="slider-dots" data-slide-dots>
        <?php foreach ($promos as $index => $promo): ?><button type="button" aria-label="Tampilkan slide <?= $index + 1 ?>" data-slide-dot="<?= $index ?>" class="<?= $index === 0 ? 'active' : '' ?>"></button><?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<section class="panel section-card" id="katalog">
    <div class="panel-header"><div><h2>Katalog produk</h2><p class="panel-subtitle">Pesan sekarang, bayar dengan QRIS dan ambil barang di toko.</p></div><a class="small-link" href="belanja_online.php">Lihat semua produk →</a></div>
    <?php if (!$products): ?><div class="panel-body"><p class="panel-subtitle">Belum ada produk dengan stok tersedia.</p></div>
    <?php else: ?><div class="online-product-grid">
        <?php foreach (array_slice($products, 0, 8) as $product): ?>
        <article class="online-product-card">
            <?php if ($product['gambar']): ?><img src="<?= h($product['gambar']) ?>" alt="<?= h($product['nama_produk']) ?>" loading="lazy"><?php else: ?><div class="online-product-placeholder">MKLOYY</div><?php endif; ?>
            <div class="online-product-info"><h3><?= h($product['nama_produk']) ?></h3><strong><?= rupiah($product['harga']) ?></strong><small>Stok <?= (int) $product['stok'] ?></small>
                <form method="post" action="belanja_online.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="return_to" value="dashboard"><input type="hidden" name="product_id" value="<?= (int) $product['id_produk'] ?>">
                    <button class="button button-primary" type="submit">＋ Keranjang</button>
                </form>
            </div>
        </article>
        <?php endforeach; ?>
    </div><?php endif; ?>
</section>
<section class="panel section-card">
    <div class="panel-header"><div><h2>Pesanan online</h2><p class="panel-subtitle">Pantau status pesanan ambil-di-toko Anda.</p></div><a class="small-link" href="belanja_online.php#pesanan">Belanja online →</a></div>
    <div class="table-wrap"><table><thead><tr><th>No. pesanan</th><th>Tanggal</th><th>Status</th><th>Pembayaran</th><th>Total</th></tr></thead><tbody>
        <?php if (!$onlineOrders): ?><tr><td class="table-empty" colspan="5">Belum ada pesanan online.</td></tr>
        <?php else: foreach ($onlineOrders as $order): ?><tr><td><strong><?= h($order['nomor_pesanan']) ?></strong></td><td><?= h($order['dibuat_pada']) ?></td><td><span class="badge <?= $order['status'] === 'Dibatalkan' ? 'orange' : '' ?>"><?= h($order['status']) ?></span></td><td><?= h($order['metode_pembayaran']) ?><br><span class="member-city"><?= h($order['status_pembayaran']) ?></span></td><td><?= rupiah($order['total']) ?></td></tr><?php endforeach; endif; ?>
    </tbody></table></div>
</section>
<section class="panel">
    <div class="panel-header"><div><h2>Riwayat belanja</h2><p class="panel-subtitle">Transaksi milik akun Anda (maksimal 100 terakhir).</p></div><span class="badge"><?= count($sales) ?> transaksi</span></div>
    <div class="table-wrap"><table><thead><tr><th>No. transaksi</th><th>Tanggal</th><th>Total</th><th>Diskon</th></tr></thead><tbody>
        <?php if (!$sales): ?><tr><td class="table-empty" colspan="4">Belum ada transaksi.</td></tr>
        <?php else: foreach ($sales as $sale): ?>
            <tr><td>#<?= (int) $sale['id_transaksi'] ?></td><td><?= h($sale['tanggal']) ?></td><td><strong><?= rupiah($sale['total_belanja']) ?></strong></td><td><?= rupiah($sale['diskon']) ?></td></tr>
        <?php endforeach; endif; ?>
    </tbody></table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
