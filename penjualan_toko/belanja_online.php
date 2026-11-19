<?php

require __DIR__ . '/includes/helpers.php';
requireRole(['member']);
require __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/duitku.php';

$memberQuery = $pdo->prepare(
    'SELECT m.id_member, m.id_pelanggan, m.card_code, m.diskon_persen, p.nama_pelanggan
     FROM tbl_member m
     JOIN tbl_pelanggan p ON p.id_pelanggan = m.id_pelanggan
     WHERE m.id_member = ? AND m.aktif = 1'
);
$memberQuery->execute([currentUserId()]);
$member = $memberQuery->fetch();
if (!$member) {
    http_response_code(403);
    exit('Akun member tidak aktif.');
}

if (!isset($_SESSION['online_cart']) || !is_array($_SESSION['online_cart'])) {
    $_SESSION['online_cart'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnToDashboard = ($_POST['return_to'] ?? '') === 'dashboard'
        && (($_POST['action'] ?? '') === 'add');
    $newProof = null;
    $oldProof = null;
    $gatewayRedirect = null;
    $createdOrderId = null;
    $invoiceCreated = false;
    try {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);

        if ($action === 'upload_payment_proof') {
            $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$orderId || $orderId < 1) {
                throw new InvalidArgumentException('Pesanan tidak valid.');
            }

            $pdo->beginTransaction();
            $orderQuery = $pdo->prepare(
                'SELECT id_pesanan, metode_pembayaran, status, status_pembayaran, bukti_pembayaran
                 FROM tbl_pesanan_online
                 WHERE id_pesanan = ? AND id_member = ? FOR UPDATE'
            );
            $orderQuery->execute([$orderId, (int) $member['id_member']]);
            $order = $orderQuery->fetch();
            if (!$order || $order['metode_pembayaran'] !== 'QRIS'
                || in_array($order['status'], ['Selesai', 'Dibatalkan'], true)
                || !in_array($order['status_pembayaran'], ['Menunggu pembayaran', 'Ditolak'], true)) {
                throw new InvalidArgumentException('Pesanan ini tidak dapat menerima bukti pembayaran baru.');
            }

            $newProof = storeUploadedImage($_FILES['payment_proof'] ?? [], 'qris_proofs');
            $oldProof = is_string($order['bukti_pembayaran']) ? $order['bukti_pembayaran'] : null;
            $saveProof = $pdo->prepare(
                "UPDATE tbl_pesanan_online
                 SET bukti_pembayaran = ?, status_pembayaran = 'Menunggu verifikasi'
                 WHERE id_pesanan = ?"
            );
            $saveProof->execute([$newProof, $orderId]);
            $pdo->commit();
            removeUploadedImage($oldProof, 'qris_proofs');
            $newProof = null;
            setFlash('success', 'Bukti pembayaran terkirim. Pesanan akan diproses setelah QRIS diverifikasi Admin/Kasir.');
        } elseif ($action === 'add' || $action === 'update') {
            $quantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT);
            if (!$productId || $productId < 1 || !$quantity || $quantity < 1 || $quantity > 99) {
                throw new InvalidArgumentException('Pilih produk dan jumlah antara 1 sampai 99.');
            }
            $productQuery = $pdo->prepare('SELECT stok FROM tbl_produk WHERE id_produk = ?');
            $productQuery->execute([$productId]);
            $stock = $productQuery->fetchColumn();
            if ($stock === false || (int) $stock < 1) {
                throw new InvalidArgumentException('Produk tidak tersedia atau stok sudah habis.');
            }
            $currentQuantity = $action === 'add' ? (int) ($_SESSION['online_cart'][$productId] ?? 0) : 0;
            if ($quantity + $currentQuantity > (int) $stock) {
                throw new InvalidArgumentException('Jumlah melebihi stok yang tersedia.');
            }
            $_SESSION['online_cart'][$productId] = $quantity + $currentQuantity;
            setFlash('success', 'Produk masuk ke keranjang.');
        } elseif ($action === 'remove') {
            if (!$productId || !isset($_SESSION['online_cart'][$productId])) {
                throw new InvalidArgumentException('Produk tidak ditemukan di keranjang.');
            }
            unset($_SESSION['online_cart'][$productId]);
            setFlash('success', 'Produk dihapus dari keranjang.');
        } elseif ($action === 'checkout') {
            $cart = $_SESSION['online_cart'];
            if (!$cart) {
                throw new InvalidArgumentException('Keranjang masih kosong.');
            }
            $selectedPaymentMethod = strtoupper(trim((string) ($_POST['payment_method'] ?? '')));
            if (preg_match('/^[A-Z0-9]{2}$/D', $selectedPaymentMethod) !== 1) {
                throw new InvalidArgumentException('Pilih metode pembayaran non-tunai yang tersedia.');
            }
            assertDuitkuConfigured();
            $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
            $customerPhone = preg_replace('/[^0-9+]/', '', (string) ($_POST['customer_phone'] ?? ''));
            if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Masukkan alamat email yang valid untuk pembayaran Duitku.');
            }
            if (strlen($customerPhone) > 32) {
                throw new InvalidArgumentException('Nomor telepon terlalu panjang.');
            }
            $_SESSION['online_checkout_email'] = $customerEmail;
            $_SESSION['online_checkout_phone'] = $customerPhone;
            $_SESSION['online_payment_method'] = $selectedPaymentMethod;
            $productIds = array_map('intval', array_keys($cart));
            sort($productIds, SORT_NUMERIC);
            $pdo->beginTransaction();
            $lockedMemberQuery = $pdo->prepare(
                'SELECT id_member, id_pelanggan, diskon_persen FROM tbl_member
                 WHERE id_member = ? AND aktif = 1 FOR UPDATE'
            );
            $lockedMemberQuery->execute([(int) $member['id_member']]);
            $lockedMember = $lockedMemberQuery->fetch();
            if (!$lockedMember) {
                throw new InvalidArgumentException('Kartu member tidak aktif. Pesanan tidak dapat dibuat.');
            }
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $productsQuery = $pdo->prepare(
                'SELECT id_produk, nama_produk, harga, stok
                 FROM tbl_produk WHERE id_produk IN (' . $placeholders . ')
                 ORDER BY id_produk FOR UPDATE'
            );
            $productsQuery->execute($productIds);
            $products = [];
            foreach ($productsQuery->fetchAll() as $product) {
                $products[(int) $product['id_produk']] = $product;
            }
            if (count($products) !== count($cart)) {
                throw new InvalidArgumentException('Produk di keranjang sudah tidak tersedia. Perbarui keranjang.');
            }

            $subtotal = 0.0;
            foreach ($cart as $id => $quantity) {
                $id = (int) $id;
                $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
                if (!$quantity || $quantity < 1 || $quantity > 99
                    || (int) $products[$id]['stok'] < $quantity) {
                    throw new InvalidArgumentException('Stok ' . $products[$id]['nama_produk'] . ' tidak mencukupi.');
                }
                $subtotal += (float) $products[$id]['harga'] * $quantity;
            }
            $subtotal = round($subtotal, 2);
            $discount = round($subtotal * (float) $lockedMember['diskon_persen'] / 100, 2);
            $tax = round(($subtotal - $discount) * 0.11, 2);
            $total = (float) round($subtotal - $discount + $tax);
            $orderNumber = 'OL' . date('ymd') . strtoupper(bin2hex(random_bytes(4)));
            $createOrder = $pdo->prepare(
                'INSERT INTO tbl_pesanan_online
                    (nomor_pesanan, id_pelanggan, id_member, email_pelanggan, telepon_pelanggan,
                     status, subtotal, diskon, pajak, total, metode_pembayaran, status_pembayaran,
                     duitku_payment_method)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $createOrder->execute([
                $orderNumber,
                (int) $lockedMember['id_pelanggan'],
                (int) $lockedMember['id_member'],
                $customerEmail,
                $customerPhone !== '' ? $customerPhone : null,
                'Menunggu',
                $subtotal,
                $discount,
                $tax,
                $total,
                'Duitku',
                'Menunggu pembayaran',
                $selectedPaymentMethod,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $createdOrderId = $orderId;
            $addDetail = $pdo->prepare(
                'INSERT INTO tbl_detail_pesanan_online
                    (id_pesanan, id_produk, nama_produk, jumlah, harga)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $reserveStock = $pdo->prepare(
                'UPDATE tbl_produk SET stok = stok - ? WHERE id_produk = ? AND stok >= ?'
            );
            foreach ($cart as $id => $quantity) {
                $id = (int) $id;
                $product = $products[$id];
                $addDetail->execute([
                    $orderId,
                    $id,
                    $product['nama_produk'],
                    (int) $quantity,
                    $product['harga'],
                ]);
                $reserveStock->execute([(int) $quantity, $id, (int) $quantity]);
                if ($reserveStock->rowCount() !== 1) {
                    throw new RuntimeException('Stok berubah saat pesanan diproses. Muat ulang halaman dan coba kembali.');
                }
            }
            $pdo->commit();
            $availableMethods = duitkuGetNonCashPaymentMethods((int) round($total));
            $selectedMethod = null;
            foreach ($availableMethods as $availableMethod) {
                if ($availableMethod['code'] === $selectedPaymentMethod) {
                    $selectedMethod = $availableMethod;
                    break;
                }
            }
            if ($selectedMethod === null) {
                throw new InvalidArgumentException('Metode pembayaran yang dipilih sudah tidak tersedia untuk nominal ini. Pilih metode lain.');
            }
            $saveMethodName = $pdo->prepare(
                'UPDATE tbl_pesanan_online SET duitku_payment_name = ? WHERE id_pesanan = ?'
            );
            $saveMethodName->execute([$selectedMethod['name'], $orderId]);
            $itemDetails = [[
                'name' => 'Pesanan MKLOYY ' . $orderNumber,
                'price' => (int) round($total),
                'quantity' => 1,
            ]];
            $invoice = duitkuCreateInvoice(
                $orderNumber,
                (int) round($total),
                (string) $member['nama_pelanggan'],
                $customerEmail,
                $customerPhone,
                $itemDetails,
                $selectedPaymentMethod
            );
            $invoiceCreated = true;
            $saveInvoice = $pdo->prepare(
                'UPDATE tbl_pesanan_online
                 SET duitku_reference = ?, duitku_payment_url = ?, duitku_payment_code = ?, duitku_qr_string = ?
                 WHERE id_pesanan = ? AND status_pembayaran = ?'
            );
            $saveInvoice->execute([
                (string) $invoice['reference'],
                (string) $invoice['paymentUrl'],
                $selectedPaymentMethod,
                isset($invoice['qrString']) ? (string) $invoice['qrString'] : null,
                $orderId,
                'Menunggu pembayaran',
            ]);
            $confirmOrder = $pdo->prepare(
                'SELECT status_pembayaran, duitku_reference, duitku_payment_url
                 FROM tbl_pesanan_online WHERE id_pesanan = ?'
            );
            $confirmOrder->execute([$orderId]);
            $savedOrder = $confirmOrder->fetch();
            if (!$savedOrder
                || $savedOrder['duitku_reference'] !== (string) $invoice['reference']
                || !in_array($savedOrder['status_pembayaran'], ['Menunggu pembayaran', 'Dibayar'], true)
                || ($savedOrder['status_pembayaran'] === 'Menunggu pembayaran'
                    && $savedOrder['duitku_payment_url'] !== (string) $invoice['paymentUrl'])) {
                throw new RuntimeException('Invoice Duitku berhasil dibuat tetapi gagal ditautkan ke pesanan. Hubungi Admin sebelum membayar.');
            }
            $_SESSION['online_cart'] = [];
            $gatewayRedirect = $savedOrder['status_pembayaran'] === 'Dibayar'
                ? null
                : (string) $invoice['paymentUrl'];
            setFlash('success', $savedOrder['status_pembayaran'] === 'Dibayar'
                ? 'Pesanan ' . $orderNumber . ' berhasil dibayar dan siap diambil.'
                : 'Pesanan ' . $orderNumber . ' dibuat. Selesaikan pembayaran ' . $selectedMethod['name'] . ' di halaman Duitku.');
        } else {
            throw new InvalidArgumentException('Aksi keranjang tidak dikenal.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newProof !== null) {
            removeUploadedImage($newProof, 'qris_proofs');
        }
        if ($createdOrderId !== null && !$invoiceCreated && !$pdo->inTransaction()) {
            try {
                duitkuFailOnlineOrder($pdo, $createdOrderId);
            } catch (Throwable $cleanupException) {
                error_log('Unable to release stock after failed Duitku invoice: ' . $cleanupException->getMessage());
            }
        }
        if ($exception instanceof InvalidArgumentException) {
            setFlash('error', $exception->getMessage());
        } elseif ($exception instanceof PDOException) {
            error_log('Online order operation failed: ' . $exception->getMessage());
            setFlash('error', 'Pesanan belum dapat diproses. Pastikan migrasi database pesanan online sudah dijalankan.');
        } else {
            error_log('Unexpected online order error: ' . $exception->getMessage());
            setFlash('error', $exception->getMessage());
        }
    }

    if ($gatewayRedirect !== null) {
        header('Location: ' . $gatewayRedirect);
        exit;
    }

    header('Location: ' . ($returnToDashboard ? 'dashboard_pelanggan.php' : 'belanja_online.php'));
    exit;
}

$cart = $_SESSION['online_cart'];
$cartProducts = [];
$subtotal = 0.0;
if ($cart) {
    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $cartQuery = $pdo->prepare(
        'SELECT id_produk, nama_produk, harga, stok, gambar
         FROM tbl_produk WHERE id_produk IN (' . $placeholders . ')'
    );
    $cartQuery->execute($ids);
    foreach ($cartQuery->fetchAll() as $product) {
        $productId = (int) $product['id_produk'];
        $product['jumlah'] = (int) $cart[$productId];
        $product['line_total'] = (float) $product['harga'] * $product['jumlah'];
        $subtotal += $product['line_total'];
        $cartProducts[] = $product;
    }
}
$discount = round($subtotal * (float) $member['diskon_persen'] / 100, 2);
$tax = round(($subtotal - $discount) * 0.11, 2);
$total = round($subtotal - $discount + $tax, 2);
$products = $pdo->query(
    'SELECT id_produk, nama_produk, harga, stok, gambar
     FROM tbl_produk WHERE stok > 0 ORDER BY nama_produk'
)->fetchAll();
$ordersQuery = $pdo->prepare(
    'SELECT id_pesanan, nomor_pesanan, status, status_pembayaran, metode_pembayaran, bukti_pembayaran,
            duitku_payment_url, duitku_reference, duitku_payment_method, duitku_payment_name,
            subtotal, diskon, pajak, total, dibuat_pada
     FROM tbl_pesanan_online WHERE id_member = ? ORDER BY dibuat_pada DESC LIMIT 50'
);
$ordersQuery->execute([(int) $member['id_member']]);
$orders = $ordersQuery->fetchAll();
$duitkuReady = false;
$duitkuSetupError = null;
$paymentMethods = [];
if ($cartProducts && $total > 0) {
    try {
        assertDuitkuCredentialsConfigured();
        $paymentMethods = duitkuGetNonCashPaymentMethods((int) round($total));
        if (!$paymentMethods) {
            $duitkuSetupError = 'Duitku tidak menyediakan metode non-tunai untuk nominal keranjang ini.';
        } else {
            try {
                assertDuitkuConfigured();
                $duitkuReady = true;
            } catch (RuntimeException $exception) {
                $duitkuSetupError = 'Metode tersedia, tetapi checkout memerlukan callback publik HTTPS. ' . $exception->getMessage();
            }
        }
    } catch (RuntimeException $exception) {
        $duitkuSetupError = $exception->getMessage();
    }
}
$checkoutEmail = (string) ($_SESSION['online_checkout_email'] ?? '');
$checkoutPhone = (string) ($_SESSION['online_checkout_phone'] ?? '');
$selectedPaymentMethod = (string) ($_SESSION['online_payment_method'] ?? '');
$flash = takeFlash();
$pageTitle = 'Belanja Online';
$activePage = 'shop';
require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Belanja member</div><h1>Katalog Produk</h1><p>Pesan dari rumah, pilih pembayaran non-tunai, lalu ambil barang di toko.</p></div>
    <a class="button button-secondary" href="dashboard_pelanggan.php">← Kembali ke dashboard</a>
</section>
<?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<section class="online-shop-layout">
    <div>
        <?php if (!$products): ?><div class="panel panel-body">Belum ada produk dengan stok tersedia.</div>
        <?php else: ?><div class="online-product-grid online-product-grid-full">
            <?php foreach ($products as $product): ?>
            <article class="online-product-card">
                <?php if ($product['gambar']): ?><img src="<?= h($product['gambar']) ?>" alt="<?= h($product['nama_produk']) ?>" loading="lazy"><?php else: ?><div class="online-product-placeholder">MKLOYY</div><?php endif; ?>
                <div class="online-product-info"><h3><?= h($product['nama_produk']) ?></h3><strong><?= rupiah($product['harga']) ?></strong><small>Stok <?= (int) $product['stok'] ?></small>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= (int) $product['id_produk'] ?>"><button class="button button-primary" type="submit">＋ Keranjang</button></form>
                </div>
            </article>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </div>
    <aside class="panel online-cart-panel">
        <div class="panel-header"><div><h2>Keranjang</h2><p class="panel-subtitle"><?= count($cartProducts) ?> jenis produk</p></div></div>
        <?php if (!$cartProducts): ?><div class="panel-body"><p class="panel-subtitle">Pilih produk untuk memulai pesanan.</p></div>
        <?php else: ?>
        <div class="online-cart-items">
            <?php foreach ($cartProducts as $product): ?>
            <div class="online-cart-item">
                <div><strong><?= h($product['nama_produk']) ?></strong><small><?= rupiah($product['harga']) ?> · <?= rupiah($product['line_total']) ?></small></div>
                <div class="online-cart-actions">
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="product_id" value="<?= (int) $product['id_produk'] ?>"><input class="online-cart-quantity" type="number" name="quantity" min="1" max="<?= min(99, (int) $product['stok']) ?>" value="<?= (int) $product['jumlah'] ?>" aria-label="Jumlah <?= h($product['nama_produk']) ?>"><button class="small-link" type="submit">Ubah</button></form>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= (int) $product['id_produk'] ?>"><button class="small-link" type="submit">Hapus</button></form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="member-discount-callout">
            <span class="member-discount-icon" aria-hidden="true">M</span>
            <div class="member-discount-copy">
                <strong>Kartu member aktif</strong>
                <span><?= h($member['card_code']) ?></span>
            </div>
            <span class="member-discount-rate"><?= h(number_format((float) $member['diskon_persen'], 2, ',', '.')) ?>%</span>
        </div>
        <div class="online-cart-summary">
            <div><span>Subtotal</span><strong><?= rupiah($subtotal) ?></strong></div>
            <div class="member-savings-row"><span>Diskon kartu member</span><strong>− <?= rupiah($discount) ?></strong></div>
            <div><span>Pajak</span><strong><?= rupiah($tax) ?></strong></div>
            <div class="online-cart-total"><span>Total pembayaran</span><strong><?= rupiah($total) ?></strong></div>
        </div>
        <div class="online-qris-checkout">
            <div class="online-qris-heading"><span class="online-qris-icon">▦</span><div><strong>Pembayaran online melalui Duitku</strong><small>Transfer bank, virtual account, e-wallet, kartu, QRIS, dan kanal aktif lain.</small></div></div>
            <p>Tunai tidak tersedia untuk belanja online. Pilih kanal pembayaran yang aktif; status pembayaran akan dikonfirmasi otomatis oleh Duitku.</p>
        </div>
        <?php if (!$duitkuReady): ?><div class="notice error online-duitku-warning">Pembayaran online belum siap. <?= h($duitkuSetupError) ?></div><?php endif; ?>
        <form method="post" class="online-checkout-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="checkout">
            <label class="online-payment-method">Metode pembayaran
                <select name="payment_method" required <?= !$paymentMethods ? 'disabled' : '' ?>>
                    <?php if (!$paymentMethods): ?><option value="">Metode pembayaran belum tersedia</option>
                    <?php else: foreach ($paymentMethods as $method): ?>
                        <option value="<?= h($method['code']) ?>" <?= $selectedPaymentMethod === $method['code'] ? 'selected' : '' ?>><?= h($method['name']) ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </label>
            <label class="online-payment-method">Email untuk pembayaran<input type="email" name="customer_email" value="<?= h($checkoutEmail) ?>" maxlength="254" autocomplete="email" required></label>
            <label class="online-payment-method">Nomor telepon <span class="field-optional">(opsional)</span><input type="tel" name="customer_phone" value="<?= h($checkoutPhone) ?>" maxlength="32" autocomplete="tel"></label>
            <div class="online-payment-selected"><span>Total pembayaran</span><strong><?= rupiah($total) ?></strong></div>
            <button class="button button-primary" type="submit" <?= !$duitkuReady ? 'disabled title="Konfigurasi Duitku belum lengkap"' : '' ?>>Pilih pembayaran · <?= rupiah($total) ?></button>
        </form>
        <?php endif; ?>
    </aside>
</section>
<section class="panel section-card" id="pesanan">
    <div class="panel-header"><div><h2>Riwayat pesanan online</h2><p class="panel-subtitle">Pembayaran Duitku terkonfirmasi otomatis.</p></div><span class="badge"><?= count($orders) ?> pesanan</span></div>
    <div class="table-wrap"><table><thead><tr><th>No. pesanan</th><th>Tanggal</th><th>Status pesanan</th><th>Pembayaran</th><th>Total & bukti</th></tr></thead><tbody>
        <?php if (!$orders): ?><tr><td class="table-empty" colspan="5">Belum ada pesanan online.</td></tr>
        <?php else: foreach ($orders as $order): ?><tr>
            <td><strong><?= h($order['nomor_pesanan']) ?></strong><br><span class="member-city">Referensi toko</span><?php if ($order['duitku_reference']): ?><br><span class="member-city">Referensi Duitku: <?= h($order['duitku_reference']) ?></span><?php endif; ?></td>
            <td><?= h($order['dibuat_pada']) ?></td>
            <td><span class="badge <?= $order['status_pembayaran'] === 'Gagal' || $order['status'] === 'Dibatalkan' ? 'orange' : '' ?>"><?= h($order['status'] === 'Menunggu' && $order['status_pembayaran'] === 'Menunggu pembayaran' && $order['duitku_payment_method'] ? 'Menunggu pembayaran' : ($order['status'] === 'Menunggu' ? 'Siap diambil' : $order['status'])) ?></span></td>
            <td><?= h($order['duitku_payment_name'] ?: $order['metode_pembayaran']) ?><br><span class="badge <?= in_array($order['status_pembayaran'], ['Ditolak', 'Gagal', 'Dibatalkan'], true) ? 'orange' : '' ?>"><?= h($order['status_pembayaran']) ?></span></td>
            <td><strong><?= rupiah($order['total']) ?></strong>
                <?php if ($order['duitku_payment_url'] && $order['status_pembayaran'] === 'Menunggu pembayaran'): ?><br><a class="small-link" href="<?= h($order['duitku_payment_url']) ?>" target="_blank" rel="noopener">Lanjutkan pembayaran Duitku</a><?php endif; ?>
                <?php if ($order['bukti_pembayaran']): ?><br><a class="small-link" href="payment_proof.php?order_id=<?= (int) $order['id_pesanan'] ?>" target="_blank" rel="noopener">Lihat bukti</a><?php endif; ?>
                <?php if (!$order['duitku_reference'] && in_array($order['status_pembayaran'], ['Menunggu pembayaran', 'Ditolak'], true) && !in_array($order['status'], ['Selesai', 'Dibatalkan'], true)): ?>
                <form method="post" enctype="multipart/form-data" class="qris-proof-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="upload_payment_proof"><input type="hidden" name="order_id" value="<?= (int) $order['id_pesanan'] ?>">
                    <label class="qris-proof-file">Pilih bukti<input type="file" name="payment_proof" accept="image/jpeg,image/png,image/webp" required></label>
                    <button class="small-link" type="submit"><?= $order['status_pembayaran'] === 'Ditolak' ? 'Kirim ulang' : 'Unggah bukti' ?></button>
                </form>
                <?php endif; ?>
            </td>
        </tr><?php endforeach; endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
