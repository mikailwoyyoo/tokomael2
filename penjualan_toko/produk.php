<?php

require __DIR__ . '/includes/helpers.php';
requireLogin();
require __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newImagePath = null;
    $oldImagePath = null;
    $action = '';
    try {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add_category') {
            $name = trim((string) ($_POST['category_name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if (preg_match('/^.{1,100}$/us', $name) !== 1 || preg_match('/^.{0,255}$/us', $description) !== 1) {
                throw new InvalidArgumentException('Nama kategori wajib diisi (maksimal 100 karakter); deskripsi maksimal 255 karakter.');
            }
            $insert = $pdo->prepare('INSERT INTO tbl_kategori (nama_kategori, deskripsi) VALUES (?, ?)');
            $insert->execute([$name, $description !== '' ? $description : null]);
            setFlash('success', 'Kategori berhasil ditambahkan dan dicatat oleh Trigger.');
        } elseif ($action === 'add_product') {
            $name = trim((string) ($_POST['product_name'] ?? ''));
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
            $barcode = trim((string) ($_POST['barcode'] ?? ''));
            if (preg_match('/^.{1,100}$/us', $name) !== 1 || !$categoryId
                || $price === false || $price < 0 || $price > 9999999999.99
                || $stock === false || $stock < 0 || $stock > 2147483647
                || ($barcode !== '' && preg_match('/^[\x21-\x7E]{1,64}$/D', $barcode) !== 1)) {
                throw new InvalidArgumentException('Isi nama, kategori, harga dan stok dengan benar; barcode maksimal 64 karakter tanpa spasi.');
            }
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $newImagePath = storeProductImage($_FILES['image']);
            }
            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO tbl_produk (id_kategori, nama_produk, harga, stok, gambar, barcode) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$categoryId, $name, $price, $stock, $newImagePath, $barcode !== '' ? $barcode : null]);
            $productId = (int) $pdo->lastInsertId();
            if ($barcode === '') {
                $barcodeUpdate = $pdo->prepare('UPDATE tbl_produk SET barcode = ? WHERE id_produk = ?');
                $barcodeUpdate->execute([productBarcode($productId), $productId]);
            }
            $pdo->commit();
            $newImagePath = null;
            setFlash('success', $barcode !== ''
                ? 'Produk berhasil ditambahkan dengan barcode pabrik.'
                : 'Produk berhasil ditambahkan dengan barcode EAN-13 otomatis.');
        } elseif ($action === 'update_product') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['product_name'] ?? ''));
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
            $barcode = trim((string) ($_POST['barcode'] ?? ''));
            if (!$productId || preg_match('/^.{1,100}$/us', $name) !== 1 || !$categoryId
                || $price === false || $price < 0 || $price > 9999999999.99
                || $stock === false || $stock < 0 || $stock > 2147483647
                || ($barcode !== '' && preg_match('/^[\x21-\x7E]{1,64}$/D', $barcode) !== 1)) {
                throw new InvalidArgumentException('Isi nama, kategori, harga dan stok dengan benar; barcode maksimal 64 karakter tanpa spasi.');
            }

            $find = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk = ?');
            $find->execute([$productId]);
            $oldImagePath = $find->fetchColumn();
            if ($oldImagePath === false) {
                throw new InvalidArgumentException('Produk yang akan diedit tidak ditemukan.');
            }
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $newImagePath = storeProductImage($_FILES['image']);
            }

            $pdo->beginTransaction();
            $update = $pdo->prepare(
                'UPDATE tbl_produk
                 SET id_kategori = ?, nama_produk = ?, harga = ?, stok = ?, barcode = ?, gambar = ?
                 WHERE id_produk = ?'
            );
            $update->execute([
                $categoryId,
                $name,
                $price,
                $stock,
                $barcode !== '' ? $barcode : productBarcode((int) $productId),
                $newImagePath ?? $oldImagePath,
                $productId,
            ]);
            $pdo->commit();
            if ($newImagePath !== null) {
                removeProductImage($oldImagePath);
                $newImagePath = null;
            }
            setFlash('success', 'Data produk berhasil diperbarui.');
        } elseif ($action === 'delete_product') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId) {
                throw new InvalidArgumentException('Produk yang akan dihapus tidak valid.');
            }
            $find = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk = ?');
            $find->execute([$productId]);
            $oldImagePath = $find->fetchColumn();
            if ($oldImagePath === false) {
                throw new InvalidArgumentException('Produk yang akan dihapus tidak ditemukan.');
            }
            $delete = $pdo->prepare('DELETE FROM tbl_produk WHERE id_produk = ?');
            $delete->execute([$productId]);
            removeProductImage($oldImagePath);
            setFlash('success', 'Produk berhasil dihapus dari katalog. Riwayat transaksi tetap tersimpan.');
        } elseif ($action === 'update_image') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || !isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
                throw new InvalidArgumentException('Pilih gambar produk yang akan diunggah.');
            }

            $newImagePath = storeProductImage($_FILES['image']);
            $find = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk = ?');
            $find->execute([$productId]);
            $oldImagePath = $find->fetchColumn();
            if ($oldImagePath === false) {
                throw new InvalidArgumentException('Produk tidak ditemukan.');
            }

            $update = $pdo->prepare('UPDATE tbl_produk SET gambar = ? WHERE id_produk = ?');
            $update->execute([$newImagePath, $productId]);
            removeProductImage($oldImagePath);
            $newImagePath = null;
            setFlash('success', 'Gambar produk berhasil diperbarui.');
        } elseif ($action === 'adjust_stock') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            $change = filter_var($_POST['stock_change'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || $change === false || $change === 0 || abs($change) > 2147483647) {
                throw new InvalidArgumentException('Pilih produk dan masukkan perubahan stok bukan nol.');
            }
            $update = $pdo->prepare(
                'UPDATE tbl_produk SET stok = stok + ? WHERE id_produk = ? AND stok + ? >= 0'
            );
            $update->execute([$change, $productId, $change]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('Stok tidak dapat diubah. Produk tidak ditemukan atau stok tidak boleh menjadi negatif.');
            }
            setFlash('success', 'Stok berhasil diperbarui dan dicatat oleh Trigger.');
        } else {
            throw new InvalidArgumentException('Aksi tidak dikenali.');
        }
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        removeProductImage($newImagePath);
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Catalog operation failed: ' . $exception->getMessage());
        removeProductImage($newImagePath);
        $message = (string) $exception->getCode() === '23000'
            ? ($action === 'delete_product'
                ? 'Produk tidak dapat dihapus karena masih digunakan oleh data lain.'
                : 'Nama produk/barcode sudah digunakan atau relasi kategori tidak valid.')
            : 'Perubahan katalog gagal disimpan. Pastikan migrasi database sudah diterapkan.';
        setFlash('error', $message);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Unexpected catalog error: ' . $exception->getMessage());
        removeProductImage($newImagePath);
        setFlash('error', 'Terjadi kesalahan saat memperbarui katalog.');
    }

    header('Location: produk.php');
    exit;
}

