<?php

function duitkuConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/duitku.php';
    }

    return $config;
}

function assertDuitkuConfigured(): array
{
    $config = assertDuitkuCredentialsConfigured();

    $baseUrl = filter_var($config['base_url'], FILTER_VALIDATE_URL);
    $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) === 'domain-publik-anda') {
        throw new RuntimeException('DUITKU_BASE_URL harus URL HTTPS publik yang dapat menerima callback.');
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new RuntimeException('Callback Duitku tidak dapat mengakses localhost. Gunakan domain HTTPS atau tunnel publik.');
    }

    return $config;
}

function assertDuitkuCredentialsConfigured(): array
{
    $config = duitkuConfig();
    foreach (['merchant_code', 'api_key'] as $requiredKey) {
        if ($config[$requiredKey] === '' || strpos($config[$requiredKey], 'ISI_') === 0) {
            throw new RuntimeException(
                'Metode pembayaran belum dapat dimuat karena kredensial Duitku belum diatur. Salin config/duitku.local.php.example menjadi config/duitku.local.php, lalu isi Merchant Code dan API Key sandbox dari akun Duitku.'
            );
        }
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('Ekstensi PHP cURL harus diaktifkan untuk menghubungkan Duitku.');
    }

    return $config;
}

function duitkuApiUrl(string $path): string
{
    $config = duitkuConfig();
    $host = $config['environment'] === 'production'
        ? 'https://passport.duitku.com/webapi/api/merchant'
        : 'https://sandbox.duitku.com/webapi/api/merchant';

    return $host . '/' . ltrim($path, '/');
}

function duitkuPostJson(string $path, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        throw new RuntimeException('Permintaan Duitku gagal disiapkan.');
    }

    $curl = curl_init(duitkuApiUrl($path));
    if ($curl === false) {
        throw new RuntimeException('Koneksi Duitku gagal disiapkan.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $responseBody = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($responseBody)) {
        error_log('Duitku request transport failed: ' . $curlError);
        throw new RuntimeException('Tidak dapat terhubung ke Duitku. Coba kembali beberapa saat lagi.');
    }

    $response = json_decode($responseBody, true);
    if (!is_array($response)) {
        error_log('Duitku returned invalid JSON with HTTP status ' . $httpStatus . '.');
        throw new RuntimeException('Respons Duitku tidak valid.');
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $message = (string) ($response['Message'] ?? $response['statusMessage'] ?? 'Permintaan ditolak.');
        error_log('Duitku request failed with HTTP status ' . $httpStatus . ': ' . $message);
        throw new RuntimeException('Duitku menolak permintaan pembayaran: ' . $message);
    }

    return $response;
}

function duitkuCreateInvoice(
    string $merchantOrderId,
    int $amount,
    string $customerName,
    string $customerEmail,
    string $customerPhone,
    array $itemDetails,
    string $paymentMethod
): array {
    $config = assertDuitkuConfigured();
    if ($amount < 1
        || $merchantOrderId === ''
        || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)
        || preg_match('/^[A-Z0-9]{2}$/D', $paymentMethod) !== 1) {
        throw new InvalidArgumentException('Nominal, nomor pesanan, atau email untuk pembayaran tidak valid.');
    }

    $signature = hash_hmac(
        'sha256',
        $config['merchant_code'] . $merchantOrderId . $amount,
        $config['api_key']
    );
    $callbackUrl = $config['base_url'] . '/duitku_callback.php';
    $returnUrl = $config['base_url'] . '/duitku_return.php?merchantOrderId=' . rawurlencode($merchantOrderId);
    if (strlen($callbackUrl) > 255 || strlen($returnUrl) > 255) {
        throw new RuntimeException('URL callback atau return Duitku melebihi batas 255 karakter.');
    }
    $payload = [
        'merchantCode' => $config['merchant_code'],
        'paymentAmount' => $amount,
        'paymentMethod' => $paymentMethod,
        'merchantOrderId' => $merchantOrderId,
        'productDetails' => 'Pesanan online MKLOYY ' . $merchantOrderId,
        'additionalParam' => '',
        'merchantUserInfo' => $merchantOrderId,
        'customerVaName' => function_exists('mb_substr')
            ? mb_substr($customerName, 0, 20, 'UTF-8')
            : substr($customerName, 0, 20),
        'email' => $customerEmail,
        'phoneNumber' => $customerPhone,
        'itemDetails' => $itemDetails,
        'callbackUrl' => $callbackUrl,
        'returnUrl' => $returnUrl,
        'signature' => $signature,
        'expiryPeriod' => $config['expiry_period'],
    ];

    $response = duitkuPostJson('v2/inquiry', $payload);
    $paymentUrl = (string) ($response['paymentUrl'] ?? '');
    $paymentUrlParts = parse_url($paymentUrl);
    if ((string) ($response['statusCode'] ?? '') !== '00'
        || !isset($response['paymentUrl'], $response['reference'])
        || !filter_var($paymentUrl, FILTER_VALIDATE_URL)
        || !is_array($paymentUrlParts)
        || strtolower((string) ($paymentUrlParts['scheme'] ?? '')) !== 'https') {
        $message = (string) ($response['statusMessage'] ?? $response['responseMessage'] ?? 'Invoice Duitku tidak berhasil dibuat.');
        throw new RuntimeException($message);
    }

    return $response;
}

