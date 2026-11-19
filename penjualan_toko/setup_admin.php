<?php

require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/config/database.php';

if (isLoggedIn()) {
    $destination = [
        'admin' => 'index.php',
        'cashier' => 'kasir.php',
        'member' => 'dashboard_pelanggan.php',
    ][currentRole()] ?? 'login.php';
    header('Location: ' . $destination);
    exit;
}

$error = null;
$alreadyConfigured = false;

try {
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM tbl_admin')->fetchColumn();
    if ($adminCount > 0) {
        $alreadyConfigured = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (preg_match('/^.{1,100}$/us', $name) !== 1
            || preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) !== 1
            || strlen($password) < 12 || strlen($password) > 200
            || $password !== $confirmation) {
            throw new InvalidArgumentException('Isi nama, username 3–50 karakter (huruf/angka/._-), kata sandi minimal 12 karakter, dan konfirmasi yang sama.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO tbl_admin (id_admin, username, nama_admin, password_hash)
             VALUES (1, ?, ?, ?)'
        );
        $insert->execute([$username, $name, password_hash($password, PASSWORD_DEFAULT)]);

        session_regenerate_id(true);
        $_SESSION['auth_role'] = 'admin';
        $_SESSION['auth_id'] = 1;
        $_SESSION['auth_name'] = $name;
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_name'] = $name;
        unset($_SESSION['csrf_token']);
        csrfToken();
        header('Location: index.php');
        exit;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    error_log('Initial admin setup failed: ' . $exception->getMessage());
    if ((string) $exception->getCode() === '42S02') {
        $error = 'Tabel admin belum tersedia. Jalankan database_sql/upgrade_admin_and_banner.sql terlebih dahulu.';
    } elseif ((string) $exception->getCode() === '23000') {
        $alreadyConfigured = true;
    } else {
        $error = 'Admin tidak dapat dibuat. Periksa koneksi database.';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup Admin | MKLOYY</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="auth-brand" href="login.php"><span class="brand-mark">M</span><span>MKLOYY</span></a>
        <?php if ($alreadyConfigured): ?>
            <div class="eyebrow">Setup selesai</div>
            <h1>Akun admin sudah ada</h1>
            <p class="auth-description">Setup awal hanya bisa digunakan sekali.</p>
            <a class="button button-primary auth-submit" href="login.php">Ke halaman login</a>
        <?php else: ?>
            <div class="eyebrow">Pengaturan awal</div>
            <h1>Buat akun administrator</h1>
            <p class="auth-description">Buat akun admin Anda sendiri. Halaman setup akan terkunci setelah akun dibuat.</p>
            <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <label>Nama administrator<input name="name" type="text" maxlength="100" autocomplete="name" required></label>
                <label>Username<input name="username" type="text" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" autocomplete="username" required></label>
                <label>Kata sandi (minimal 12 karakter)<input name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label>
                <label>Ulangi kata sandi<input name="password_confirmation" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label>
                <button class="button button-primary auth-submit" type="submit">Buat akun admin</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