$pageTitle = 'Produk';
$activePage = 'produk';
$error = null;
$flash = takeFlash();
$categories = [];
$products = [];
$editProduct = null;

try {
    $categories = $pdo->query('SELECT id_kategori, nama_kategori FROM tbl_kategori ORDER BY nama_kategori')->fetchAll();
    $editProductId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
    if ($editProductId) {
        $editQuery = $pdo->prepare('SELECT id_produk, id_kategori, nama_produk, harga, stok, gambar, barcode FROM tbl_produk WHERE id_produk = ?');
        $editQuery->execute([$editProductId]);
        $editProduct = $editQuery->fetch() ?: null;
        if ($editProduct === null) {
            setFlash('error', 'Produk yang akan diedit tidak ditemukan.');
            header('Location: produk.php');
            exit;
        }
    }
    ensureProductBarcodes($pdo);
    $products = $pdo->query(
        'SELECT p.id_produk, p.nama_produk, p.harga, p.stok, p.gambar, p.barcode, k.nama_kategori,
                COALESCE(SUM(d.jumlah), 0) AS total_terjual
         FROM tbl_produk p
         LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori
         LEFT JOIN tbl_detail_transaksi d ON d.nama_produk = p.nama_produk
         GROUP BY p.id_produk, p.nama_produk, p.harga, p.stok, p.gambar, p.barcode, k.nama_kategori
         ORDER BY p.nama_produk'
    )->fetchAll();
} catch (PDOException $exception) {
    $error = databaseError($exception);
}

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div>    <div class="eyebrow">Inventori toko</div><h1>Produk & Kategori</h1><p>Kelola katalog, foto produk, harga, dan stok.</p></div>
    <a class="button button-secondary" href="transaksi.php">Buka kasir POS →</a>
