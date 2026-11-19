<?php

require __DIR__ . '/includes/helpers.php';
requireLogin();
require __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (preg_match('/^[A-Za-z0-9._-]{3,50}$/D', $username) !== 1
                || preg_match('/^.{1,100}$/us', $name) !== 1
                || strlen($password) < 8) {
                throw new InvalidArgumentException('Username harus 3-50 karakter, nama wajib diisi, dan kata sandi minimal 8 karakter.');
            }

            $usernameCheck = $pdo->prepare(
                'SELECT username FROM tbl_admin WHERE username = ?
                 UNION ALL SELECT username FROM tbl_kasir WHERE username = ?
                 UNION ALL SELECT username FROM tbl_member WHERE username = ?
                 LIMIT 1'
            );
            $usernameCheck->execute([$username, $username, $username]);
            if ($usernameCheck->fetchColumn()) {
                throw new InvalidArgumentException('Username sudah digunakan oleh akun lain.');
            }

            $create = $pdo->prepare(
                'INSERT INTO tbl_kasir (username, nama_kasir, password_hash) VALUES (?, ?, ?)'
            );
            $create->execute([$username, $name, password_hash($password, PASSWORD_DEFAULT)]);
            setFlash('success', 'Akun kasir berhasil dibuat.');
        } elseif ($action === 'toggle') {
            $cashierId = filter_var($_POST['cashier_id'] ?? null, FILTER_VALIDATE_INT);
            $active = filter_var($_POST['active'] ?? null, FILTER_VALIDATE_INT);
            if (!$cashierId || $cashierId < 1 || !in_array($active, [0, 1], true)) {
                throw new InvalidArgumentException('Data status akun kasir tidak valid.');
            }
            $update = $pdo->prepare('UPDATE tbl_kasir SET aktif = ? WHERE id_kasir = ?');
            $update->execute([$active, $cashierId]);
            setFlash('success', $active === 1 ? 'Akun kasir diaktifkan.' : 'Akun kasir dinonaktifkan.');
        } else {
            throw new InvalidArgumentException('Aksi akun kasir tidak dikenal.');
        }
    } catch (InvalidArgumentException $exception) {
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log('Cashier account update failed: ' . $exception->getMessage());
        setFlash('error', 'Akun kasir gagal disimpan. Pastikan migrasi role sudah diterapkan dan username belum dipakai.');
    } catch (Throwable $exception) {
        error_log('Unexpected cashier account error: ' . $exception->getMessage());
        setFlash('error', 'Terjadi kesalahan saat menyimpan akun kasir.');
    }

    header('Location: akun_kasir.php');
    exit;
}

$pageTitle = 'Akun Kasir';
$activePage = 'accounts';
$flash = takeFlash();
$error = null;
$cashiers = [];
try {
    $cashiers = $pdo->query(
        'SELECT id_kasir, username, nama_kasir, aktif, dibuat_pada
         FROM tbl_kasir ORDER BY nama_kasir'
    )->fetchAll();
} catch (PDOException $exception) {
    $error = databaseError($exception);
}

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Akses terbatas</div><h1>Akun Kasir</h1><p>Kasir hanya dapat memproses transaksi dan melihat transaksi hariannya sendiri.</p></div>
</section>
<?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<section class="panel section-card">
    <div class="panel-header"><div><h2>Buat akun kasir</h2><p class="panel-subtitle">Akun disimpan dengan password hash.</p></div></div>
    <div class="panel-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="field"><label for="cashier-name">Nama kasir</label><input id="cashier-name" name="name" maxlength="100" required></div>
            <div class="field"><label for="cashier-username">Username</label><input id="cashier-username" name="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]{3,50}" required></div>
            <div class="field"><label for="cashier-password">Kata sandi awal</label><input id="cashier-password" name="password" type="password" minlength="8" autocomplete="new-password" required></div>
            <div class="field full"><button class="button button-primary" type="submit">Buat akun</button></div>
        </form>
    </div>
</section>
<section class="panel">
    <div class="panel-header"><div><h2>Daftar kasir</h2><p class="panel-subtitle"><?= count($cashiers) ?> akun</p></div></div>
    <div class="table-wrap">
        <table><thead><tr><th>Nama</th><th>Username</th><th>Status</th><th>Terdaftar</th><th>Aksi</th></tr></thead><tbody>
        <?php if (!$cashiers): ?><tr><td class="table-empty" colspan="5"><?= $error ? 'Data tidak tersedia.' : 'Belum ada akun kasir.' ?></td></tr>
        <?php else: foreach ($cashiers as $cashier): ?>
            <tr>
                <td><strong><?= h($cashier['nama_kasir']) ?></strong></td><td><?= h($cashier['username']) ?></td>
                <td><span class="badge <?= (int) $cashier['aktif'] === 1 ? '' : 'orange' ?>"><?= (int) $cashier['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
                <td><?= h($cashier['dibuat_pada']) ?></td>
                <td><form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="cashier_id" value="<?= (int) $cashier['id_kasir'] ?>">
                    <input type="hidden" name="active" value="<?= (int) $cashier['aktif'] === 1 ? '0' : '1' ?>">
                    <button class="button button-secondary member-toggle" type="submit"><?= (int) $cashier['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody></table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
