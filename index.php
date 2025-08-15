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

// URL’den gelen path’i decode et
$requestPathDecoded = rawurldecode($dirParam);

// Geçerli klasör yolunu oluştur
$currentDirectory = realpath($baseDirectory . ($requestPathDecoded === '/' ? '' : $requestPathDecoded));

$ignoreList = [".", "..", "awstatsicons", "index.php", "icon", "awstats-icon", "index.html", ".htaccess", ".user.ini"];

// Güvenlik kontrolü: baseDirectory dışına çıkma engeli
if ($currentDirectory === false || strpos($currentDirectory, $baseDirectory) !== 0 || !is_dir($currentDirectory)) {
    echo '<div class="alert alert-danger">Folder not exist or access denied.</div>';
    exit;
}

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
if ($dir_handle = opendir($currentDirectory)) {
    echo '<div class="list-group">';
    while (($file = readdir($dir_handle)) !== false) {
        if (!in_array($file, $ignoreList)) {
            $encodedFile = rawurlencode($file);
            $filePath = ($requestPathDecoded === '/' ? '' : $requestPathDecoded) . '/' . $encodedFile;
            $filePath = str_replace('//', '/', $filePath);

            if (is_dir($currentDirectory . '/' . $file)) {
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