</section>
<?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

<section class="section-grid">
    <article class="panel section-card" id="product-form">
        <div class="panel-header"><div><h2><?= $editProduct ? 'Edit Produk' : 'Tambah Produk' ?></h2><p class="panel-subtitle"><?= $editProduct ? 'Perbarui data katalog produk' : 'Scan barcode pabrik atau gunakan barcode otomatis' ?></p></div><?php if ($editProduct): ?><a class="button button-secondary" href="produk.php">Batal edit</a><?php endif; ?></div>
        <div class="panel-body">
            <?php if (!$categories && !$error): ?><div class="notice info">Tambahkan kategori terlebih dahulu agar dapat mendaftarkan produk.</div><?php endif; ?>
            <form method="post" enctype="multipart/form-data" data-product-form>
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="<?= $editProduct ? 'update_product' : 'add_product' ?>">
                <?php if ($editProduct): ?><input type="hidden" name="product_id" value="<?= (int) $editProduct['id_produk'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="field full"><label for="product-barcode">Barcode pabrik (scan dengan mesin)</label><input id="product-barcode" name="barcode" maxlength="64" pattern="[!-~]{1,64}" title="Barcode harus berisi 1 sampai 64 karakter tanpa spasi." placeholder="Klik di sini, lalu scan barcode kemasan" autocomplete="off" spellcheck="false" data-barcode-input autofocus value="<?= h($editProduct['barcode'] ?? '') ?>"><small>Terima barcode EAN/UPC atau Code 128. Kosongkan untuk membuat barcode EAN-13 otomatis.</small></div>
                    <div class="field full"><label for="product-name">Nama produk</label><input id="product-name" name="product_name" maxlength="100" required value="<?= h($editProduct['nama_produk'] ?? '') ?>"></div>
                    <div class="field"><label for="product-category">Kategori</label>
                        <select id="product-category" name="category_id" required>
                            <option value="">Pilih kategori</option>
                            <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id_kategori'] ?>" <?= (int) ($editProduct['id_kategori'] ?? 0) === (int) $category['id_kategori'] ? 'selected' : '' ?>><?= h($category['nama_kategori']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label for="product-price">Harga (Rp)</label><input id="product-price" name="price" type="number" min="0" max="9999999999.99" step="0.01" required value="<?= h($editProduct['harga'] ?? '') ?>"></div>
                    <div class="field"><label for="product-stock"><?= $editProduct ? 'Stok' : 'Stok awal' ?></label><input id="product-stock" name="stock" type="number" min="0" value="<?= h($editProduct['stok'] ?? '0') ?>" required></div>
                    <div class="field full"><label for="product-image">Gambar produk (opsional, JPG/PNG/WEBP · maks. 5 MB)<?= $editProduct ? ' — kosongkan untuk mempertahankan gambar' : '' ?></label><input id="product-image" name="image" type="file" accept="image/jpeg,image/png,image/webp" data-image-input><img class="image-preview" src="<?= h($editProduct['gambar'] ?? '') ?>" alt="Pratinjau gambar produk" data-image-preview <?= !empty($editProduct['gambar']) ? '' : 'hidden' ?>></div>
                </div>
                <div class="form-actions"><button class="button button-primary" type="submit" <?= !$categories || $error ? 'disabled' : '' ?>><?= $editProduct ? 'Simpan Perubahan' : '＋ Simpan Produk' ?></button></div>
            </form>
        </div>
    </article>
    <article class="panel section-card">
        <div class="panel-header"><div><h2>Tambah Kategori</h2><p class="panel-subtitle">Penyimpanan ke tbl_kategori</p></div></div>
        <div class="panel-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_category">
                <div class="form-grid">
                    <div class="field full"><label for="category-name">Nama kategori</label><input id="category-name" name="category_name" maxlength="100" required></div>
                    <div class="field full"><label for="category-description">Deskripsi (opsional)</label><input id="category-description" name="description" maxlength="255"></div>
                </div>
                <div class="form-actions"><button class="button button-primary" type="submit" <?= $error ? 'disabled' : '' ?>>＋ Simpan Kategori</button></div>
            </form>
            <div class="product-note" style="margin-top:15px">Kategori saat ini: <?= count($categories) ?></div>
        </div>
    </article>
