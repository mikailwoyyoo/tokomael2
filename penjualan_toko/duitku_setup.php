    <?php

require __DIR__ . '/includes/helpers.php';
requireRole(['admin']);
require __DIR__ . '/includes/duitku.php';

$config = duitkuConfig();
$methods = [];
$error = null;
$checkoutReady = false;
$credentialsConfigured = false;
$apiResponded = false;
try {
    assertDuitkuCredentialsConfigured();
    $credentialsConfigured = true;
    $methods = duitkuGetNonCashPaymentMethods(10000);
    $apiResponded = true;
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
if ($credentialsConfigured) {
    try {
        assertDuitkuConfigured();
        $checkoutReady = true;
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}
$merchantCode = trim((string) $config['merchant_code']);
$merchantCodeConfigured = $merchantCode !== ''
    && stripos($merchantCode, 'ISI_') !== 0
    && stripos($merchantCode, 'CHANGE_ME') === false;
$status = $checkoutReady
    ? 'Terhubung dan siap untuk checkout'
    : ($apiResponded
        ? 'Duitku terhubung; callback belum siap'
        : ($credentialsConfigured ? 'Koneksi API Duitku gagal' : 'Kredensial belum diatur'));

$pageTitle = 'Integrasi Duitku';
$activePage = 'duitku';
require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Payment gateway</div><h1>Integrasi Duitku</h1><p>Periksa konfigurasi dan metode pembayaran aktif sebelum mengaktifkan checkout online.</p></div>
    <a class="button button-secondary" href="index.php">← Kembali ke dashboard</a>
</section>
<?php if ($error): ?><div class="notice <?= $checkoutReady ? 'success' : 'error' ?>"><?= h($error) ?></div><?php endif; ?>
<?php if (!$credentialsConfigured): ?><section class="panel panel-body"><h2>Lengkapi kredensial Duitku</h2><p>Edit file <code>config/duitku.local.php</code>. Ganti nilai contoh pada <code>merchant_code</code> dan <code>api_key</code> dengan kredensial sandbox dari akun Duitku, simpan file, lalu muat ulang halaman ini. Jangan membagikan API Key melalui chat.</p></section>
<?php elseif (!$apiResponded): ?><section class="panel panel-body"><h2>Koneksi ke API Duitku gagal</h2><p>Periksa Merchant Code dan API Key sandbox di <code>config/duitku.local.php</code>, pastikan environment cocok dengan kredensial, lalu muat ulang halaman ini. Detail respons API ditampilkan pada pesan di atas.</p></section>
<?php elseif (!$checkoutReady): ?><section class="panel panel-body"><h2>Aktifkan callback checkout</h2><p>Daftar kanal berhasil dimuat. Untuk menerima konfirmasi pembayaran, atur <code>base_url</code> ke URL HTTPS publik yang sedang aktif dan daftarkan URL callback yang ditampilkan di bawah pada portal Duitku.</p></section><?php endif; ?>
<section class="panel section-card">
    <div class="panel-header"><div><h2>Konfigurasi server</h2><p class="panel-subtitle">API Key tidak pernah ditampilkan pada halaman ini.</p></div><span class="badge"><?= h(strtoupper($config['environment'])) ?></span></div>
    <div class="panel-body">
        <p><strong>Status:</strong> <?= h($status) ?></p>
        <p><strong>Merchant Code:</strong> <?= $merchantCodeConfigured ? h($merchantCode) : 'Belum diatur — nilai yang ada masih contoh' ?></p>
        <p><strong>Callback URL:</strong> <?= $config['base_url'] !== '' ? h($config['base_url'] . '/duitku_callback.php') : 'Atur base_url di konfigurasi lokal' ?></p>
        <p><strong>Return URL:</strong> <?= $config['base_url'] !== '' ? h($config['base_url'] . '/duitku_return.php') : 'Atur base_url di konfigurasi lokal' ?></p>
        <p class="panel-subtitle">URL callback harus dapat dijangkau publik melalui HTTPS; localhost tidak dapat menerima callback Duitku. Metode tunai dikecualikan, dan daftar metode checkout mengikuti kanal non-tunai yang aktif di Duitku.</p>
    </div>
</section>
<section class="panel">
    <div class="panel-header"><div><h2>Metode non-tunai yang aktif</h2><p class="panel-subtitle">Daftar contoh untuk nominal Rp 10.000; checkout memuat ulang daftar sesuai total pesanan.</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Kode</th><th>Nama</th></tr></thead><tbody>
        <?php if (!$methods): ?><tr><td class="table-empty" colspan="2"><?= $apiResponded ? 'Duitku tidak mengirim metode pembayaran non-tunai aktif untuk nominal Rp 10.000.' : 'Lengkapi Merchant Code dan API Key valid untuk memuat daftar metode pembayaran.' ?></td></tr>
        <?php else: foreach ($methods as $method): ?>
            <tr><td><strong><?= h($method['code']) ?></strong></td><td><?= h($method['name']) ?></td></tr>
        <?php endforeach; endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
