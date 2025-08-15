<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Files</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        footer { position: fixed; bottom: 0; width: 100%; }
    </style>
</head>
<body>

<header class="bg-dark text-white p-3 text-center">
    <h1>Files: <?php echo htmlspecialchars($_GET['dir'] ?? '/'); ?></h1>
</header>

<div class="container">
    <h2 class="my-4">Directory Listing</h2>

<?php
// === TEMEL AYARLAR ===
$baseDirectory = realpath('.');
$dirParam = $_GET['dir'] ?? '/';

// 1. URL decode et (boşluk, özel karakterler)
$requestPathDecoded = rawurldecode($dirParam);

// 2. Full path oluştur
$fullPath = $baseDirectory . ($requestPathDecoded === '/' ? '' : $requestPathDecoded);

// 3. Gerçek path (symlink çözümü ve normalizasyon)
$realPath = realpath($fullPath);
// 4. Güvenlik kontrolleri
if (
    $realPath === false ||               // Dizin yok
    !is_dir($realPath) ||                // Gerçekten dizin değilse
    strpos($realPath, $baseDirectory) !== 0 // Base directory dışına çıkıyorsa
) {
    header("HTTP/1.1 400 Bad Request");
    echo '<div class="alert alert-danger">Invalid directory or access denied.</div>';

    // Geri butonunu göster
    $parentDirectory = dirname($requestPathDecoded);
    if ($parentDirectory !== '/' && $parentDirectory !== '.' && $parentDirectory !== '') {
        $parentParts = explode('/', trim($parentDirectory, '/'));
        $parentUrlEncoded = '/' . implode('/', array_map('rawurlencode', $parentParts));
        echo '<a href="?dir=' . htmlspecialchars($parentUrlEncoded) . '" class="btn btn-secondary mt-3"><i class="bi bi-arrow-left"></i> Go Back</a>';
    } else {
        // Eğer kök dizindeyse ana sayfaya dön
        echo '<a href="?dir=/" class="btn btn-secondary mt-3"><i class="bi bi-arrow-left"></i> Go Back</a>';
    }

    exit;
}

// === Ignore list ===
$ignoreList = [".", "..", "awstatsicons", "index.php", "icon", "awstats-icon", "index.html", ".htaccess", ".user.ini"];

// === BREADCRUMB ===
$breadcrumbs = [];
$parts = explode('/', trim($requestPathDecoded, '/'));
$pathAcc = '';
foreach ($parts as $part) {
    $pathAcc .= '/' . rawurlencode($part);
    $breadcrumbs[] = ['name' => $part, 'path' => $pathAcc];
}

$domain = $_SERVER['HTTP_HOST'];
echo '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
echo '<li class="breadcrumb-item"><a href="/">'.$domain.'</a></li>';
foreach ($breadcrumbs as $breadcrumb) {
    echo '<li class="breadcrumb-item"><a href="?dir=' . htmlspecialchars($breadcrumb['path']) . '">' . htmlspecialchars($breadcrumb['name']) . '</a></li>';
}
echo '</ol></nav>';

// === GERİ BUTONU ===
$parentDirectory = dirname($requestPathDecoded);
if ($parentDirectory !== '/' && $parentDirectory !== '.' && $parentDirectory !== '') {
    $parentParts = explode('/', trim($parentDirectory, '/'));
    $parentUrlEncoded = '/' . implode('/', array_map('rawurlencode', $parentParts));
    echo '<a href="?dir=' . htmlspecialchars($parentUrlEncoded) . '" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Go Back</a>';
}

// === DİZİN LİSTELEME ===
if ($dir_handle = opendir($realPath)) {
    echo '<div class="list-group">';
    while (($file = readdir($dir_handle)) !== false) {
        if (!in_array($file, $ignoreList)) {
            $encodedFile = rawurlencode($file);
            $filePath = ($requestPathDecoded === '/' ? '' : $requestPathDecoded) . '/' . $encodedFile;
            $filePath = str_replace('//', '/', $filePath);

            if (is_dir($realPath . '/' . $file)) {
                echo '<a href="?dir=' . htmlspecialchars($filePath) . '" class="list-group-item list-group-item-action list-group-item-primary">
                        <i class="bi bi-folder"></i> ' . htmlspecialchars($file) . '
                      </a>';
            } else {
                echo '<a href="' . htmlspecialchars($filePath) . '" download class="list-group-item list-group-item-action">
                        <i class="bi bi-file-earmark"></i> ' . htmlspecialchars($file) . '
                      </a>';
            }
        }
    }
    echo '</div>';
    closedir($dir_handle);
} else {
    echo '<div class="alert alert-danger">Error opening directory.</div>';
}
?>

</div>

<footer class="bg-dark text-white text-center py-3 fixed-bottom">
    <p><a href="https://github.com/oytunistrator/php-dirlister/" class="text-white">PHP DirLister</a> © <?php echo date("Y"); ?></p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
