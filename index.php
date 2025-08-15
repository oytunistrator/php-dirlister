<?php
// === TEMEL AYARLAR ===
$baseDirectory = realpath('.');
$dirParam = $_GET['dir'] ?? '/';
$fileParam = $_GET['file'] ?? null;

// ZIP oluşturma endpoint
if (isset($_GET['zip']) && $_GET['zip'] === '1' && isset($_GET['dirToZip'])) {
    $dirToZip = rawurldecode($_GET['dirToZip']);
    $fullDirPath = realpath($baseDirectory . '/' . $dirToZip);

    if ($fullDirPath && is_dir($fullDirPath) && strpos($fullDirPath, $baseDirectory) === 0) {
        $zipName = basename($fullDirPath) . '.zip';
        $zipPath = sys_get_temp_dir() . '/' . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDirPath),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($fullDirPath) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.basename($zipName).'"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            unlink($zipPath);
            exit;
        } else {
            echo '<div class="alert alert-danger">Failed to create ZIP.</div>';
            exit;
        }
    } else {
        echo '<div class="alert alert-danger">Invalid folder to zip.</div>';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $_SERVER['HTTP_HOST']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        footer { position: fixed; bottom: 0; width: 100%; }
    </style>
</head>
<body>

<header class="bg-dark text-white p-3 text-center">
    <h1>Folder: <?php echo htmlspecialchars($_GET['dir'] ?? '/'); ?></h1>
</header>

<div class="container mt-2" style="padding-bottom: 100px;">

<?php
// URL decode ve path oluşturma
$requestPathDecoded = rawurldecode($dirParam);
$fullPath = $baseDirectory . ($requestPathDecoded === '/' ? '' : $requestPathDecoded);
$realPath = realpath($fullPath);

// Güvenlik kontrolü
if ($realPath === false || !is_dir($realPath) || strpos($realPath, $baseDirectory) !== 0) {
    header("HTTP/1.1 400 Bad Request");
    
    $parentDirectory = dirname($requestPathDecoded);
    $parentUrl = ($parentDirectory && $parentDirectory !== '.' && $parentDirectory !== '/') 
        ? '/' . implode('/', array_map('rawurlencode', explode('/', trim($parentDirectory, '/')))) 
        : '/';
    $domain = $_SERVER['HTTP_HOST'];
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
    echo '<li class="breadcrumb-item"><a href="/">'.$domain.'</a></li>';
    echo '</ol></nav>';
    echo '<a href="?dir=' . htmlspecialchars($parentUrl) . '" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Go Back</a>';
    echo '<div class="alert alert-danger mt-2">Invalid directory or access denied.</div>';
    exit;
}

// Ignore list
$ignoreList = [".", "..", "awstatsicons", "index.php", "icon", "awstats-icon", "index.html", ".htaccess", ".user.ini"];

// Dosya okuma
if ($fileParam) {
    $fileDecoded = rawurldecode($fileParam);
    $filePath = $baseDirectory . $fileDecoded;
    $realFilePath = realpath($filePath);
    $allowedExtensions = ['txt','log','csv','md'];

    if ($realFilePath && is_file($realFilePath) && strpos($realFilePath, $baseDirectory) === 0 && in_array(strtolower(pathinfo($realFilePath, PATHINFO_EXTENSION)), $allowedExtensions)) {
        $content = file_get_contents($realFilePath);
        echo '<a href="?dir=' . htmlspecialchars($parentUrl) . '" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Go Back</a>';
        echo '<div class="card mb-3"><div class="card-body">';
        echo '<h5>' . htmlspecialchars(basename($realFilePath)) . '</h5>';
        echo '<pre>' . nl2br(htmlspecialchars($content)) . '</pre>';
        echo '</div></div>';
        $parentDir = dirname($fileDecoded);
        $parentUrl = ($parentDir && $parentDir !== '.' && $parentDir !== '/') 
            ? '/' . implode('/', array_map('rawurlencode', explode('/', trim($parentDir, '/')))) 
            : '/';
        
        exit;
    } else {
        echo '<div class="alert alert-danger">Cannot read this file.</div>';
    }
}

// Breadcrumb
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

// Geri butonu
$parentDirectory = dirname($requestPathDecoded);
$parentUrl = ($parentDirectory && $parentDirectory !== '.' && $parentDirectory !== '/') 
    ? '/' . implode('/', array_map('rawurlencode', explode('/', trim($parentDirectory, '/')))) 
    : '/';
echo '<a href="?dir=' . htmlspecialchars($parentUrl) . '" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Go Back</a>';

// Dizin listeleme
if ($dir_handle = opendir($realPath)) {
    echo '<div class="list-group">';
    while (($file = readdir($dir_handle)) !== false) {
        if (!in_array($file, $ignoreList)) {
            $encodedFile = rawurlencode($file);
            $filePath = ($requestPathDecoded === '/' ? '' : $requestPathDecoded) . '/' . $encodedFile;
            $filePath = str_replace('//', '/', $filePath);

            if (is_dir($realPath . '/' . $file)) {
                $zipUrl = '?zip=1&dirToZip=' . urlencode($filePath);
                echo '<div class="list-group-item list-group-item-action list-group-item-primary d-flex justify-content-between align-items-center">';
                echo '<span><a href="?dir=' . htmlspecialchars($filePath) . '" class="text-decoration-none text-dark"><i class="bi bi-folder"></i> ' . htmlspecialchars($file) . '</a></span>';
                echo '<span class="text-success" style="cursor:pointer;" title="ZIP indir" onclick="window.location.href=\'' . htmlspecialchars($zipUrl) . '\'"><i class="bi bi-file-zip fs-5"></i></span>';
                echo '</div>';
            } else {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['txt','log','csv','md'])) {
                    echo '<a href="?file=' . htmlspecialchars($filePath) . '" class="list-group-item list-group-item-action">
                            <i class="bi bi-file-text"></i> ' . htmlspecialchars($file) . '
                          </a>';
                } else {
                    echo '<a href="' . htmlspecialchars($filePath) . '" download class="list-group-item list-group-item-action">
                            <i class="bi bi-file-earmark"></i> ' . htmlspecialchars($file) . '
                          </a>';
                }
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
