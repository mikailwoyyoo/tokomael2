<?php

require __DIR__ . '/includes/helpers.php';
requireLogin();
require __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $role = (string) ($_POST['role'] ?? '');
        $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($role, ['admin', 'cashier', 'member'], true) || !$accountId || $accountId < 1) {
            throw new InvalidArgumentException('Akun yang dipilih tidak valid.');
        }

        if ($action === 'remove') {
            $remove = $pdo->prepare('DELETE FROM tbl_login_kartu WHERE role = ? AND id_akun = ?');
            $remove->execute([$role, $accountId]);
            setFlash('success', $remove->rowCount() ? 'Kartu login dihapus. RFID pelanggan untuk POS tetap tersimpan.' : 'Akun ini belum memiliki kartu login.');
        } elseif ($action === 'save') {
            $uid = normalizeMemberCardUid(trim((string) ($_POST['uid_kartu'] ?? '')));
            $pin = (string) ($_POST['pin'] ?? '');
            if (preg_match('/^\d{6}$/D', $pin) !== 1) {
                throw new InvalidArgumentException('PIN login kartu harus tepat 6 angka.');
            }

            if ($role === 'admin') {
                $accountQuery = $pdo->prepare('SELECT id_admin FROM tbl_admin WHERE id_admin = ?');
            } elseif ($role === 'cashier') {
                $accountQuery = $pdo->prepare('SELECT id_kasir FROM tbl_kasir WHERE id_kasir = ? AND aktif = 1');
            } else {
                $accountQuery = $pdo->prepare('SELECT id_member FROM tbl_member WHERE id_member = ? AND aktif = 1');
            }
            $accountQuery->execute([$accountId]);
            if (!$accountQuery->fetchColumn()) {
                throw new InvalidArgumentException('Akun tidak ditemukan atau sedang nonaktif.');
            }

            $save = $pdo->prepare(
                'INSERT INTO tbl_login_kartu (uid_kartu, role, id_akun, pin_hash, aktif, percobaan_gagal, terkunci_sampai)
                 VALUES (?, ?, ?, ?, 1, 0, NULL)
                 ON DUPLICATE KEY UPDATE
                    uid_kartu = VALUES(uid_kartu),
                    pin_hash = VALUES(pin_hash),
                    aktif = 1,
                    percobaan_gagal = 0,
                    terkunci_sampai = NULL'
            );
            $save->execute([$uid, $role, $accountId, password_hash($pin, PASSWORD_DEFAULT)]);
            setFlash('success', 'Kartu login berhasil ditautkan. Login kartu tetap memerlukan PIN.');
        } else {
            throw new InvalidArgumentException('Aksi kartu login tidak dikenal.');
        }
    } catch (InvalidArgumentException $exception) {
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log('Login card administration failed: ' . $exception->getMessage());
        setFlash('error', 'Kartu gagal disimpan. UID mungkin sudah ditautkan ke akun lain atau migrasi kartu login belum diterapkan.');
    } catch (Throwable $exception) {
        error_log('Unexpected login card administration error: ' . $exception->getMessage());
        setFlash('error', 'Terjadi kesalahan saat mengatur kartu login.');
    }

    header('Location: kartu_login.php');
    exit;
}

$pageTitle = 'Kartu Login';
$activePage = 'login-cards';
$flash = takeFlash();
$error = null;
$accounts = [];
$cards = [];

try {
    $accounts = $pdo->query(
        "SELECT 'admin' AS role, id_admin AS account_id, nama_admin AS nama
         FROM tbl_admin
         UNION ALL
         SELECT 'cashier', id_kasir, nama_kasir FROM tbl_kasir WHERE aktif = 1
         UNION ALL
         SELECT 'member', m.id_member, p.nama_pelanggan
         FROM tbl_member m JOIN tbl_pelanggan p ON p.id_pelanggan = m.id_pelanggan
         WHERE m.aktif = 1
         ORDER BY role, nama"
    )->fetchAll();
    $cards = $pdo->query(
        'SELECT role, id_akun, uid_kartu, aktif
         FROM tbl_login_kartu'
    )->fetchAll();
} catch (PDOException $exception) {
    $error = databaseError($exception);
}

$cardsByAccount = [];
foreach ($cards as $card) {
    $cardsByAccount[$card['role'] . ':' . $card['id_akun']] = $card;
}

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Login cepat & aman</div><h1>Kartu Login RFID</h1><p>Tautkan kartu reader USB kepada Admin, Kasir, atau Pelanggan. Setiap scan tetap dilindungi PIN 6 angka.</p></div>
</section>
<?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<div class="notice info">Keamanan: UID kartu saja tidak cukup untuk masuk. Login memerlukan kartu fisik dan PIN, serta akan terkunci 15 menit setelah 5 PIN salah. Jangan gunakan PIN yang sama untuk banyak akun.</div>
<section class="panel">
    <div class="panel-header"><div><h2>Akun aktif</h2><p class="panel-subtitle">Scan kartu ke kolom UID, lalu simpan PIN pribadi untuk akun tersebut.</p></div><span class="badge"><?= count($accounts) ?> akun</span></div>
    <div class="table-wrap">
        <table><thead><tr><th>Role</th><th>Nama akun</th><th>Status kartu</th><th>UID RFID & PIN</th><th>Aksi</th></tr></thead><tbody>
        <?php if (!$accounts): ?><tr><td class="table-empty" colspan="5"><?= $error ? 'Data tidak tersedia.' : 'Belum ada akun aktif.' ?></td></tr>
        <?php else: foreach ($accounts as $account): ?>
            <?php $accountKey = $account['role'] . ':' . $account['account_id']; $card = $cardsByAccount[$accountKey] ?? null; ?>
            <tr>
                <td><span class="badge <?= $account['role'] === 'admin' ? 'orange' : '' ?>"><?= h(ucfirst($account['role'])) ?></span></td>
                <td><strong><?= h($account['nama']) ?></strong></td>
                <td><?= $card ? '<span class="badge">Terdaftar</span>' : '<span class="member-city">Belum ditautkan</span>' ?></td>
                <td>
                    <form method="post" class="login-card-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="role" value="<?= h($account['role']) ?>">
                        <input type="hidden" name="account_id" value="<?= (int) $account['account_id'] ?>">
                        <input name="uid_kartu" maxlength="64" value="<?= h($card['uid_kartu'] ?? '') ?>" placeholder="Fokuskan lalu tap kartu" autocomplete="off" data-login-card-uid required>
                        <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="PIN baru · 6 angka" autocomplete="new-password" required>
                        <button class="button button-secondary" type="submit"><?= $card ? 'Perbarui kartu/PIN' : 'Tautkan kartu' ?></button>
                    </form>
                </td>
                <td>
                    <?php if ($card): ?>
                    <form method="post" data-confirm="Hapus kartu login akun ini? RFID member untuk kasir tidak ikut dihapus.">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="role" value="<?= h($account['role']) ?>">
                        <input type="hidden" name="account_id" value="<?= (int) $account['account_id'] ?>">
                        <button class="button button-danger" type="submit">Hapus kartu</button>
                    </form>
                    <?php else: ?><span class="member-city">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody></table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
