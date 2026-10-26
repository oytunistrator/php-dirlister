<?php
$base = realpath(__DIR__);
if ($base === false) { http_response_code(500); exit('Directory browser is not configured.'); }
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store, max-age=0');
header_remove('X-Powered-By');
header('Content-Type: text/html; charset=UTF-8');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function qv($key) { if (!array_key_exists($key, $_GET)) return null; return is_string($_GET[$key]) ? $_GET[$key] : false; }
function fail_request($code, $message) { http_response_code($code); header('Content-Type: text/plain; charset=UTF-8'); exit($message); }
function segments($path) {
    if (!is_string($path) || strpos($path, "\0") !== false || strpos($path, '\\') !== false) return false;
    $path = trim($path, '/'); if ($path === '') return [];
    $parts = explode('/', $path);
    foreach ($parts as $part) if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.') return false;
    return $parts;
}
function within_root($root, $path) {
    if ($path === $root) return true;
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strncmp($path, $prefix, strlen($prefix)) === 0;
}
function resolve_path($root, $parts) {
    $candidate = $root;
    foreach ($parts as $part) { $candidate .= DIRECTORY_SEPARATOR . $part; if (is_link($candidate)) return false; }
    $real = realpath($candidate);
    return $real !== false && within_root($root, $real) ? $real : false;
}
function private_name($name) {
    if ($name === '' || $name[0] === '.') return true;
    return in_array(strtolower($name), ['index.php', '.htaccess', '.user.ini'], true) || preg_match('/\.(?:php[0-9]?|phtml|phar)$/i', $name) === 1;
}
function private_path($path) { foreach (explode('/', str_replace('\\', '/', $path)) as $part) if (private_name($part)) return true; return false; }
function url_for($script, $query) { return $script . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986); }
function attachment($name) {
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($fallback === '' || $fallback === '.' || $fallback === '..') $fallback = 'download';
    return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}
