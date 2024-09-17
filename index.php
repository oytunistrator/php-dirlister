<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Files</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Footer'ı sabit tutmak için */
        footer {
            position: fixed;
            bottom: 0;
            width: 100%;
        }
    </style>
</head>
<body>

<header class="bg-dark text-white p-3 text-center">
    <h1>Files: <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?></h1>
</header>

<div class="container">
    <h2 class="my-4">Directory Listing</h2>

    <?php
    // Varsayılan klasör yolu
    $baseDirectory = realpath('.'); // Mutlak yol

    // Aktif klasör yolu URL'den alınır
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // "/" root, "" ise baseDirectory
    $currentDirectory = realpath($baseDirectory . ($requestUri === '/' ? '' : $requestUri));

    // Göz ardı edilecek klasör ve dosyalar
    $ignoreList = array(".", "..", "awstatsicons", "index.php", "icon", "awstats-icon", "index.html", ".htaccess", ".user.ini");

    // Klasörün geçerli olup olmadığını kontrol et
    if (strpos($currentDirectory, $baseDirectory) !== 0 || !is_dir($currentDirectory)) {
        echo '<div class="alert alert-danger">Geçersiz klasör yolu.</div>';
        exit;
    }

    // Breadcrumb oluşturma
    $breadcrumbs = [];
    $path = '';
    $parts = explode('/', trim($requestUri, '/'));
    foreach ($parts as $part) {
        $path .= '/' . $part;
        $breadcrumbs[] = [
            'name' => $part,
            'path' => htmlspecialchars($path)
        ];
    }

$domain = $_SERVER['HTTP_HOST'];

    // Breadcrumb'ları gösterme
    echo '<nav aria-label="breadcrumb">';
    echo '<ol class="breadcrumb">';
    echo '<li class="breadcrumb-item"><a href="/">'.$domain.'</a></li>';
    foreach ($breadcrumbs as $breadcrumb) {
        echo '<li class="breadcrumb-item"><a href="' . $breadcrumb['path'] . '">' . $breadcrumb['name'] . '</a></li>';
    }
    echo '</ol>';
    echo '</nav>';

    // Geri linki için bir üst klasörü bul
    $parentDirectory = dirname($requestUri);
    if ($parentDirectory !== '/') {
        // URL'yi temizle ve tam URL oluştur
        $parentUrl = $parentDirectory === '.' ? '/' : $parentDirectory;
        echo '<a href="' . htmlspecialchars($parentUrl) . '" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Go Back</a>';
    }

    // Klasörün açılması
    if ($dir_handle = opendir($currentDirectory)) {
        echo '<div class="list-group">';
        // Klasördeki dosyaları döngüyle listeleme
        while (($file = readdir($dir_handle)) !== false) {
            if (!in_array($file, $ignoreList)) {
                $filePath = $requestUri . '/' . $file;
                $filePath = str_replace('//', '/', $filePath); // Çift slash'ları temizle

                // İkon tanımları
                if (is_dir($currentDirectory . '/' . $file)) {
                    // Klasör için ikon
                    echo '<a href="' . htmlspecialchars($filePath) . '" class="list-group-item list-group-item-action list-group-item-primary">
                            <i class="bi bi-folder"></i> ' . htmlspecialchars($file) . ' (Folder)
                          </a>';
                } else {
                    // Dosya için ikon
                    echo '<a href="' . htmlspecialchars($filePath) . '" class="list-group-item list-group-item-action">
                            <i class="bi bi-file-earmark"></i> ' . htmlspecialchars($file) . '
                          </a>';
                }
            }
        }
        echo '</div>';
        closedir($dir_handle);
    } else {
        echo '<div class="alert alert-danger">Klasör açılamadı.</div>';
    }
    ?>

</div>

<footer class="bg-dark text-white text-center py-3 fixed-bottom">
    <p>ODZNAMES © <?php echo date("Y"); ?></p>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
