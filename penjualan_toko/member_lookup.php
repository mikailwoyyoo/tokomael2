<?php

require __DIR__ . '/includes/helpers.php';
header('Content-Type: application/json; charset=utf-8');

function respondMemberLookup(int $status, array $response): void
{
    http_response_code($status);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!in_array(currentRole(), ['admin', 'cashier'], true)) {
    respondMemberLookup(403, ['message' => 'Fitur scan member hanya tersedia untuk Admin dan Kasir.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respondMemberLookup(405, ['message' => 'Metode permintaan tidak didukung.']);
}

try {
    verifyCsrfToken($_POST['csrf_token'] ?? null);
    $cardValue = trim((string) ($_POST['card_value'] ?? $_POST['card_code'] ?? ''));
    if (preg_match('/^MBR[A-F0-9]{22}$/iD', $cardValue) === 1) {
        $cardCode = strtoupper($cardValue);
        $rfidUid = '';
    } else {
        $cardCode = '';
        $rfidUid = normalizeMemberCardUid($cardValue);
    }

    require __DIR__ . '/config/database.php';
    $lookup = $pdo->prepare(
        'SELECT m.card_code, m.rfid_uid, m.diskon_persen, p.id_pelanggan, p.nama_pelanggan
         FROM tbl_member m
         JOIN tbl_pelanggan p ON p.id_pelanggan = m.id_pelanggan
         WHERE (m.card_code = ? OR m.rfid_uid = ?) AND m.aktif = 1'
    );
    $lookup->execute([$cardCode, $rfidUid]);
    $member = $lookup->fetch();
    if (!$member) {
        respondMemberLookup(404, ['message' => 'Kartu member tidak aktif atau tidak ditemukan.']);
    }

    respondMemberLookup(200, [
        'card_code' => $member['card_code'],
        'rfid_uid' => $member['rfid_uid'],
        'customer_id' => (int) $member['id_pelanggan'],
        'customer_name' => $member['nama_pelanggan'],
        'discount_percent' => (float) $member['diskon_persen'],
    ]);
} catch (InvalidArgumentException $exception) {
    respondMemberLookup(400, ['message' => $exception->getMessage()]);
} catch (PDOException $exception) {
    error_log('Member lookup failed: ' . $exception->getMessage());
    respondMemberLookup(500, ['message' => 'Kartu member gagal diperiksa. Pastikan migrasi database sudah diterapkan.']);
} catch (Throwable $exception) {
    error_log('Unexpected member lookup error: ' . $exception->getMessage());
    respondMemberLookup(500, ['message' => 'Terjadi kesalahan saat memeriksa kartu member.']);
}
