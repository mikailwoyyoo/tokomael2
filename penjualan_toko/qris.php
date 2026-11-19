<?php

require __DIR__ . '/includes/helpers.php';
requireRole(['admin']);
require __DIR__ . '/config/database.php';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newImage = null;
    try {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $newImage = storeUploadedImage($_FILES['qris_image'] ?? [], 'qris');
        $currentQuery = $pdo->query('SELECT gambar_path FROM tbl_pengaturan_qris WHERE id_qris = 1');
        $oldImage = $currentQuery->fetchColumn();
        $update = $pdo->prepare(
            'UPDATE tbl_pengaturan_qris SET gambar_path = ? WHERE id_qris = 1'
        );
        $update->execute([$newImage]);
        removeUploadedImage(is_string($oldImage) ? $oldImage : null, 'qris');
        setFlash('success', 'Gambar QRIS toko berhasil diperbarui.');
    } catch (InvalidArgumentException $exception) {
        if ($newImage !== null) {
            removeUploadedImage($newImage, 'qris');
        }
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($newImage !== null) {
            removeUploadedImage($newImage, 'qris');
        }
        error_log('QRIS configuration failed: ' . $exception->getMessage());
        setFlash('error', 'Gambar QRIS gagal disimpan. Pastikan migrasi pesanan online sudah dijalankan.');
    } catch (Throwable $exception) {
        if ($newImage !== null) {
            removeUploadedImage($newImage, 'qris');
        }
        error_log('Unexpected QRIS configuration error: ' . $exception->getMessage());
        setFlash('error', 'Terjadi kesalahan saat menyimpan gambar QRIS.');
    }

    header('Location: qris.php');
    exit;
}

$pageTitle = 'Pengaturan QRIS';
$activePage = 'qris';
$flash = takeFlash();
$error = null;
$qrisImage = null;
try {
    $qrisImage = $pdo->query(
        'SELECT gambar_path FROM tbl_pengaturan_qris WHERE id_qris = 1'
    )->fetchColumn();
    if (!is_string($qrisImage) || $qrisImage === '') {
        $qrisImage = null;
    }
} catch (PDOException $exception) {
    $error = databaseError($exception);
}

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Pembayaran toko online</div><h1>Pengaturan QRIS</h1><p>Unggah gambar QRIS resmi toko. Pelanggan akan memindai QR ini dan mengirim bukti pembayaran untuk diverifikasi.</p></div>
    <a class="button button-secondary" href="index.php">← Kembali ke dashboard</a>
</section>
<?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<section class="panel qris-settings-panel">
    <div class="panel-header"><div><h2>QRIS toko</h2><p class="panel-subtitle">Format JPG, PNG, atau WEBP, maksimal 5 MB.</p></div></div>
    <div class="panel-body qris-settings-content">
        <?php if ($qrisImage): ?><img class="qris-settings-preview" src="<?= h($qrisImage) ?>" alt="QRIS pembayaran toko">
        <?php else: ?><div class="qris-settings-empty">QRIS belum diunggah</div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="qris-settings-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <label for="qris-image">Pilih gambar QRIS toko</label>
            <input id="qris-image" name="qris_image" type="file" accept="image/jpeg,image/png,image/webp" required>
            <button class="button button-primary" type="submit"><?= $qrisImage ? 'Perbarui QRIS' : 'Simpan QRIS' ?></button>
        </form>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
