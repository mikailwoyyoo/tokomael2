<?php

require __DIR__ . '/includes/helpers.php';
requireLogin();
require __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $name = trim((string) ($_POST['name'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        if (preg_match('/^.{1,100}$/us', $name) !== 1 || preg_match('/^.{0,50}$/us', $city) !== 1) {
            throw new InvalidArgumentException('Nama pelanggan wajib diisi (maks. 100 karakter); kota opsional (maks. 50 karakter).');
        }

        $statement = $pdo->prepare('CALL TambahPelanggan(?, ?)');
        $statement->execute([$name, $city !== '' ? $city : null]);
        $statement->closeCursor();
        setFlash('success', 'Pelanggan berhasil ditambahkan.');
    } catch (InvalidArgumentException $exception) {
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log('Customer insert failed: ' . $exception->getMessage());
        setFlash('error', 'Pelanggan tidak dapat ditambahkan. Periksa koneksi database dan nama pelanggan.');
    }

    header('Location: pelanggan.php');
    exit;
}

$pageTitle = 'Pelanggan';
$activePage = 'pelanggan';
$error = null;
$flash = takeFlash();
$customers = [];

try {
    $customers = $pdo->query(
        'SELECT p.id_pelanggan, p.nama_pelanggan, p.kota,
                COUNT(t.id_transaksi) AS jumlah_transaksi,
                COALESCE(SUM(t.total_belanja), 0) AS total_belanja
         FROM tbl_pelanggan p
         LEFT JOIN tbl_transaksi t ON t.id_pelanggan = p.id_pelanggan
         GROUP BY p.id_pelanggan, p.nama_pelanggan, p.kota
         ORDER BY p.nama_pelanggan'
    )->fetchAll();
} catch (PDOException $exception) {
    $error = databaseError($exception);
}

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Relasi pelanggan</div><h1>Data Pelanggan</h1><p>Daftar pelanggan dan ringkasan aktivitas belanjanya.</p></div>
    <a class="button button-primary" href="#tambah-pelanggan">＋ Tambah Pelanggan</a>
</section>
<?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<section class="panel section-card" id="tambah-pelanggan">
    <div class="panel-header"><div><h2>Tambah Pelanggan</h2><p class="panel-subtitle">Disimpan menggunakan Stored Procedure database</p></div></div>
    <div class="panel-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="field"><label for="customer-name">Nama pelanggan</label><input id="customer-name" name="name" maxlength="100" required></div>
            <div class="field"><label for="customer-city">Kota <span class="field-optional">(opsional)</span></label><input id="customer-city" name="city" maxlength="50" autocomplete="address-level2"></div>
            <div class="field full"><button class="button button-primary" type="submit">Simpan Pelanggan</button></div>
        </form>
    </div>
</section>
<section class="panel">
    <div class="panel-header"><div><h2>Semua Pelanggan</h2><p class="panel-subtitle"><?= count($customers) ?> pelanggan terdaftar</p></div></div>
    <div class="table-wrap">
        <table><thead><tr><th>ID</th><th>Nama pelanggan</th><th>Kota</th><th>Transaksi</th><th>Total belanja</th><th>Kategori belanja</th></tr></thead>
            <tbody><?php if (!$customers): ?><tr><td class="table-empty" colspan="6"><?= $error ? 'Data tidak tersedia.' : 'Belum ada data pelanggan.' ?></td></tr>
            <?php else: foreach ($customers as $customer): ?>
                <tr>
                    <td>#<?= (int) $customer['id_pelanggan'] ?></td><td><strong><?= h($customer['nama_pelanggan']) ?></strong></td>
                    <td><?= h($customer['kota']) ?></td><td><?= number_format((int) $customer['jumlah_transaksi'], 0, ',', '.') ?></td>
                    <td><?= rupiah($customer['total_belanja']) ?></td>
                    <td><span class="badge"><?= h((float) $customer['total_belanja'] >= 1000000 ? 'Gold' : ((float) $customer['total_belanja'] >= 500000 ? 'Silver' : 'Reguler')) ?></span></td>
                </tr>
            <?php endforeach; endif; ?></tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
