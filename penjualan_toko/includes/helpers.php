<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecureRequest = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isSecureRequest,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function databaseError(PDOException $exception): string
{
    error_log('Database query failed: ' . $exception->getMessage());
    return 'Data tidak dapat dimuat. Pastikan database dan objek tabel, view, prosedur, serta function sudah tersedia.';
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token): void
{
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        throw new InvalidArgumentException('Sesi formulir tidak valid. Muat ulang halaman, lalu coba lagi.');
    }
}

function normalizeMemberCardUid(string $value): string
{
    $uid = preg_replace('/[\s:-]+/', '', strtoupper(trim($value)));
    if (!is_string($uid) || preg_match('/^[A-Z0-9]{1,64}$/D', $uid) !== 1) {
        throw new InvalidArgumentException('UID RFID tidak valid. Gunakan 1 sampai 64 huruf atau angka.');
    }

    return $uid;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['auth_role'], $_SESSION['auth_id'], $_SESSION['auth_name'])
        || isset($_SESSION['admin_id'], $_SESSION['admin_name']);
}

function requireLogin(): void
{
    requireRole(['admin']);
}

function currentRole(): ?string
{
    if (isset($_SESSION['auth_role'])) {
        return (string) $_SESSION['auth_role'];
    }

    return isset($_SESSION['admin_id'], $_SESSION['admin_name']) ? 'admin' : null;
}

function currentUserId(): ?int
{
    if (isset($_SESSION['auth_id'])) {
        return (int) $_SESSION['auth_id'];
    }
    return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
}

function currentUserName(): string
{
    return (string) ($_SESSION['auth_name'] ?? $_SESSION['admin_name'] ?? '');
}

function requireRole(array $roles): void
{
    if (!in_array(currentRole(), $roles, true)) {
        header('Location: login.php');
        exit;
    }
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function takeFlash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function removeProductImage(?string $imagePath): void
{
    removeUploadedImage($imagePath, 'products');
}

function storeProductImage(array $file): string
{
    return storeUploadedImage($file, 'products');
}

function storeAdvertisementImage(array $file): string
{
    return storeUploadedImage($file, 'advertisements');
}

function removeAdvertisementImage(?string $imagePath): void
{
    removeUploadedImage($imagePath, 'advertisements');
}

function storeUploadedImage(array $file, string $folder): string
{
    if (!in_array($folder, ['products', 'advertisements', 'qris', 'qris_proofs'], true)) {
        throw new InvalidArgumentException('Lokasi gambar tidak valid.');
    }

    if (!isset($file['error']) || is_array($file['error'])) {
        throw new InvalidArgumentException('File gambar tidak valid.');
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Pilih gambar JPG, PNG, atau WEBP dengan ukuran maksimal 5 MB.');
    }

    if (!isset($file['tmp_name'], $file['size']) || $file['size'] < 1 || $file['size'] > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Ukuran gambar harus antara 1 byte dan 5 MB.');
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false || $imageInfo[0] > 6000 || $imageInfo[1] > 6000) {
        throw new InvalidArgumentException('File bukan gambar yang valid atau dimensinya terlalu besar.');
    }

    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    $imageType = $imageInfo[2];
    if (!isset($extensions[$imageType])) {
        throw new InvalidArgumentException('Format gambar yang didukung: JPG, PNG, atau WEBP.');
    }

    $directory = $folder === 'qris_proofs'
        ? privateUploadDirectory($folder)
        : dirname(__DIR__) . '/assets/uploads/' . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder penyimpanan gambar tidak dapat dibuat.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$imageType];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Gambar gagal disimpan ke folder upload.');
    }

    return $folder === 'qris_proofs'
        ? $folder . '/' . $filename
        : 'assets/uploads/' . $folder . '/' . $filename;
}