function duitkuGetPaymentMethods(int $amount = 10000): array
{
    $config = assertDuitkuCredentialsConfigured();
    if ($amount < 1) {
        throw new InvalidArgumentException('Nominal untuk memuat metode pembayaran Duitku harus lebih dari nol.');
    }
    $datetime = date('Y-m-d H:i:s');
    $signature = hash_hmac(
        'sha256',
        $config['merchant_code'] . $amount . $datetime,
        $config['api_key']
    );

    $response = duitkuPostJson('paymentmethod/getpaymentmethod', [
        'merchantcode' => $config['merchant_code'],
        'amount' => $amount,
        'datetime' => $datetime,
        'signature' => $signature,
    ]);
    if ((string) ($response['responseCode'] ?? '') !== '00' || !isset($response['paymentFee'])) {
        throw new RuntimeException((string) ($response['responseMessage'] ?? 'Metode pembayaran Duitku tidak dapat dimuat.'));
    }

    return is_array($response['paymentFee']) ? $response['paymentFee'] : [];
}

function duitkuGetNonCashPaymentMethods(int $amount): array
{
    return duitkuFilterNonCashPaymentMethods(duitkuGetPaymentMethods($amount));
}

function duitkuFilterNonCashPaymentMethods(array $paymentMethods): array
{
    $cashIndicators = ['cash', 'tunai', 'indomaret', 'alfamart', 'convenience', 'payment point', 'counter', 'pos indonesia'];
    $methods = [];
    foreach ($paymentMethods as $method) {
        if (!is_array($method)) {
            continue;
        }

        $code = strtoupper(trim((string) ($method['paymentMethod'] ?? '')));
        $name = trim((string) ($method['paymentName'] ?? ''));
        if (preg_match('/^[A-Z0-9]{2}$/D', $code) !== 1 || $name === '') {
            continue;
        }

        $normalizedName = strtolower($name);
        foreach ($cashIndicators as $cashIndicator) {
            if (strpos($normalizedName, $cashIndicator) !== false) {
                continue 2;
            }
        }

        $methods[] = [
            'code' => $code,
            'name' => function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80),
        ];
    }

    return $methods;
}

function duitkuFailOnlineOrder(PDO $pdo, int $orderId): void
{
    $pdo->beginTransaction();
    $orderQuery = $pdo->prepare(
        'SELECT status, status_pembayaran FROM tbl_pesanan_online WHERE id_pesanan = ? FOR UPDATE'
    );
    $orderQuery->execute([$orderId]);
    $order = $orderQuery->fetch();
    if (!$order || $order['status_pembayaran'] !== 'Menunggu pembayaran') {
        $pdo->commit();
        return;
    }

    $detailsQuery = $pdo->prepare(
        'SELECT id_produk, jumlah FROM tbl_detail_pesanan_online WHERE id_pesanan = ?'
    );
    $detailsQuery->execute([$orderId]);
    $restoreStock = $pdo->prepare('UPDATE tbl_produk SET stok = stok + ? WHERE id_produk = ?');
    foreach ($detailsQuery->fetchAll() as $detail) {
        if ($detail['id_produk'] !== null) {
            $restoreStock->execute([(int) $detail['jumlah'], (int) $detail['id_produk']]);
        }
    }

    $markFailed = $pdo->prepare(
        "UPDATE tbl_pesanan_online
         SET status = 'Dibatalkan', status_pembayaran = 'Gagal'
         WHERE id_pesanan = ?"
    );
    $markFailed->execute([$orderId]);
    $pdo->commit();
}