</section>

<section class="panel section-card">
    <div class="panel-header"><div><h2>Daftar Inventori</h2><p class="panel-subtitle"><?= count($products) ?> produk terdaftar</p></div><span class="badge">tbl_produk</span></div>
    <div class="table-wrap">
        <table><thead><tr><th>Produk</th><th>Barcode</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Terjual tercatat</th><th>Gambar</th><th>Penyesuaian stok</th><th>Aksi</th></tr></thead>
            <tbody><?php if (!$products): ?><tr><td class="table-empty" colspan="9"><?= $error ? 'Data tidak tersedia.' : 'Belum ada produk.' ?></td></tr>
            <?php else: foreach ($products as $product): ?><tr>
                <td><div class="inventory-product"><span class="inventory-thumb"><?php if ($product['gambar']): ?><img src="<?= h($product['gambar']) ?>" alt=""><?php else: ?><?= h(mb_strtoupper(mb_substr($product['nama_produk'], 0, 1))) ?><?php endif; ?></span><strong><?= h($product['nama_produk']) ?></strong></div></td>
                <td>
                    <div class="barcode-cell" data-barcode-label>
                        <?php if (isValidEan13((string) $product['barcode'])): ?><?= ean13Svg((string) $product['barcode']) ?><?php else: ?><span class="barcode-external">Barcode pabrik</span><?php endif; ?>
                        <code><?= h($product['barcode']) ?></code>
                        <?php if (isValidEan13((string) $product['barcode'])): ?><button class="button button-secondary barcode-print-button" type="button" data-barcode-print>Cetak label</button><?php endif; ?>
                    </div>
                </td>
                <td><?= h($product['nama_kategori'] ?? 'Tanpa kategori') ?></td>
                <td><?= rupiah($product['harga']) ?></td><td><span class="badge <?= (int) $product['stok'] === 0 ? 'orange' : '' ?>"><?= number_format((int) $product['stok'], 0, ',', '.') ?></span></td>
                <td><?= number_format((int) $product['total_terjual'], 0, ',', '.') ?></td>
                <td>
                    <form method="post" enctype="multipart/form-data" class="image-update-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="update_image">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id_produk'] ?>">
                        <label class="image-upload-button">Pilih foto<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
                        <button class="button button-secondary" type="submit">Simpan</button>
                    </form>
                </td>
                <td>
                    <form method="post" class="stock-form" style="display:flex;gap:6px;min-width:190px">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="adjust_stock">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id_produk'] ?>">
                        <input name="stock_change" type="number" step="1" required placeholder="+/- stok" aria-label="Jumlah perubahan stok untuk <?= h($product['nama_produk']) ?>" style="width:95px;min-height:32px;padding:0 7px;border:1px solid #e1e6ee;border-radius:6px">
                        <button class="button button-secondary" type="submit" style="min-height:32px;padding:0 9px">Ubah</button>
                    </form>
                </td>
                <td><div class="product-actions">
                    <a class="button button-secondary" href="produk.php?edit=<?= (int) $product['id_produk'] ?>#product-form">Edit</a>
                    <form method="post" data-confirm="Hapus <?= h($product['nama_produk']) ?> dari katalog? Riwayat transaksi tidak dihapus.">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="delete_product">
                        <input type="hidden" name="product_id" value="<?= (int) $product['id_produk'] ?>">
                        <button class="button button-danger" type="submit">Hapus</button>
                    </form>
                </div></td>
            </tr><?php endforeach; endif; ?></tbody>
        </table>
    </div>
</section>
<div class="notice info">Produk yang sudah ada pada detail transaksi disalin ke katalog dengan harga rata-rata transaksi sebelumnya. Stok awal ditetapkan 0 karena stok riil tidak tersedia pada data lama; sesuaikan stok sebelum melakukan penjualan baru. Foto dapat diunggah atau diperbarui per produk.</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
