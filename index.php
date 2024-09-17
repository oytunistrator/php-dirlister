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
    $baseDirectory = realpath('.'); 
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    $currentDirectory = realpath($baseDirectory . ($requestUri === '/' ? '' : $requestUri));

    $ignoreList = array(".", "..", "awstatsicons", "index.php", "icon", "awstats-icon", "index.html", ".htaccess", ".user.ini");

    if (strpos($currentDirectory, $baseDirectory) !== 0 || !is_dir($currentDirectory)) {
        echo '<div class="alert alert-danger">Folder not exist.</div>';
        exit;
    }
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

    echo '<nav aria-label="breadcrumb">';
    echo '<ol class="breadcrumb">';
    echo '<li class="breadcrumb-item"><a href="/">'.$domain.'</a></li>';
    foreach ($breadcrumbs as $breadcrumb) {
        echo '<li class="breadcrumb-item"><a href="' . $breadcrumb['path'] . '">' . $breadcrumb['name'] . '</a></li>';
    }
    echo '</ol>';
    echo '</nav>';

    $parentDirectory = dirname($requestUri);
    if ($parentDirectory !== '/') {
        $parentUrl = $parentDirectory === '.' ? '/' : $parentDirectory;
        echo '<a href="' . htmlspecialchars($parentUrl) . '" class="btn btn-secondary mb-3"><i class="bi bi-arrow-left"></i> Go Back</a>';
    }

    if ($dir_handle = opendir($currentDirectory)) {
        echo '<div class="list-group">';
        while (($file = readdir($dir_handle)) !== false) {
            if (!in_array($file, $ignoreList)) {
                $filePath = $requestUri . '/' . $file;
                $filePath = str_replace('//', '/', $filePath); 

                if (is_dir($currentDirectory . '/' . $file)) {
                    echo '<a href="' . htmlspecialchars($filePath) . '" class="list-group-item list-group-item-action list-group-item-primary">
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
        echo '<div class="alert alert-danger">Error.</div>';
    }
    ?>

</div>

<footer class="bg-dark text-white text-center py-3 fixed-bottom">
    <p><a href="https://github.com/oytunistrator/php-dirlister/" class="text-white">PHP DirLister</a> © <?php echo date("Y"); ?></p>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
