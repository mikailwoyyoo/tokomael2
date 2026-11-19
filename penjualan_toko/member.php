<?php

require __DIR__ . '/includes/helpers.php';
requireLogin();
require __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);
            $discountPercent = filter_var($_POST['discount_percent'] ?? 5, FILTER_VALIDATE_FLOAT);
            if (!$customerId || $customerId < 1 || $discountPercent === false
                || $discountPercent < 0 || $discountPercent > 100) {
                throw new InvalidArgumentException('Pilih pelanggan dan masukkan diskon antara 0 sampai 100%.');
            }

            $rfidUid = trim((string) ($_POST['rfid_uid'] ?? ''));
            $rfidUid = $rfidUid !== '' ? normalizeMemberCardUid($rfidUid) : null;
            $cardCode = 'MBR' . strtoupper(bin2hex(random_bytes(11)));
            $create = $pdo->prepare(
                'INSERT INTO tbl_member (id_pelanggan, card_code, rfid_uid, diskon_persen) VALUES (?, ?, ?, ?)'
            );
            $create->execute([$customerId, $cardCode, $rfidUid, $discountPercent]);
            setFlash('success', 'Kartu member berhasil dibuat. Kode kartu: ' . $cardCode);
        } elseif ($action === 'bind_rfid') {
            $memberId = filter_var($_POST['member_id'] ?? null, FILTER_VALIDATE_INT);
            $rfidUid = normalizeMemberCardUid(trim((string) ($_POST['rfid_uid'] ?? '')));
            if (!$memberId || $memberId < 1) {
                throw new InvalidArgumentException('Data member tidak valid.');
            }

            $bind = $pdo->prepare('UPDATE tbl_member SET rfid_uid = ? WHERE id_member = ?');
            $bind->execute([$rfidUid, $memberId]);
            if ($bind->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM tbl_member WHERE id_member = ?');
                $exists->execute([$memberId]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('Member tidak ditemukan.');
                }
            }
            setFlash('success', 'Kartu RFID berhasil ditautkan ke member.');
        } elseif ($action === 'set_credentials') {
            $memberId = filter_var($_POST['member_id'] ?? null, FILTER_VALIDATE_INT);
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (!$memberId || $memberId < 1
                || preg_match('/^[A-Za-z0-9._-]{3,50}$/D', $username) !== 1
                || ($password !== '' && strlen($password) < 8)) {
                throw new InvalidArgumentException('Username harus 3-50 karakter; kata sandi baru minimal 8 karakter.');
            }

            $usernameCheck = $pdo->prepare(
                'SELECT username FROM tbl_admin WHERE username = ?
                 UNION ALL SELECT username FROM tbl_kasir WHERE username = ?
                 UNION ALL SELECT username FROM tbl_member WHERE username = ? AND id_member <> ?
                 LIMIT 1'
            );
            $usernameCheck->execute([$username, $username, $username, $memberId]);
            if ($usernameCheck->fetchColumn()) {
                throw new InvalidArgumentException('Username sudah digunakan oleh akun lain.');
            }

            if ($password !== '') {
                $saveCredentials = $pdo->prepare(
                    'UPDATE tbl_member SET username = ?, password_hash = ? WHERE id_member = ?'
                );
                $saveCredentials->execute([$username, password_hash($password, PASSWORD_DEFAULT), $memberId]);
            } else {
                $saveCredentials = $pdo->prepare(
                    'UPDATE tbl_member SET username = ? WHERE id_member = ?'
                );
                $saveCredentials->execute([$username, $memberId]);
            }
            if ($saveCredentials->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM tbl_member WHERE id_member = ?');
                $exists->execute([$memberId]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('Member tidak ditemukan.');
                }
            }
            setFlash('success', 'Akun portal member berhasil diperbarui.');
        } elseif ($action === 'toggle') {
            $memberId = filter_var($_POST['member_id'] ?? null, FILTER_VALIDATE_INT);
            $active = filter_var($_POST['active'] ?? null, FILTER_VALIDATE_INT);
            if (!$memberId || $memberId < 1 || !in_array($active, [0, 1], true)) {
                throw new InvalidArgumentException('Data perubahan status member tidak valid.');
            }

            $update = $pdo->prepare('UPDATE tbl_member SET aktif = ? WHERE id_member = ?');
            $update->execute([$active, $memberId]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('Member tidak ditemukan atau statusnya sudah berubah.');
            }
            setFlash('success', $active === 1 ? 'Kartu member diaktifkan.' : 'Kartu member dinonaktifkan.');
        } else {
            throw new InvalidArgumentException('Aksi member tidak dikenal.');
        }
    } catch (InvalidArgumentException $exception) {
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        error_log('Member update failed: ' . $exception->getMessage());
        setFlash('error', 'Member gagal disimpan. Pelanggan mungkin sudah terdaftar atau UID RFID sudah ditautkan ke member lain.');
    } catch (Throwable $exception) {
        error_log('Unexpected member update error: ' . $exception->getMessage());
        setFlash('error', 'Terjadi kesalahan saat menyimpan kartu member.');
    }

    header('Location: member.php');
    exit;
}

$pageTitle = 'Member';
$activePage = 'member';
$flash = takeFlash();
$error = null;
$customers = [];
$members = [];

