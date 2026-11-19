<?php

function createTransaction(
    PDO $pdo,
    int $customerId,
    array $cart,
    float $discountPercent,
    float $amountPaid,
    string $paymentMethod,
    ?string $memberCardCode = null,
    ?int $cashierId = null
): array {
    try {
        $pdo->beginTransaction();

        $customerQuery = $pdo->prepare(
            'SELECT id_pelanggan, nama_pelanggan, kota FROM tbl_pelanggan WHERE id_pelanggan = ?'
        );
        $customerQuery->execute([$customerId]);
        $customer = $customerQuery->fetch();
        if (!$customer) {
            throw new InvalidArgumentException('Pelanggan yang dipilih tidak ditemukan.');
        }

        if ($cashierId !== null) {
            $discountPercent = 0.0;
            $cashierQuery = $pdo->prepare('SELECT id_kasir FROM tbl_kasir WHERE id_kasir = ? AND aktif = 1');
            $cashierQuery->execute([$cashierId]);
            if (!$cashierQuery->fetchColumn()) {
                throw new InvalidArgumentException('Akun kasir tidak aktif. Silakan login kembali.');
            }
        }

        $member = null;
        if ($memberCardCode !== null && $memberCardCode !== '') {
            if (preg_match('/^MBR[A-F0-9]{22}$/D', $memberCardCode) !== 1) {
                throw new InvalidArgumentException('Kode kartu member tidak valid.');
            }

            $memberQuery = $pdo->prepare(
                'SELECT id_member, id_pelanggan, card_code, diskon_persen
                 FROM tbl_member
                 WHERE card_code = ? AND aktif = 1
                 FOR UPDATE'
            );
            $memberQuery->execute([$memberCardCode]);
            $member = $memberQuery->fetch();
            if (!$member) {
                throw new InvalidArgumentException('Kartu member tidak aktif atau tidak ditemukan.');
            }
            if ((int) $member['id_pelanggan'] !== $customerId) {
                throw new InvalidArgumentException('Pelanggan tidak sesuai dengan kartu member yang discan.');
            }

            $discountPercent = max($discountPercent, (float) $member['diskon_persen']);
        }

        $productIds = array_keys($cart);
        sort($productIds, SORT_NUMERIC);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $productQuery = $pdo->prepare(
            'SELECT id_produk, nama_produk, harga, stok
             FROM tbl_produk
             WHERE id_produk IN (' . $placeholders . ')
             ORDER BY id_produk
             FOR UPDATE'
        );
        $productQuery->execute($productIds);
        $products = [];
        foreach ($productQuery->fetchAll() as $product) {
            $products[(int) $product['id_produk']] = $product;
        }

        if (count($products) !== count($cart)) {
            throw new InvalidArgumentException('Salah satu produk di keranjang sudah tidak tersedia.');
        }

        $lineItems = [];
        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalTax = 0.0;
        $calculation = $pdo->prepare(
            'SELECT HitungTotal(?, ?) AS subtotal,
                    HitungDiskon(?, ?) AS diskon,
                    HitungPajak(?) AS pajak'
        );

        foreach ($cart as $productId => $quantity) {
            $product = $products[(int) $productId];
            if ((int) $product['stok'] < $quantity) {
                throw new InvalidArgumentException('Stok ' . $product['nama_produk'] . ' tidak mencukupi.');
            }

            $unitPrice = (float) $product['harga'];
            $calculation->execute([
                $unitPrice,
                $quantity,
                $unitPrice * $quantity,
                $discountPercent,
                $unitPrice * $quantity * (1 - $discountPercent / 100),
            ]);
            $amounts = $calculation->fetch();
            $lineSubtotal = (float) $amounts['subtotal'];
            $lineDiscount = (float) $amounts['diskon'];
            $lineTax = (float) $amounts['pajak'];

            $lineItems[] = [
                'id' => (int) $productId,
                'name' => $product['nama_produk'],
                'quantity' => $quantity,
                'price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'discount' => $lineDiscount,
                'tax' => $lineTax,
            ];
            $subtotal += $lineSubtotal;
            $totalDiscount += $lineDiscount;
            $totalTax += $lineTax;
        }

        $subtotal = round($subtotal, 2);
        $totalDiscount = round($totalDiscount, 2);
        $totalTax = round($totalTax, 2);
        $total = round($subtotal - $totalDiscount + $totalTax, 2);
        $amountPaid = $paymentMethod === 'Tunai' ? round($amountPaid, 2) : $total;

        if ($total > 9999999999.99 || $amountPaid > 9999999999.99) {
            throw new InvalidArgumentException('Total transaksi melebihi batas nilai database.');
        }
        if ($amountPaid < $total) {
            throw new InvalidArgumentException('Uang yang dibayarkan kurang dari total belanja.');
        }

        $change = $paymentMethod === 'Tunai' ? round($amountPaid - $total, 2) : 0.0;
        $sale = $pdo->prepare(
            'INSERT INTO tbl_transaksi
                (tanggal, id_pelanggan, id_member, id_kasir, total_belanja, subtotal, diskon, pajak, uang_dibayar, kembalian, metode_pembayaran)
             VALUES (CURRENT_DATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $sale->execute([
            $customerId,
            $member ? (int) $member['id_member'] : null,
            $cashierId,
            $total,
            $subtotal,
            $totalDiscount,
            $totalTax,
            $amountPaid,
            $change,
            $paymentMethod,
        ]);
        $transactionId = (int) $pdo->lastInsertId();

        $detail = $pdo->prepare(
            'INSERT INTO tbl_detail_transaksi (id_transaksi, nama_produk, jumlah, harga)
             VALUES (?, ?, ?, ?)'
        );
        $stockUpdate = $pdo->prepare(
            'UPDATE tbl_produk SET stok = stok - ? WHERE id_produk = ? AND stok >= ?'
        );

        foreach ($lineItems as $item) {
            $detail->execute([$transactionId, $item['name'], $item['quantity'], $item['price']]);
            $stockUpdate->execute([$item['quantity'], $item['id'], $item['quantity']]);
            if ($stockUpdate->rowCount() !== 1) {
                throw new RuntimeException('Stok berubah saat transaksi diproses. Silakan coba kembali.');
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'id' => $transactionId,
        'customer' => $customer,
        'member' => $member,
        'items' => $lineItems,
        'subtotal' => $subtotal,
        'discount' => $totalDiscount,
        'tax' => $totalTax,
        'total' => $total,
        'amount_paid' => $amountPaid,
        'change' => $change,
        'payment_method' => $paymentMethod,
    ];
}