function removeUploadedImage(?string $imagePath, string $folder): void
{
    if ($folder === 'qris_proofs') {
        $prefix = 'qris_proofs/';
        if (!$imagePath || strpos($imagePath, $prefix) !== 0) {
            return;
        }

        $filename = substr($imagePath, strlen($prefix));
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $filename) !== 1) {
            return;
        }

        $fullPath = privateUploadDirectory($folder) . DIRECTORY_SEPARATOR . $filename;
        if (is_file($fullPath) && !unlink($fullPath)) {
            error_log('Unable to remove old private image: ' . $fullPath);
        }
        return;
    }

    $prefix = 'assets/uploads/' . $folder . '/';
    if (!$imagePath || strpos($imagePath, $prefix) !== 0) {
        return;
    }

    $filename = basename($imagePath);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return;
    }

    $fullPath = dirname(__DIR__) . '/assets/uploads/' . $folder . '/' . $filename;
    if (is_file($fullPath) && !unlink($fullPath)) {
        error_log('Unable to remove old uploaded image: ' . $fullPath);
    }
}

function privateUploadDirectory(string $folder): string
{
    if ($folder !== 'qris_proofs') {
        throw new InvalidArgumentException('Lokasi gambar privat tidak valid.');
    }

    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $storageRoot = $documentRoot !== ''
        ? dirname($documentRoot)
        : dirname(__DIR__, 4);

    return $storageRoot . DIRECTORY_SEPARATOR . 'tokokita_private_uploads'
        . DIRECTORY_SEPARATOR . $folder;
}

function productBarcode(int $productId): string
{
    if ($productId < 1 || $productId > 9999999999) {
        throw new InvalidArgumentException('ID produk di luar rentang barcode EAN-13.');
    }

    $payload = '20' . str_pad((string) $productId, 10, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($index = 0; $index < 12; $index++) {
        $sum += (int) $payload[$index] * ($index % 2 === 0 ? 1 : 3);
    }

    return $payload . (string) ((10 - ($sum % 10)) % 10);
}

function ensureProductBarcodes(PDO $pdo): void
{
    $missing = $pdo->query(
        "SELECT id_produk FROM tbl_produk WHERE barcode IS NULL OR barcode = ''"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$missing) {
        return;
    }

    $update = $pdo->prepare('UPDATE tbl_produk SET barcode = ? WHERE id_produk = ? AND (barcode IS NULL OR barcode = \'\')');
    foreach ($missing as $productId) {
        $update->execute([productBarcode((int) $productId), (int) $productId]);
    }
}

function isValidEan13(string $barcode): bool
{
    if (preg_match('/^\d{13}$/', $barcode) !== 1) {
        return false;
    }

    $sum = 0;
    for ($index = 0; $index < 12; $index++) {
        $sum += (int) $barcode[$index] * ($index % 2 === 0 ? 1 : 3);
    }

    return (10 - ($sum % 10)) % 10 === (int) $barcode[12];
}

function ean13Svg(string $barcode): string
{
    if (!isValidEan13($barcode)) {
        throw new InvalidArgumentException('Digit pemeriksa barcode EAN-13 tidak valid.');
    }

    $leftPatterns = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];
    $rightPatterns = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];
    $parityPatterns = [
        'LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG',
        'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL',
    ];

    $bits = '101';
    $parity = $parityPatterns[(int) $barcode[0]];
    for ($index = 1; $index <= 6; $index++) {
        $digit = (int) $barcode[$index];
        $bits .= $parity[$index - 1] === 'L'
            ? $leftPatterns[$digit]
            : strrev(strtr($leftPatterns[$digit], '01', '10'));
    }
    $bits .= '01010';
    for ($index = 7; $index <= 12; $index++) {
        $bits .= $rightPatterns[(int) $barcode[$index]];
    }
    $bits .= '101';

    $bars = '';
    $inGuard = static function (int $index): bool {
        return $index < 3 || ($index >= 45 && $index < 50) || $index >= 92;
    };
    for ($index = 0, $length = strlen($bits); $index < $length; $index++) {
        if ($bits[$index] === '1') {
            $height = $inGuard($index) ? 70 : 62;
            $bars .= '<rect x="' . ($index + 9) . '" y="0" width="1" height="' . $height . '"/>';
        }
    }

    $readable = h($barcode);
    return '<svg class="ean13-barcode" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 113 82" role="img" aria-label="Barcode EAN-13 ' . $readable . '" shape-rendering="crispEdges">'
        . '<rect width="113" height="82" fill="#fff"/><g fill="#111">' . $bars . '</g>'
        . '<text x="56.5" y="80" text-anchor="middle" font-family="Arial,sans-serif" font-size="8">' . $readable . '</text></svg>';
}