try {
    $customers = $pdo->query(
        'SELECT p.id_pelanggan, p.nama_pelanggan
         FROM tbl_pelanggan p
         LEFT JOIN tbl_member m ON m.id_pelanggan = p.id_pelanggan
         WHERE m.id_member IS NULL
         ORDER BY p.nama_pelanggan'
    )->fetchAll();
    $members = $pdo->query(
        'SELECT m.id_member, m.card_code, m.rfid_uid, m.username, m.diskon_persen, m.aktif, m.dibuat_pada,
                p.nama_pelanggan, p.kota
         FROM tbl_member m
         JOIN tbl_pelanggan p ON p.id_pelanggan = m.id_pelanggan
         ORDER BY m.id_member DESC'
    )->fetchAll();
} catch (PDOException $exception) {
    $error = databaseError($exception);
}

require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <div><div class="eyebrow">Program loyalitas</div><h1>Kartu Member</h1><p>Daftarkan UID dari kartu RFID untuk scan otomatis di kasir.</p></div>
    <a class="button button-primary" href="#buat-member">＋ Buat Kartu Member</a>
</section>
<?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<section class="panel section-card" id="buat-member">
    <div class="panel-header"><div><h2>Daftarkan Member</h2><p class="panel-subtitle">Scan kartu RFID ke kolom UID sebelum menyimpan, atau tautkan kartu nanti.</p></div></div>
    <div class="panel-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="field"><label for="member-customer">Pelanggan</label>
                <select id="member-customer" name="customer_id" required>
                    <option value="">Pilih pelanggan</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int) $customer['id_pelanggan'] ?>"><?= h($customer['nama_pelanggan']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="member-discount">Diskon member</label>
                <div class="member-discount-input"><input id="member-discount" name="discount_percent" type="number" min="0" max="100" step="0.01" value="5" required><span>%</span></div>
            </div>
            <div class="field full"><label for="member-rfid-uid">UID kartu RFID <span class="field-optional">(scan kartu, opsional)</span></label>
                <input id="member-rfid-uid" name="rfid_uid" maxlength="64" autocomplete="off" placeholder="Fokuskan kolom ini, lalu tap kartu RFID" data-rfid-input>
                <small class="rfid-scan-status" role="status" aria-live="polite" data-rfid-status>Reader USB keyboard akan mengisi UID saat kartu ditap.</small>
            </div>
            <div class="field full"><button class="button button-primary" type="submit" <?= !$customers ? 'disabled' : '' ?>>Buat Kartu</button></div>
        </form>
    </div>
</section>
<section class="panel">
    <div class="panel-header"><div><h2>Daftar Kartu Member</h2><p class="panel-subtitle"><?= count($members) ?> kartu terdaftar</p></div></div>
    <div class="table-wrap">
        <table><thead><tr><th>Pelanggan</th><th>ID / Barcode</th><th>QR kartu</th><th>UID RFID</th><th>Akun portal</th><th>Diskon</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php if (!$members): ?><tr><td class="table-empty" colspan="8"><?= $error ? 'Data tidak tersedia.' : 'Belum ada kartu member.' ?></td></tr>
            <?php else: foreach ($members as $member): ?>
                <tr>
                    <td><strong><?= h($member['nama_pelanggan']) ?></strong><br><span class="member-city"><?= h($member['kota']) ?></span></td>
                    <td class="member-barcode-cell"><?= code128Svg($member['card_code']) ?></td>
                    <td class="member-qr-cell"><?= locationQrSvg($member['card_code']) ?></td>
                    <td>
                        <form method="post" class="rfid-bind-form">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="bind_rfid">
                            <input type="hidden" name="member_id" value="<?= (int) $member['id_member'] ?>">
                            <input name="rfid_uid" maxlength="64" value="<?= h($member['rfid_uid']) ?>" autocomplete="off" placeholder="Scan UID RFID" required data-rfid-input>
                            <button class="button button-secondary member-toggle" type="submit"><?= $member['rfid_uid'] ? 'Perbarui' : 'Tautkan' ?></button>
                        </form>
                    </td>
                    <td>
                        <form method="post" class="rfid-bind-form">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="set_credentials">
                            <input type="hidden" name="member_id" value="<?= (int) $member['id_member'] ?>">
                            <input name="username" maxlength="50" value="<?= h($member['username']) ?>" placeholder="Username" pattern="[A-Za-z0-9._-]{3,50}" required>
                            <input name="password" type="password" minlength="8" placeholder="<?= $member['username'] ? 'Kosongkan agar tidak berubah' : 'Password awal min. 8 karakter' ?>" <?= $member['username'] ? '' : 'required' ?>>
                            <button class="button button-secondary member-toggle" type="submit"><?= $member['username'] ? 'Simpan akun' : 'Buat akun' ?></button>
                        </form>
                    </td>
                    <td><?= h(number_format((float) $member['diskon_persen'], 2, ',', '.')) ?>%</td>
                    <td><span class="badge <?= (int) $member['aktif'] === 1 ? '' : 'orange' ?>"><?= (int) $member['aktif'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="member_id" value="<?= (int) $member['id_member'] ?>">
                            <input type="hidden" name="active" value="<?= (int) $member['aktif'] === 1 ? '0' : '1' ?>">
                            <button class="button button-secondary member-toggle" type="submit"><?= (int) $member['aktif'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