function stream_handle($handle) {
    @set_time_limit(0);
    while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
    while (!feof($handle)) {
        $chunk = @fread($handle, 1048576);
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        flush();
    }
    fclose($handle);
}
function download_file($root, $parts) {
    $path = resolve_path($root, $parts);
    if ($path === false || !is_file($path) || private_path(implode('/', $parts))) fail_request(404, 'File not found.');
    if (!is_readable($path)) fail_request(403, 'File is not readable.');
    $handle = @fopen($path, 'rb');
    if ($handle === false) fail_request(403, 'File is not readable.');
    $name = basename($path); $size = @filesize($path);
    header('Content-Type: application/octet-stream'); header('Content-Disposition: ' . attachment($name));
    header('Content-Transfer-Encoding: binary'); header('Accept-Ranges: none');
    if ($size !== false) header('Content-Length: ' . $size);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') stream_handle($handle); else fclose($handle);
    exit;
}
function download_zip($root, $parts) {
    @set_time_limit(0);
    if (!class_exists('ZipArchive')) fail_request(501, 'ZIP downloads are not available on this server.');
    if (!$parts) fail_request(400, 'Choose a folder to create a ZIP download.');
    $dir = resolve_path($root, $parts);
    if ($dir === false || !is_dir($dir)) fail_request(404, 'Folder not found.');
    if (!is_readable($dir)) fail_request(403, 'Folder is not readable.');

    // Bound archive work so repeated public ZIP requests cannot consume unlimited disk.
    $maxArchiveBytes = 1073741824;
    $maxArchiveFiles = 10000;
    $preflight = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    $sourceBytes = 0; $sourceFiles = 0;
    foreach ($preflight as $entry) {
        try {
            if ($entry->isLink() || !$entry->isFile() || !$entry->isReadable()) continue;
            $relative = ltrim(substr($entry->getPathname(), strlen($dir)), DIRECTORY_SEPARATOR);
            if (private_path($relative)) continue;
            $real = $entry->getRealPath(); if ($real === false || !within_root($root, $real)) continue;
            $size = @filesize($real); if ($size === false) continue;
            $sourceFiles++;
            if ($sourceFiles > $maxArchiveFiles || $size > $maxArchiveBytes - $sourceBytes) {
                fail_request(413, 'This folder is too large to archive. Download its files individually.');
            }
            $sourceBytes += $size;
        } catch (UnexpectedValueException $e) { continue; }
    }
    $tmp = @tempnam(sys_get_temp_dir(), 'dirlister-');
    if ($tmp === false) fail_request(500, 'Could not prepare the ZIP download.');
    $zip = new ZipArchive();
    if (@$zip->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); fail_request(500, 'Could not create the ZIP download.'); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    $zipFailed = false; $addedBytes = 0; $addedFiles = 0;
    foreach ($it as $entry) {
        try {
            if ($entry->isLink() || !$entry->isFile() || !$entry->isReadable()) continue;
            $relative = ltrim(substr($entry->getPathname(), strlen($dir)), DIRECTORY_SEPARATOR);
            if (private_path($relative)) continue;
            $real = $entry->getRealPath();
            if ($real === false || !within_root($root, $real) || $real === realpath($tmp)) continue;
            $size = @filesize($real);
            if ($size === false) continue;
            $addedFiles++;
            if ($addedFiles > $maxArchiveFiles || $size > $maxArchiveBytes - $addedBytes) { $zipFailed = true; break; }
            if (!@$zip->addFile($real, str_replace(DIRECTORY_SEPARATOR, '/', $relative))) $zipFailed = true;
            $addedBytes += $size;
        } catch (UnexpectedValueException $e) { continue; }
    }
    $zipClosed = @$zip->close();
    if (!$zipClosed || $zipFailed) { @unlink($tmp); fail_request(500, 'Could not finish the ZIP download.'); }
    $handle = @fopen($tmp, 'rb');
    if ($handle === false) { @unlink($tmp); fail_request(500, 'Could not read the ZIP download.'); }
    register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
    $name = ($parts ? end($parts) : basename($root)) . '.zip'; $size = @filesize($tmp);
    header('Content-Type: application/zip'); header('Content-Disposition: ' . attachment($name));
    header('Content-Transfer-Encoding: binary'); if ($size !== false) header('Content-Length: ' . $size);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') stream_handle($handle); else fclose($handle);
    @unlink($tmp); exit;
}
function icon_svg($kind) {
    $paths = [
        'folder' => '<path d="M3.75 6.75A1.75 1.75 0 0 1 5.5 5h4l1.7 1.8h7.3A1.75 1.75 0 0 1 20.25 8.5v7A1.75 1.75 0 0 1 18.5 17.25h-13a1.75 1.75 0 0 1-1.75-1.75z"/><path d="M3.75 9h16.5"/>',
        'file' => '<path d="M7 3.75h6l4 4v12.5H7a1.75 1.75 0 0 1-1.75-1.75V5.5A1.75 1.75 0 0 1 7 3.75z"/><path d="M13 3.75v4.5h4.5M8.5 12h7M8.5 15.5h7"/>',
        'download' => '<path d="M12 3.75v11M7.5 10.5 12 15l4.5-4.5M4.5 17.25v2h15v-2"/>',
        'archive' => '<path d="M4.25 6.25h15.5v13h-15.5zM3.5 3.75h17v2.5h-17zM10 9h4M10 12h4"/>',
        'back' => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'open' => '<path d="M4.25 6.25h6l1.5 1.5h8v10h-15.5zM4.25 10h15.5"/>'
    ];
    return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$kind] ?? $paths['file']) . '</svg>';
}
function pretty_size($bytes) {
    $bytes = max(0, (float)$bytes); $units = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$bytes : number_format($bytes, 1)) . ' ' . $units[$i];
}
function previewable($name) { return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['txt', 'log', 'csv', 'md'], true); }

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); fail_request(405, 'Method not allowed.'); }
$script = $_SERVER['SCRIPT_NAME'] ?? '/index.php'; if ($script === '' || $script[0] !== '/') $script = '/' . $script;
$preview = null; $viewName = null; $viewBackUrl = null; $viewDownloadUrl = null;
$viewValue = qv('view'); if ($viewValue === null) $viewValue = qv('file');
if ($viewValue === false) fail_request(400, 'Invalid file path.');
if ($viewValue !== null) {
    $viewParts = segments($viewValue); if ($viewParts === false || !$viewParts) fail_request(404, 'File not found.');
    $viewPath = resolve_path($base, $viewParts);
    if ($viewPath === false || !is_file($viewPath) || private_path(implode('/', $viewParts))) fail_request(404, 'File not found.');
    if (!previewable($viewPath)) fail_request(415, 'This file type cannot be previewed.');
    if (!is_readable($viewPath)) fail_request(403, 'File is not readable.');
    $size = @filesize($viewPath); $preview = ($size !== false && $size <= 1048576) ? @file_get_contents($viewPath) : false;
    $viewName = basename($viewPath); $viewParent = array_slice($viewParts, 0, -1);
    $directoryParts = $viewParent; $viewBackUrl = url_for($script, ['dir' => '/' . implode('/', $viewParent) . ($viewParent ? '/' : '')]);
    $viewDownloadUrl = url_for($script, ['download' => implode('/', $viewParts)]);
}
if ($viewValue === null) {
    $download = qv('download'); if ($download === false) fail_request(400, 'Invalid file path.');
    if ($download !== null) { $parts = segments($download); if ($parts === false || !$parts) fail_request(404, 'File not found.'); download_file($base, $parts); }
    $zipPath = qv('zipdir'); $legacyZip = false;
    if ($zipPath === null && qv('zip') === '1') { $zipPath = qv('dirToZip'); $legacyZip = true; }
    if ($zipPath === false) fail_request(400, 'Invalid folder path.');
    if ($zipPath !== null) { if ($legacyZip) $zipPath = rawurldecode($zipPath); $parts = segments($zipPath); if ($parts === false) fail_request(404, 'Folder not found.'); download_zip($base, $parts); }

    $explicitDir = array_key_exists('dir', $_GET);
    if ($explicitDir && !is_string($_GET['dir'])) fail_request(400, 'Invalid folder path.');
    $dirValue = $explicitDir ? $_GET['dir'] : qv('route'); if ($dirValue === false) fail_request(400, 'Invalid folder path.');
    if ($dirValue === null) $dirValue = '/';
    $directoryParts = segments($dirValue); if ($directoryParts === false) fail_request(404, 'Folder not found.');
    $directory = resolve_path($base, $directoryParts); if ($directory === false) fail_request(404, 'Folder not found.');
    if (is_file($directory) && !$explicitDir) download_file($base, $directoryParts);
    if (!is_dir($directory)) fail_request(404, 'Folder not found.');
    if (!is_readable($directory)) fail_request(403, 'Folder is not readable.');
    $names = @scandir($directory); if ($names === false) fail_request(403, 'Folder is not readable.');
    $items = [];
    foreach ($names as $name) {
        if ($name === '.' || $name === '..' || private_name($name)) continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name; if (is_link($path)) continue;
        $isDir = is_dir($path); if (!$isDir && !is_file($path)) continue;
        $size = $isDir ? null : @filesize($path); $mtime = @filemtime($path);
        $items[] = ['name' => $name, 'dir' => $isDir, 'size' => $size === false ? 0 : $size, 'mtime' => $mtime === false ? null : $mtime];
    }
    usort($items, function ($a, $b) { if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1; $c = strnatcasecmp($a['name'], $b['name']); return $c ?: strcmp($a['name'], $b['name']); });
}
$siteName = 'Files';
$currentPath = '/' . implode('/', $directoryParts); if ($currentPath !== '/') $currentPath .= '/';
$currentName = $directoryParts ? end($directoryParts) : $siteName;
$parentParts = array_slice($directoryParts, 0, -1);
$parentUrl = url_for($script, ['dir' => '/' . implode('/', $parentParts) . ($parentParts ? '/' : '')]);
$rootUrl = url_for($script, ['dir' => '/']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light">
<title><?= h($currentName) ?> · <?= h($siteName) ?></title>
<style>
:root{color-scheme:light;--ink:#17212f;--muted:#66758a;--line:#e6ebf2;--accent:#315df5;--soft:#eef2ff;--shadow:0 18px 50px rgba(28,43,69,.08)}*{box-sizing:border-box}html{min-height:100%;background:#f5f7fb}body{margin:0;min-height:100vh;color:var(--ink);background:radial-gradient(ellipse at 88% 0,#e7edff 0,transparent 30rem),#f5f7fb;font:15px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}a{color:inherit}.topbar{border-bottom:1px solid rgba(230,235,242,.9);background:rgba(255,255,255,.78)}.top-inner,.shell{width:min(1120px,calc(100% - 40px));margin:auto}.top-inner{min-height:68px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:inline-flex;align-items:center;gap:11px;font-weight:750;letter-spacing:-.025em;text-decoration:none}.mark{width:34px;height:34px;display:grid;place-items:center;border-radius:11px;color:white;background:linear-gradient(145deg,#4973ff,#294edb);box-shadow:0 5px 14px #315df53d}.mark svg{width:20px}.top-note,.intro-copy{color:var(--muted);font-size:13px}.shell{padding:45px 0 34px}.intro{margin:0 0 26px}.eyebrow{margin:0 0 8px;color:var(--accent);text-transform:uppercase;letter-spacing:.11em;font-size:11px;font-weight:800}h1{margin:0;font-size:clamp(29px,4vw,42px);line-height:1.12;letter-spacing:-.045em;overflow-wrap:anywhere}.intro-copy{margin:11px 0 0;max-width:650px;font-size:15px}.breadcrumbs{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 17px;color:var(--muted);font-size:13px}.breadcrumbs a{color:var(--muted);text-decoration:none}.breadcrumbs a:hover{color:var(--accent)}.sep{color:#aab4c2}.panel{overflow:hidden;border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:var(--shadow)}.panel-head{min-height:91px;padding:20px 24px;display:flex;align-items:center;justify-content:space-between;gap:18px;border-bottom:1px solid var(--line)}.panel-head h2{margin:0;font-size:17px;letter-spacing:-.02em}.panel-head p{margin:4px 0 0;color:var(--muted);font-size:13px}.head-actions,.actions{display:flex;align-items:center;justify-content:flex-end;gap:7px}.button{min-height:38px;padding:8px 12px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--line);border-radius:10px;background:#fff;color:#354358;font:inherit;font-size:13px;font-weight:650;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s,transform .15s}.button:hover{border-color:#bdc9dd;background:#f8faff;transform:translateY(-1px)}.button.primary{border-color:var(--accent);color:#fff;background:var(--accent)}.button.primary:hover{background:#264edb}.button svg{width:16px;height:16px}.listing-head,.row{display:grid;grid-template-columns:minmax(0,1fr) 100px 145px 155px;align-items:center;gap:18px}.listing-head{padding:11px 24px;color:#7e8ba0;background:#fbfcfe;text-transform:uppercase;letter-spacing:.075em;font-size:10px;font-weight:800}.listing-head span:not(:first-child){text-align:right}.row{min-height:71px;padding:12px 24px;border-top:1px solid #edf0f5;transition:background .12s}.row:hover{background:#fbfcff}.main-link{min-width:0;display:flex;align-items:center;gap:13px;text-decoration:none}.file-icon{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;border:1px solid #e5ebf5;border-radius:12px;color:#56708f;background:#f8faff}.file-icon.folder{border-color:#e7eaff;color:#5367d9;background:var(--soft)}.file-icon svg{width:20px;height:20px}.label{min-width:0}.name{display:block;overflow:hidden;color:#202c3c;font-weight:680;text-overflow:ellipsis;white-space:nowrap}.main-link:hover .name{color:var(--accent)}.kind{display:block;margin-top:2px;color:#8995a7;font-size:12px}.meta{color:#68778b;text-align:right;font-size:13px;white-space:nowrap}.actions .button{min-height:34px;padding:6px 9px;font-size:12px}.actions .zip{color:#087e68}.empty{padding:56px 20px;color:var(--muted);text-align:center}.empty strong{display:block;margin-bottom:4px;color:var(--ink)}.preview{padding:22px 24px 26px}.preview-top{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.preview-top h2{margin:0;min-width:0;overflow-wrap:anywhere;font-size:17px}.preview-content{max-height:70vh;min-height:90px;overflow:auto;margin:0;padding:18px;border:1px solid var(--line);border-radius:12px;color:#344256;background:#f9fbfe;font:13px/1.65 ui-monospace,monospace;white-space:pre-wrap;overflow-wrap:anywhere}.preview-limit{color:var(--muted);font-size:13px}.footer{padding:22px 12px 4px;color:#8490a1;text-align:center;font-size:12px}.footer a{color:#69778b;text-decoration:none}@media(max-width:820px){.listing-head,.row{grid-template-columns:minmax(0,1fr) 86px 130px;gap:12px}.modified,.modified-head{display:none}.shell{padding-top:34px}}@media(max-width:600px){.top-inner,.shell{width:min(calc(100% - 28px),1120px)}.top-inner{min-height:60px}.top-note{display:none}.shell{padding-top:28px}.panel{border-radius:14px}.panel-head{min-height:78px;padding:16px;align-items:flex-start}.panel-head p{max-width:190px}.head-actions .button{min-height:35px;padding:7px 9px;font-size:12px}.listing-head{display:none}.row{grid-template-columns:minmax(0,1fr) auto;gap:8px;min-height:68px;padding:11px 14px}.size,.modified{display:none}.main-link{gap:10px}.file-icon{width:34px;height:34px;flex-basis:34px;border-radius:10px}.file-icon svg{width:18px;height:18px}.actions{gap:5px}.actions .button{min-height:32px;padding:5px 7px}.preview{padding:16px}.preview-top{align-items:flex-start}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<header class="topbar"><div class="top-inner"><a class="brand" href="<?= h($rootUrl) ?>"><span class="mark"><?= icon_svg('folder') ?></span><span><?= h($siteName) ?></span></a><span class="top-note">A simple, secure view of this folder</span></div></header>
<main class="shell">
<section class="intro"><p class="eyebrow">Directory browser</p><h1><?= h($currentName) ?></h1><p class="intro-copy">Browse folders and download files. Your current location is <?= h($currentPath) ?></p></section>
<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="<?= h($rootUrl) ?>"><?= h($siteName) ?></a><?php $crumbs = []; foreach ($directoryParts as $crumb): $crumbs[] = $crumb; ?><span class="sep" aria-hidden="true">/</span><?php if (count($crumbs) === count($directoryParts)): ?><span aria-current="page"><?= h($crumb) ?></span><?php else: ?><a href="<?= h(url_for($script, ['dir' => '/' . implode('/', $crumbs) . '/'])) ?>"><?= h($crumb) ?></a><?php endif; ?><?php endforeach; ?></nav>
<section class="panel" aria-labelledby="contents-heading"><div class="panel-head"><div><h2 id="contents-heading"><?= $preview !== null ? 'File preview' : 'Contents' ?></h2><p><?= $preview !== null ? 'Text preview · files up to 1 MB' : count($items) . ' ' . (count($items) === 1 ? 'item' : 'items') . ' in this folder' ?></p></div><div class="head-actions"><?php if ($preview !== null): ?><a class="button" href="<?= h($viewBackUrl) ?>"><?= icon_svg('back') ?> Back to folder</a><?php elseif ($directoryParts): ?><a class="button" href="<?= h($parentUrl) ?>"><?= icon_svg('back') ?> Up one level</a><?php endif; ?></div></div>
<?php if ($preview !== null): ?><div class="preview"><div class="preview-top"><h2><?= h($viewName) ?></h2><a class="button primary" href="<?= h($viewDownloadUrl) ?>" download><?= icon_svg('download') ?> Download</a></div><?php if ($preview === false): ?><p class="preview-limit">This file is larger than 1 MB. Download it to view the full contents.</p><?php else: ?><pre class="preview-content"><?= h($preview) ?></pre><?php endif; ?></div>
<?php elseif (!$items): ?><div class="empty"><strong>This folder is empty</strong>There are no visible files or folders here.</div>
<?php else: ?><div class="listing-head" aria-hidden="true"><span>Name</span><span>Size</span><span class="modified-head">Modified</span><span></span></div><?php foreach ($items as $item): $relative = implode('/', array_merge($directoryParts, [$item['name']])); $path = '/' . $relative; $icon = $item['dir'] ? 'folder' : 'file'; $open = $item['dir'] ? url_for($script, ['dir' => $path . '/']) : url_for($script, ['download' => $relative]); ?>
<div class="row"><a class="main-link" href="<?= h($open) ?>"<?= $item['dir'] ? '' : ' download' ?> aria-label="<?= h(($item['dir'] ? 'Open folder ' : 'Download ') . $item['name']) ?>"><span class="file-icon<?= $item['dir'] ? ' folder' : '' ?>"><?= icon_svg($icon) ?></span><span class="label"><span class="name"><?= h($item['name']) ?></span><span class="kind"><?= $item['dir'] ? 'Folder' : strtoupper(pathinfo($item['name'], PATHINFO_EXTENSION) ?: 'File') ?></span></span></a><span class="meta size"><?= $item['dir'] ? '—' : h(pretty_size($item['size'])) ?></span><span class="meta modified"><?= $item['mtime'] === null ? '—' : h(date('Y-m-d H:i', $item['mtime'])) ?></span><div class="actions"><?php if ($item['dir']): ?><a class="button" href="<?= h($open) ?>"><?= icon_svg('open') ?> Open</a><?php if (class_exists('ZipArchive')): ?><a class="button zip" href="<?= h(url_for($script, ['zipdir' => $relative])) ?>" aria-label="Download <?= h($item['name']) ?> as ZIP"><?= icon_svg('archive') ?> ZIP</a><?php endif; ?><?php else: ?><?php if (previewable($item['name'])): ?><a class="button" href="<?= h(url_for($script, ['view' => $relative])) ?>">Preview</a><?php endif; ?><a class="button primary" href="<?= h($open) ?>" download><?= icon_svg('download') ?> Download</a><?php endif; ?></div></div><?php endforeach; ?><?php endif; ?></section>
<footer class="footer">Directory browser</footer>
</main></body></html>
