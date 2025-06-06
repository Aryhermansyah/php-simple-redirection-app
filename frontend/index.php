<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

session_start();

$db = Database::getInstance();

// Filter tanggal
$tanggal_sewa = isset($_GET['tanggal_sewa']) ? $_GET['tanggal_sewa'] : date('Y-m-d');
if (!validateDate($tanggal_sewa)) {
    $tanggal_sewa = date('Y-m-d');
}

$kategori = isset($_GET['kategori']) ? $_GET['kategori'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = ITEMS_PER_PAGE;
$offset = ($page - 1) * $limit;

// Query untuk total items
$whereClause = [];
$params = [];

if ($kategori) {
    $whereClause[] = "i.kategori = ?";
    $params[] = $kategori;
}

if ($search) {
    $whereClause[] = "i.nama_baju LIKE ? COLLATE NOCASE";
    $params[] = "%$search%";
}

$whereSql = '';
if (!empty($whereClause)) {
    $whereSql = "WHERE " . implode(" AND ", $whereClause);
}

$totalQuery = $db->fetchOne(
    "SELECT COUNT(DISTINCT i.id) as total 
     FROM items i 
     $whereSql",
    $params
);
$total = $totalQuery['total'];
$totalPages = ceil($total / $limit);

$params = array_merge($params, [$limit, $offset]);
$items = $db->fetchAll(
    "SELECT i.*, 
            GROUP_CONCAT(DISTINCT iv.ukuran) as ukuran_tersedia,
            MIN(iv.harga) as harga_min,
            MAX(iv.harga) as harga_max,
            MIN(iv.dp) as dp_min,
            MAX(iv.dp) as dp_max
     FROM items i
     LEFT JOIN item_variants iv ON i.id = iv.item_id
     $whereSql
     GROUP BY i.id
     ORDER BY i.nama_baju
     LIMIT ? OFFSET ?",
    $params
);

// Ambil daftar kategori untuk filter
$categories = $db->fetchAll(
    "SELECT DISTINCT kategori FROM items ORDER BY kategori"
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Katalog Baju - <?= APP_NAME ?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navbar -->
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <div class="flex-shrink-0 flex items-center">
                        <h1 class="text-xl font-bold text-gray-800"><?= APP_NAME ?></h1>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <!-- Filter Section -->
        <div class="bg-white shadow px-4 py-5 sm:rounded-lg sm:p-6 mb-6">
            <form action="" method="GET" class="space-y-4 md:space-y-0 md:flex md:items-end md:space-x-4">
                <!-- Tanggal Sewa -->
                <div class="flex-1">
                    <label for="tanggal_sewa" class="block text-sm font-medium text-gray-700">
                        Tanggal Sewa
                    </label>
                    <input type="date" 
                           name="tanggal_sewa" 
                           id="tanggal_sewa"
                           value="<?= htmlspecialchars($tanggal_sewa) ?>"
                           min="<?= date('Y-m-d') ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <!-- Kategori -->
                <div class="flex-1">
                    <label for="kategori" class="block text-sm font-medium text-gray-700">
                        Kategori
                    </label>
                    <select name="kategori" 
                            id="kategori"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['kategori']) ?>"
                                <?= $kategori === $cat['kategori'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['kategori']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Submit Button -->
                <div>
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        <i class="fas fa-search mr-2"></i>
                        Cari
                    </button>
                </div>
            </form>
        </div>

        <!-- Flash Message -->
        <?= showFlashMessage() ?>

        <!-- Items Grid -->
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <?php if (empty($items)): ?>
            <div class="col-span-full text-center py-12">
                <i class="fas fa-box-open text-gray-400 text-5xl mb-4"></i>
                <p class="text-gray-500">Tidak ada baju yang tersedia</p>
            </div>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <?php
                    $ukuranArray = explode(',', $item['ukuran_tersedia']);
                    $variants = $db->fetchAll(
                        "SELECT iv.*, 
                                COALESCE(ia.stok_terpakai, 0) as stok_terpakai
                         FROM item_variants iv
                         LEFT JOIN (
                             SELECT variant_id, SUM(stok_terpakai) as stok_terpakai 
                             FROM item_availability 
                             WHERE tanggal = ?
                             GROUP BY variant_id
                         ) ia ON ia.variant_id = iv.id
                         WHERE iv.item_id = ?",
                        [$tanggal_sewa, $item['id']]
                    );
                ?>
                <div class="bg-white overflow-hidden shadow rounded-lg divide-y divide-gray-200">
                    <!-- Gambar -->
                    <div class="relative">
                        <?php if ($item['foto']): ?>
                        <img src="<?= BASE_URL ?>/assets/images/<?= htmlspecialchars($item['foto']) ?>"
                             alt="<?= htmlspecialchars($item['nama_baju']) ?>"
                             class="w-full h-48 object-cover">
                        <?php else: ?>
                        <div class="w-full h-48 bg-gray-200 flex items-center justify-center">
                            <i class="fas fa-tshirt text-gray-400 text-4xl"></i>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Informasi -->
                    <div class="p-4 space-y-3">
                        <h3 class="text-lg font-medium text-gray-900">
                            <?= htmlspecialchars($item['nama_baju']) ?>
                        </h3>
                        
                        <p class="text-sm text-gray-500">
                            <?= htmlspecialchars($item['kategori']) ?>
                        </p>

                        <?php if ($item['deskripsi']): ?>
                        <p class="text-sm text-gray-600">
                            <?= nl2br(htmlspecialchars($item['deskripsi'])) ?>
                        </p>
                        <?php endif; ?>

                        <!-- Harga -->
                        <div class="text-sm">
                            <p class="font-medium text-gray-900">
                                Harga: <?= formatRupiah($item['harga_min']) ?>
                                <?php if ($item['harga_max'] > $item['harga_min']): ?>
                                    - <?= formatRupiah($item['harga_max']) ?>
                                <?php endif; ?>
                            </p>
                            <p class="text-gray-600">
                                DP: <?= formatRupiah($item['dp_min']) ?>
                                <?php if ($item['dp_max'] > $item['dp_min']): ?>
                                    - <?= formatRupiah($item['dp_max']) ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <!-- Ukuran dan Stok -->
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-gray-700">Ukuran Tersedia:</p>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($variants as $variant): ?>
                                <?php $stokTersedia = $variant['stok_total'] - $variant['stok_terpakai']; ?>
                                <div class="inline-flex flex-col items-center">
                                    <span class="px-3 py-1 text-sm font-medium <?= $stokTersedia > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?> rounded-md">
                                        <?= $variant['ukuran'] ?>
                                    </span>
                                    <span class="text-xs text-gray-500 mt-1">
                                        Stok: <?= $stokTersedia ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tombol -->
                    <div class="p-4">
                        <a href="order.php?item_id=<?= $item['id'] ?>&tanggal_sewa=<?= urlencode($tanggal_sewa) ?>"
                           class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 cursor-pointer">
                            <i class="fas fa-shopping-cart mr-2"></i>
                            <span>Pesan Sekarang</span>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6 mt-6">
            <div class="flex-1 flex justify-between sm:hidden">
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>&tanggal_sewa=<?= urlencode($tanggal_sewa) ?>&kategori=<?= urlencode($kategori) ?>" 
                   class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    Previous
                </a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>&tanggal_sewa=<?= urlencode($tanggal_sewa) ?>&kategori=<?= urlencode($kategori) ?>" 
                   class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    Next
                </a>
                <?php endif; ?>
            </div>
            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-gray-700">
                        Menampilkan
                        <span class="font-medium"><?= ($offset + 1) ?></span>
                        sampai
                        <span class="font-medium"><?= min($offset + $limit, $total) ?></span>
                        dari
                        <span class="font-medium"><?= $total ?></span>
                        hasil
                    </p>
                </div>
                <div>
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?= $i ?>&tanggal_sewa=<?= urlencode($tanggal_sewa) ?>&kategori=<?= urlencode($kategori) ?>" 
                           class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50
                                  <?= $i === $page ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : '' ?>">
                            <?= $i ?>
                        </a>
                        <?php endfor; ?>
                    </nav>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
