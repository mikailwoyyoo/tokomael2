<?php

$pageTitle = $pageTitle ?? 'MKLOYY';
$activePage = $activePage ?? '';
$role = currentRole();
$userName = currentUserName();
$homePage = ['admin' => 'index.php', 'cashier' => 'kasir.php', 'member' => 'dashboard_pelanggan.php'][$role] ?? 'login.php';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | MKLOYY</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= h($homePage) ?>"><span class="brand-mark">M</span><span>MKLOYY</span></a>
        <div class="sidebar-label">MENU UTAMA</div>
        <nav class="side-nav" aria-label="Navigasi utama">
            <?php if ($role === 'admin'): ?>
            <a class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" href="index.php"><span class="nav-icon">▦</span>Dashboard</a>
            <a class="<?= $activePage === 'produk' ? 'active' : '' ?>" href="produk.php"><span class="nav-icon">▣</span>Produk</a>
            <a class="<?= $activePage === 'pelanggan' ? 'active' : '' ?>" href="pelanggan.php"><span class="nav-icon">♙</span>Pelanggan</a>
            <a class="<?= $activePage === 'member' ? 'active' : '' ?>" href="member.php"><span class="nav-icon">▣</span>Member</a>
            <a class="<?= $activePage === 'transaksi' ? 'active' : '' ?>" href="transaksi.php"><span class="nav-icon">▣</span>Kasir POS</a>
            <a class="<?= $activePage === 'laporan' ? 'active' : '' ?>" href="laporan.php"><span class="nav-icon">▤</span>Laporan</a>
            <a class="<?= $activePage === 'admin' ? 'active' : '' ?>" href="admin.php"><span class="nav-icon">✎</span>Kelola Iklan</a>
            <a class="<?= $activePage === 'online-orders' ? 'active' : '' ?>" href="pesanan_online.php"><span class="nav-icon">▤</span>Pesanan Online</a>
            <a class="<?= $activePage === 'qris' ? 'active' : '' ?>" href="qris.php"><span class="nav-icon">▦</span>Pengaturan QRIS</a>
            <a class="<?= $activePage === 'accounts' ? 'active' : '' ?>" href="akun_kasir.php"><span class="nav-icon">♙</span>Akun Kasir</a>
            <a class="<?= $activePage === 'login-cards' ? 'active' : '' ?>" href="kartu_login.php"><span class="nav-icon">▣</span>Kartu Login</a>
            <?php elseif ($role === 'cashier'): ?>
            <a class="<?= $activePage === 'cashier' ? 'active' : '' ?>" href="kasir.php"><span class="nav-icon">▣</span>Kasir & Riwayat</a>
            <a class="<?= $activePage === 'online-orders' ? 'active' : '' ?>" href="pesanan_online.php"><span class="nav-icon">▤</span>Pesanan Online</a>
            <?php elseif ($role === 'member'): ?>
            <a class="<?= $activePage === 'portal' ? 'active' : '' ?>" href="dashboard_pelanggan.php"><span class="nav-icon">♙</span>Dashboard Pelanggan</a>
            <a class="<?= $activePage === 'shop' ? 'active' : '' ?>" href="belanja_online.php"><span class="nav-icon">🛍</span>Belanja Online</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-label">DATABASE</div>
            <div class="database-status"><span class="status-dot"></span>PHP Native · PDO</div>
            <div class="sidebar-note">Database: <?= h($database ?? '—') ?></div>
        </div>
    </aside>
    <div class="main-shell">
        <header class="topbar">
            <button type="button" class="menu-toggle" data-menu-toggle aria-label="Buka navigasi">☰</button>
            <div class="breadcrumb">MKLOYY <span>/</span> <?= h($pageTitle) ?></div>
            <div class="topbar-right">
                <span class="today"><?= date('d M Y') ?></span>
                <span class="avatar"><?= h(strtoupper(substr($userName, 0, 1))) ?></span>
                <span class="admin-label"><?= h($userName) ?></span>
                <form method="post" action="logout.php" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <button class="logout-button" type="submit">Keluar</button>
                </form>
            </div>
        </header>
        <main class="content">