function code128Svg(string $value): string
{
    if ($value === '' || strlen($value) > 40 || preg_match('/^[\x20-\x7E]+$/D', $value) !== 1) {
        throw new InvalidArgumentException('Teks barcode Code 128 harus berisi 1 sampai 40 karakter ASCII yang dapat dicetak.');
    }

    $patterns = explode(' ', '212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 211214 211232 2331112');
    $codes = [104];
    for ($index = 0, $length = strlen($value); $index < $length; $index++) {
        $character = ord($value[$index]);
        if ($character < 32 || $character > 126) {
            throw new InvalidArgumentException('Barcode Code 128 hanya menerima karakter ASCII yang dapat dicetak.');
        }
        $codes[] = $character - 32;
    }

    $checksum = $codes[0];
    for ($index = 1, $length = count($codes); $index < $length; $index++) {
        $checksum += $codes[$index] * $index;
    }
    $codes[] = $checksum % 103;
    $codes[] = 106;

    $quietZone = 10;
    $module = $quietZone;
    $bars = '';
    foreach ($codes as $code) {
        if (!isset($patterns[$code])) {
            throw new LogicException('Pola simbol barcode Code 128 tidak tersedia.');
        }
        $pattern = $patterns[$code];
        for ($index = 0, $length = strlen($pattern); $index < $length; $index++) {
            $width = (int) $pattern[$index];
            if ($index % 2 === 0) {
                $bars .= '<rect x="' . $module . '" y="0" width="' . $width . '" height="34"/>';
            }
            $module += $width;
        }
    }

    $viewWidth = $module + $quietZone;
    $readable = h($value);
    return '<svg class="receipt-code128" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $viewWidth . ' 48" role="img" aria-label="Barcode Code 128 ' . $readable . '" shape-rendering="crispEdges">'
        . '<rect width="' . $viewWidth . '" height="48" fill="#fff"/><g fill="#111">' . $bars . '</g>'
        . '<text x="' . ($viewWidth / 2) . '" y="46" text-anchor="middle" font-family="Arial,sans-serif" font-size="9">' . $readable . '</text></svg>';
}

