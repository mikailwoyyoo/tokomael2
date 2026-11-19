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
$setupRequired = false;
$adminTableReady = false;
$loginAds = [];

try {
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM tbl_admin')->fetchColumn();
    $adminTableReady = true;
    if ($adminCount === 0) {
        header('Location: setup_admin.php');
        exit;
    }

    $loginAds = $pdo->query(
        'SELECT i.id_iklan, i.judul, i.deskripsi, i.teks_tombol, i.gambar_iklan,
                p.nama_produk, p.harga, p.gambar
         FROM tbl_iklan_dashboard i
         LEFT JOIN tbl_produk p ON p.id_produk = i.id_produk
         WHERE i.aktif = 1
         ORDER BY i.id_iklan'
    )->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        if (($_POST['login_mode'] ?? '') === 'card') {
            $uid = normalizeMemberCardUid(trim((string) ($_POST['card_uid'] ?? '')));
            $pin = (string) ($_POST['card_pin'] ?? '');
            if (preg_match('/^\d{6}$/D', $pin) !== 1) {
                throw new InvalidArgumentException('Masukkan PIN kartu yang terdiri dari 6 angka.');
            }

            $pdo->beginTransaction();
            $cardQuery = $pdo->prepare(
                'SELECT id_login_kartu, role, id_akun, pin_hash, percobaan_gagal, terkunci_sampai
                 FROM tbl_login_kartu
                 WHERE uid_kartu = ? AND aktif = 1
                 FOR UPDATE'
            );
            $cardQuery->execute([$uid]);
            $card = $cardQuery->fetch();
            if (!$card) {
                $pdo->rollBack();
                $error = 'Kartu atau PIN tidak cocok, atau akun sedang nonaktif.';
            } elseif ($card['terkunci_sampai'] && strtotime($card['terkunci_sampai']) > time()) {
                $pdo->rollBack();
                $error = 'Login kartu dikunci sementara karena terlalu banyak PIN salah. Coba kembali 15 menit lagi.';
            } else {
                $account = null;
                if ($card['role'] === 'admin') {
                    $accountQuery = $pdo->prepare('SELECT nama_admin AS nama FROM tbl_admin WHERE id_admin = ?');
                    $accountQuery->execute([(int) $card['id_akun']]);
                    $account = $accountQuery->fetch();
                } elseif ($card['role'] === 'cashier') {
                    $accountQuery = $pdo->prepare('SELECT nama_kasir AS nama FROM tbl_kasir WHERE id_kasir = ? AND aktif = 1');
                    $accountQuery->execute([(int) $card['id_akun']]);
                    $account = $accountQuery->fetch();
                } elseif ($card['role'] === 'member') {
                    $accountQuery = $pdo->prepare(
                        'SELECT m.id_pelanggan, p.nama_pelanggan AS nama
                         FROM tbl_member m JOIN tbl_pelanggan p ON p.id_pelanggan = m.id_pelanggan
                         WHERE m.id_member = ? AND m.aktif = 1'
                    );
                    $accountQuery->execute([(int) $card['id_akun']]);
                    $account = $accountQuery->fetch();
                }

                $pinValid = $account && password_verify($pin, $card['pin_hash']);
                if ($pinValid) {
                    $resetAttempts = $pdo->prepare(
                        'UPDATE tbl_login_kartu SET percobaan_gagal = 0, terkunci_sampai = NULL
                         WHERE id_login_kartu = ?'
                    );
                    $resetAttempts->execute([(int) $card['id_login_kartu']]);
                    $pdo->commit();

                    session_regenerate_id(true);
                    $_SESSION['auth_role'] = $card['role'];
                    $_SESSION['auth_id'] = (int) $card['id_akun'];
                    $_SESSION['auth_name'] = $account['nama'];
                    if ($card['role'] === 'admin') {
                        $_SESSION['admin_id'] = (int) $card['id_akun'];
                        $_SESSION['admin_name'] = $account['nama'];
                        header('Location: index.php');
                    } elseif ($card['role'] === 'cashier') {
                        unset($_SESSION['admin_id'], $_SESSION['admin_name']);
                        header('Location: kasir.php');
                    } else {
                        $_SESSION['auth_customer_id'] = (int) $account['id_pelanggan'];
                        unset($_SESSION['admin_id'], $_SESSION['admin_name']);
                        header('Location: dashboard_pelanggan.php');
                    }
                    unset($_SESSION['csrf_token']);
                    csrfToken();
                    exit;
                }

                if (!$pinValid) {
                    $failedAttempts = min(5, (int) $card['percobaan_gagal'] + 1);
                    $lockedUntil = $failedAttempts >= 5
                        ? date('Y-m-d H:i:s', time() + 900)
                        : null;
                    $recordFailure = $pdo->prepare(
                        'UPDATE tbl_login_kartu
                         SET percobaan_gagal = ?, terkunci_sampai = ?
                         WHERE id_login_kartu = ?'
                    );
                    $recordFailure->execute([$failedAttempts, $lockedUntil, (int) $card['id_login_kartu']]);
                    $pdo->commit();
                    $error = $failedAttempts >= 5
                        ? 'PIN salah 5 kali. Login kartu dikunci selama 15 menit.'
                        : 'Kartu atau PIN tidak cocok, atau akun sedang nonaktif.';
                }
            }
        } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $query = $pdo->prepare(
            'SELECT id_admin, username, nama_admin, password_hash
             FROM tbl_admin
             WHERE username = ?
             LIMIT 1'
        );
        $query->execute([$username]);
        $admin = $query->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['auth_role'] = 'admin';
            $_SESSION['auth_id'] = (int) $admin['id_admin'];
            $_SESSION['auth_name'] = $admin['nama_admin'];
            $_SESSION['admin_id'] = (int) $admin['id_admin'];
            $_SESSION['admin_name'] = $admin['nama_admin'];
            unset($_SESSION['csrf_token']);
            csrfToken();
            header('Location: index.php');
            exit;
        }

        $cashierQuery = $pdo->prepare(
            'SELECT id_kasir, nama_kasir, password_hash
             FROM tbl_kasir
             WHERE username = ? AND aktif = 1
             LIMIT 1'
        );
        $cashierQuery->execute([$username]);
        $cashier = $cashierQuery->fetch();
        if ($cashier && password_verify($password, $cashier['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['auth_role'] = 'cashier';
            $_SESSION['auth_id'] = (int) $cashier['id_kasir'];
            $_SESSION['auth_name'] = $cashier['nama_kasir'];
            unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['csrf_token']);
            csrfToken();
            header('Location: kasir.php');
            exit;
        }

        $memberQuery = $pdo->prepare(
            'SELECT id_member, id_pelanggan, username, password_hash
             FROM tbl_member
             WHERE username = ? AND aktif = 1 AND password_hash IS NOT NULL
             LIMIT 1'
        );
        $memberQuery->execute([$username]);
        $member = $memberQuery->fetch();
        if ($member && password_verify($password, $member['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['auth_role'] = 'member';
            $_SESSION['auth_id'] = (int) $member['id_member'];
            $_SESSION['auth_customer_id'] = (int) $member['id_pelanggan'];
            $_SESSION['auth_name'] = $username;
            unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['csrf_token']);
            csrfToken();
            header('Location: dashboard_pelanggan.php');
            exit;
        }

        $error = 'Username atau kata sandi salah.';
        }
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Admin login query failed: ' . $exception->getMessage());
    if (!$adminTableReady && (string) $exception->getCode() === '42S02') {
        $setupRequired = true;
        $error = 'Tabel login belum tersedia. Jalankan database_sql/upgrade_admin_and_banner.sql terlebih dahulu.';
    } elseif (in_array((string) $exception->getCode(), ['42S02', '42S22'], true)) {
        $error = 'Struktur login belum lengkap. Pastikan migrasi upgrade_barcode_and_login_ad.sql, upgrade_member_cards.sql, dan upgrade_roles_portal.sql sudah dijalankan.';
    } else {
        $error = 'Login belum dapat diproses. Periksa koneksi database, role, dan migrasi database_sql/upgrade_login_cards.sql.';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | MKLOYY</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=ui-polish-20261119">
</head>
<body class="auth-page">
    <main class="auth-layout">
        <section class="auth-ad-panel">
            <div class="auth-ad-slider" data-ad-slider aria-label="Iklan dan produk pilihan">
                <?php if ($loginAds): foreach ($loginAds as $index => $loginAd): ?>
                    <article class="auth-ad-slide" data-ad-slide>
                        <?php if (!empty($loginAd['gambar_iklan']) || !empty($loginAd['gambar'])): ?>
                            <img class="auth-ad-image" src="<?= h($loginAd['gambar_iklan'] ?: $loginAd['gambar']) ?>" alt="<?= h($loginAd['nama_produk'] ?? 'Iklan MKLOYY') ?>">
                        <?php else: ?>
                            <div class="auth-ad-art" aria-hidden="true"><span>MKLOYY</span><strong>Belanja kebutuhan<br>lebih mudah</strong></div>
                        <?php endif; ?>
                        <div class="auth-ad-shade"></div>
                        <div class="auth-ad-content">
                            <span class="auth-ad-kicker">PROMO MKLOYY · <?= sprintf('%02d', $index + 1) ?></span>
                            <h2><?= h($loginAd['judul']) ?></h2>
                            <p><?= h($loginAd['deskripsi']) ?></p>
                            <?php if (!empty($loginAd['nama_produk'])): ?>
                                <div class="auth-ad-product"><strong><?= h($loginAd['nama_produk']) ?></strong><span><?= h(rupiah($loginAd['harga'])) ?></span></div>
                            <?php endif; ?>
                            <a class="auth-ad-cta" href="#login-form"><?= h($loginAd['teks_tombol']) ?> <span aria-hidden="true">→</span></a>
                        </div>
                    </article>
                <?php endforeach; else: ?>
                    <article class="auth-ad-slide" data-ad-slide>
                        <div class="auth-ad-art" aria-hidden="true"><span>MKLOYY</span><strong>Belanja kebutuhan<br>lebih mudah</strong></div>
                        <div class="auth-ad-shade"></div>
                        <div class="auth-ad-content"><span class="auth-ad-kicker">SELAMAT DATANG</span><h2>Selamat datang di MKLOYY</h2><p>Belanja pilihan Anda dengan mudah dan nyaman.</p><a class="auth-ad-cta" href="#login-form">Masuk sekarang <span aria-hidden="true">→</span></a></div>
                    </article>
                <?php endif; ?>
            </div>
            <?php if (count($loginAds) > 1): ?>
                <div class="auth-ad-controls"><button type="button" aria-label="Iklan sebelumnya" data-slide-prev>←</button><div class="slider-dots" data-slide-dots><?php foreach ($loginAds as $index => $loginAd): ?><button type="button" aria-label="Tampilkan iklan <?= $index + 1 ?>" data-slide-dot="<?= $index ?>" class="<?= $index === 0 ? 'active' : '' ?>"></button><?php endforeach; ?></div><button type="button" aria-label="Iklan berikutnya" data-slide-next>→</button></div>
            <?php endif; ?>
        </section>
        <section class="auth-login-panel">
            <a class="auth-brand" href="login.php"><span class="brand-mark">M</span><span>MKLOYY</span></a>
            <div class="eyebrow">Area pengguna</div>
            <h1>Masuk ke akun</h1>
            <p class="auth-description">Masuk dengan username dan kata sandi, atau gunakan kartu RFID dan PIN.</p>
            <?php if ($error): ?>
                <div class="notice error"><?= h($error) ?></div>
                <?php if ($setupRequired): ?><a class="button button-primary auth-submit" href="setup_admin.php">Buka setup admin</a><?php endif; ?>
            <?php endif; ?>
            <?php if (!$setupRequired): ?>
                <form method="post" class="auth-form" id="login-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <label>Username<input name="username" type="text" autocomplete="username" maxlength="50" required autofocus></label>
                    <label>Kata sandi<input name="password" type="password" autocomplete="current-password" required></label>
                    <button class="button button-primary auth-submit" type="submit">Masuk</button>
                </form>
                <details class="card-login-details">
                    <summary>Login cepat dengan kartu RFID</summary>
                    <p>Scan UID kartu, lalu masukkan PIN 6 angka yang sudah didaftarkan Admin.</p>
                    <form method="post" class="auth-form card-login-form" id="card-login-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="login_mode" value="card">
                        <label>UID kartu RFID<input name="card_uid" type="text" maxlength="64" autocomplete="off" placeholder="Fokuskan kolom lalu tap kartu" data-login-scan required></label>
                        <label>PIN kartu<input name="card_pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" placeholder="6 angka" data-login-pin required></label>
                        <button class="button button-primary auth-submit" type="submit">Masuk dengan kartu</button>
                    </form>
                </details>
            <?php endif; ?>
            <p class="auth-login-footer">Akses Admin, Kasir, dan Pelanggan MKLOYY</p>
        </section>
    </main>
    <script src="assets/js/script.js"></script>
</body>
</html>