function locationQrSvg(string $value): string
{
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
    $length = strlen($value);
    if ($length < 1 || $length > 25 || strspn($value, $alphabet) !== $length) {
        throw new InvalidArgumentException('Kode lokasi QR harus berisi 1 sampai 25 karakter QR alfanumerik.');
    }

    $bits = '0010' . str_pad(decbin($length), 9, '0', STR_PAD_LEFT);
    for ($index = 0; $index + 1 < $length; $index += 2) {
        $pair = strpos($alphabet, $value[$index]) * 45 + strpos($alphabet, $value[$index + 1]);
        $bits .= str_pad(decbin($pair), 11, '0', STR_PAD_LEFT);
    }
    if ($length % 2 === 1) {
        $bits .= str_pad(decbin(strpos($alphabet, $value[$length - 1])), 6, '0', STR_PAD_LEFT);
    }

    $bits .= str_repeat('0', min(4, 152 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $data = [];
    foreach (str_split($bits, 8) as $byte) {
        $data[] = bindec($byte);
    }
    for ($pad = 0; count($data) < 19; $pad++) {
        $data[] = $pad % 2 === 0 ? 236 : 17;
    }

    $multiply = static function (int $left, int $right): int {
        $product = 0;
        while ($right > 0) {
            if ($right & 1) {
                $product ^= $left;
            }
            $left <<= 1;
            if ($left & 256) {
                $left ^= 0x11D;
            }
            $right >>= 1;
        }
        return $product;
    };

    $generator = [1];
    $root = 1;
    for ($degree = 0; $degree < 7; $degree++) {
        $next = array_fill(0, count($generator) + 1, 0);
        foreach ($generator as $index => $coefficient) {
            $next[$index] ^= $coefficient;
            $next[$index + 1] ^= $multiply($coefficient, $root);
        }
        $generator = $next;
        $root = $multiply($root, 2);
    }

    $remainder = array_merge($data, array_fill(0, 7, 0));
    for ($index = 0; $index < 19; $index++) {
        $factor = $remainder[$index];
        if ($factor === 0) {
            continue;
        }
        foreach ($generator as $offset => $coefficient) {
            $remainder[$index + $offset] ^= $multiply($coefficient, $factor);
        }
    }
    $codewords = array_merge($data, array_slice($remainder, 19));
    $stream = '';
    foreach ($codewords as $codeword) {
        $stream .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
    }

    $size = 21;
    $matrix = array_fill(0, $size, array_fill(0, $size, null));
    $set = static function (int $row, int $column, bool $dark) use (&$matrix, $size): void {
        if ($row >= 0 && $row < $size && $column >= 0 && $column < $size) {
            $matrix[$row][$column] = $dark;
        }
    };

    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
        for ($row = -1; $row <= 7; $row++) {
            for ($column = -1; $column <= 7; $column++) {
                $distance = max(abs($row - 3), abs($column - 3));
                $set($top + $row, $left + $column, $distance !== 2 && $distance !== 4);
            }
        }
    }
    for ($index = 8; $index < $size - 8; $index++) {
        $set(6, $index, $index % 2 === 0);
        $set($index, 6, $index % 2 === 0);
    }
    $set($size - 8, 8, true);
    for ($bit = 0; $bit <= 5; $bit++) {
        $set($bit, 8, false);
    }
    $set(7, 8, false);
    $set(8, 8, false);
    $set(8, 7, false);
    for ($bit = 9; $bit < 15; $bit++) {
        $set(8, 14 - $bit, false);
    }
    for ($bit = 0; $bit < 8; $bit++) {
        $set(8, $size - 1 - $bit, false);
    }
    for ($bit = 8; $bit < 15; $bit++) {
        $set($size - 15 + $bit, 8, false);
    }

    $bitIndex = 0;
    $upward = true;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vertical = 0; $vertical < $size; $vertical++) {
            $row = $upward ? $size - 1 - $vertical : $vertical;
            for ($offset = 0; $offset < 2; $offset++) {
                $column = $right - $offset;
                if ($matrix[$row][$column] !== null) {
                    continue;
                }
                $dark = $bitIndex < strlen($stream) && $stream[$bitIndex] === '1';
                $bitIndex++;
                if (($row + $column) % 2 === 0) {
                    $dark = !$dark;
                }
                $matrix[$row][$column] = $dark;
            }
        }
        $upward = !$upward;
    }

    $formatData = 8;
    $formatRemainder = $formatData << 10;
    for ($bit = 14; $bit >= 10; $bit--) {
        if (($formatRemainder >> $bit) & 1) {
            $formatRemainder ^= 0x537 << ($bit - 10);
        }
    }
    $format = (($formatData << 10) | $formatRemainder) ^ 0x5412;
    for ($bit = 0; $bit <= 5; $bit++) {
        $set($bit, 8, (($format >> $bit) & 1) !== 0);
    }
    $set(7, 8, (($format >> 6) & 1) !== 0);
    $set(8, 8, (($format >> 7) & 1) !== 0);
    $set(8, 7, (($format >> 8) & 1) !== 0);
    for ($bit = 9; $bit < 15; $bit++) {
        $set(8, 14 - $bit, (($format >> $bit) & 1) !== 0);
    }
    for ($bit = 0; $bit < 8; $bit++) {
        $set(8, $size - 1 - $bit, (($format >> $bit) & 1) !== 0);
    }
    for ($bit = 8; $bit < 15; $bit++) {
        $set($size - 15 + $bit, 8, (($format >> $bit) & 1) !== 0);
    }

    $path = '';
    for ($row = 0; $row < $size; $row++) {
        for ($column = 0; $column < $size; $column++) {
            if ($matrix[$row][$column] === true) {
                $path .= 'M' . ($column + 4) . ' ' . ($row + 4) . 'h1v1h-1z';
            }
        }
    }
    $readable = h($value);
    return '<svg class="receipt-location-qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29" role="img" aria-label="QR code lokasi ' . $readable . '" shape-rendering="crispEdges">'
        . '<rect width="29" height="29" fill="#fff"/><path d="' . $path . '" fill="#111"/></svg>';
}
