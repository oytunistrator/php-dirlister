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
function route_url($script, $parts, $directory = true) {
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '.') $base = '';
    $path = $base . '/' . implode('/', array_map('rawurlencode', $parts));
    if ($directory && substr($path, -1) !== '/') $path .= '/';
    return $path === '' ? '/' : $path;
}
function page_url($script, $parts, $page) {
    $url = route_url($script, $parts);
    return $page <= 1 ? $url : $url . '?page=' . (int)$page;
}
function pagination_html($script, $parts, $page, $pageCount, $total, $start, $end) {
    if ($pageCount <= 1) return '';
    $previous = $page > 1 ? '<a class="page-button" href="' . h(page_url($script, $parts, $page - 1)) . '" rel="prev">Previous</a>' : '<span class="page-button disabled">Previous</span>';
    $next = $page < $pageCount ? '<a class="page-button" href="' . h(page_url($script, $parts, $page + 1)) . '" rel="next">Next</a>' : '<span class="page-button disabled">Next</span>';
    return '<nav class="pagination" aria-label="Pagination"><span class="page-summary">Showing ' . (int)$start . '–' . (int)$end . ' of ' . (int)$total . '</span><div class="page-controls">' . $previous . '<span class="page-number">Page ' . (int)$page . ' of ' . (int)$pageCount . '</span>' . $next . '</div></nav>';
}
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
    header('Content-Transfer-Encoding: binary');
    if ($size === false) {
        header('Accept-Ranges: none');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') stream_handle($handle); else fclose($handle);
        exit;
    }

    header('Accept-Ranges: bytes');
    $mtime = @filemtime($path);
    if ($mtime !== false) header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    $start = 0; $end = $size - 1; $partial = false;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $ifRange = trim($_SERVER['HTTP_IF_RANGE'] ?? '');
    if ($ifRange !== '') {
        $ifRangeTime = strtotime($ifRange);
        if ($mtime === false || $ifRangeTime === false || $mtime > $ifRangeTime) $range = '';
    }
    if (preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match)) {
        $first = $match[1]; $last = $match[2];
        if (($first === '' && $last === '') || $size === 0) {
            fclose($handle);
            header('Content-Range: bytes */' . $size);
            fail_request(416, 'Requested range is not satisfiable.');
        }
        if ($first === '') {
            $normalizedSuffix = ltrim($last, '0');
            if ($normalizedSuffix === '') {
                fclose($handle);
                header('Content-Range: bytes */' . $size);
                fail_request(416, 'Requested range is not satisfiable.');
            }
            $suffix = filter_var($normalizedSuffix, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $start = $suffix === false ? 0 : max(0, $size - $suffix);
        } else {
            $normalizedStart = ltrim($first, '0');
            if ($normalizedStart === '') $normalizedStart = '0';
            $start = filter_var($normalizedStart, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $normalizedEnd = ltrim($last, '0');
            if ($normalizedEnd === '') $normalizedEnd = '0';
            $requestedEnd = $last === '' ? $size - 1 : filter_var($normalizedEnd, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($last !== '' && $requestedEnd === false) $requestedEnd = $size - 1;
            if ($start === false || $requestedEnd === false || $start >= $size || $requestedEnd < $start) {
                fclose($handle);
                header('Content-Range: bytes */' . $size);
                fail_request(416, 'Requested range is not satisfiable.');
            }
            $end = min($requestedEnd, $size - 1);
        }
        $partial = true;
    }

    $length = $size === 0 ? 0 : $end - $start + 1;
    if ($partial) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . $length);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        fclose($handle);
    } elseif ($length === 0) {
        fclose($handle);
    } elseif (fseek($handle, $start) !== 0) {
        fclose($handle);
        fail_request(500, 'Could not seek to the requested file range.');
    } else {
        @set_time_limit(0);
        while ($length > 0 && !feof($handle)) {
            $chunk = @fread($handle, min(1048576, $length));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $length -= strlen($chunk);
            flush();
        }
        fclose($handle);
    }
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
    $directoryParts = $viewParent; $viewBackUrl = route_url($script, $viewParent);
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
    if ($explicitDir) {
        header('Location: ' . route_url($script, $directoryParts), true, 301);
        exit;
    }
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
    $pageSize = 20;
    $pageValue = qv('page');
    if ($pageValue === false || ($pageValue !== null && !preg_match('/^[1-9][0-9]{0,5}$/', $pageValue))) fail_request(400, 'Invalid page number.');
    $totalItems = count($items);
    $pageCount = max(1, (int)ceil($totalItems / $pageSize));
    $page = $pageValue === null ? 1 : (int)$pageValue;
    if ($page > $pageCount) {
        header('Location: ' . page_url($script, $directoryParts, $pageCount), true, 302);
        exit;
    }
    $showingStart = $totalItems ? (($page - 1) * $pageSize + 1) : 0;
    $showingEnd = min($totalItems, $page * $pageSize);
    $items = array_slice($items, ($page - 1) * $pageSize, $pageSize);
}
$siteName = 'Files';
$currentPath = '/' . implode('/', $directoryParts); if ($currentPath !== '/') $currentPath .= '/';
$currentName = $directoryParts ? end($directoryParts) : $siteName;
$parentParts = array_slice($directoryParts, 0, -1);
$parentUrl = route_url($script, $parentParts);
$rootUrl = route_url($script, []);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light">
<title><?= h($currentName) ?> · <?= h($siteName) ?></title>
<style>
:root{color-scheme:light;--ink:#1f2937;--muted:#667085;--line:#e5e9f0;--accent:#315eea;--soft:#eef3ff}*{box-sizing:border-box}html{min-height:100%;background:#f7f8fa}body{margin:0;min-height:100vh;color:var(--ink);background:#f7f8fa;font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}a{color:inherit}.topbar{border-bottom:1px solid var(--line);background:#fff}.top-inner,.shell{width:min(960px,calc(100% - 36px));margin:auto}.top-inner{min-height:58px;display:flex;align-items:center;justify-content:space-between}.brand{display:inline-flex;align-items:center;gap:9px;font-weight:700;text-decoration:none}.mark{width:30px;height:30px;display:grid;place-items:center;border-radius:8px;color:var(--accent);background:var(--soft)}.mark svg{width:19px}.top-note{color:var(--muted);font-size:13px}.shell{padding:34px 0 40px}.intro{margin:0 0 18px}h1{margin:0;font-size:clamp(25px,4vw,32px);line-height:1.2;font-weight:680;letter-spacing:-.025em;overflow-wrap:anywhere}.breadcrumbs{display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin:0 0 14px;color:var(--muted);font-size:13px}.breadcrumbs a{color:#536174;text-decoration:none}.breadcrumbs a:hover{color:var(--accent);text-decoration:underline}.sep{color:#a0a9b7}.panel{overflow:hidden;border:1px solid var(--line);border-radius:12px;background:#fff;box-shadow:0 4px 18px rgba(25,42,70,.045)}.panel-head{min-height:76px;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:14px;border-bottom:1px solid var(--line)}.panel-head h2{margin:0;font-size:16px;font-weight:650}.panel-head p{margin:3px 0 0;color:var(--muted);font-size:13px}.head-actions,.actions{display:flex;align-items:center;justify-content:flex-end;gap:7px}.button{min-height:34px;padding:6px 10px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid #d9e0eb;border-radius:7px;background:#fff;color:#344054;font:inherit;font-size:12px;font-weight:550;text-decoration:none;white-space:nowrap}.button:hover{border-color:#b7c4d8;background:#f8faff}.button.primary{border-color:var(--accent);color:#fff;background:var(--accent)}.button.primary:hover{background:#244fd4}.button svg{width:15px;height:15px}.listing-head,.row{display:grid;grid-template-columns:minmax(0,1fr) 90px 135px 158px;align-items:center;gap:14px}.listing-head{padding:10px 20px;color:#717d90;background:#fafbfc;border-bottom:1px solid var(--line);font-size:11px;font-weight:600}.listing-head span:not(:first-child){text-align:right}.row{min-height:66px;padding:10px 20px;border-bottom:1px solid #edf0f4}.row:last-child{border-bottom:0}.row:hover{background:#fbfcff}.main-link{min-width:0;display:flex;align-items:center;gap:11px;text-decoration:none}.file-icon{width:34px;height:34px;flex:0 0 34px;display:grid;place-items:center;border:1px solid #e7eaf0;border-radius:9px;color:#65748b;background:#f8f9fb}.file-icon.folder{border-color:#e1e8ff;color:#4564cd;background:var(--soft)}.file-icon svg{width:19px;height:19px}.label{min-width:0}.name{display:block;overflow:hidden;color:#202c3c;font-weight:600;text-overflow:ellipsis;white-space:nowrap}.main-link:hover .name{color:var(--accent);text-decoration:underline}.kind{display:block;margin-top:1px;color:#7b8798;font-size:11px}.meta{color:#68778b;text-align:right;font-size:12px;white-space:nowrap}.actions .button{min-height:31px;padding:5px 8px}.actions .zip{color:#087e68}.pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;border-bottom:1px solid var(--line);background:#fff}.pagination-bottom{border-top:1px solid var(--line);border-bottom:0}.page-summary{color:var(--muted);font-size:12px}.page-controls{display:flex;align-items:center;gap:8px}.page-number{min-width:70px;color:#475467;text-align:center;font-size:12px}.page-button{min-height:32px;padding:5px 10px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #d9e0eb;border-radius:7px;color:#344054;background:#fff;font:inherit;font-size:12px;text-decoration:none}.page-button:hover{border-color:#b7c4d8;background:#f8faff}.page-button.disabled{color:#a0a8b5;background:#fafbfc}.empty{padding:42px 18px;color:var(--muted);text-align:center}.empty strong{display:block;margin-bottom:4px;color:var(--ink);font-weight:600}.preview{padding:18px 20px 20px}.preview-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.preview-top h2{margin:0;min-width:0;overflow-wrap:anywhere;font-size:15px;font-weight:600}.preview-content{max-height:70vh;min-height:85px;overflow:auto;margin:0;padding:15px;border:1px solid var(--line);border-radius:8px;color:#344256;background:#fafbfc;font:12px/1.6 ui-monospace,monospace;white-space:pre-wrap;overflow-wrap:anywhere}.preview-limit{color:var(--muted);font-size:13px}.footer{display:none}@media(max-width:820px){.listing-head,.row{grid-template-columns:minmax(0,1fr) 82px 150px;gap:10px}.modified,.modified-head{display:none}.shell{padding-top:28px}}@media(max-width:600px){.top-inner,.shell{width:calc(100% - 28px)}.top-inner{min-height:52px}.top-note{display:none}.shell{padding-top:22px}.panel{border-radius:10px}.panel-head{min-height:68px;padding:13px 14px}.panel-head p{font-size:12px}.head-actions .button{min-height:32px;padding:5px 8px}.listing-head{display:none}.row{grid-template-columns:minmax(0,1fr) auto;gap:8px;min-height:60px;padding:9px 12px}.size,.modified{display:none}.main-link{gap:9px}.file-icon{width:31px;height:31px;flex-basis:31px}.file-icon svg{width:18px;height:18px}.actions{gap:5px}.actions .button{min-height:30px;padding:4px 7px;font-size:11px}.preview{padding:14px}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<header class="topbar"><div class="top-inner"><a class="brand" href="<?= h($rootUrl) ?>"><span class="mark"><?= icon_svg('folder') ?></span><span><?= h($siteName) ?></span></a><span class="top-note">File downloads</span></div></header>
<main class="shell">
<section class="intro"><h1><?= h($currentName) ?></h1></section>
<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="<?= h($rootUrl) ?>"><?= h($siteName) ?></a><?php $crumbs = []; foreach ($directoryParts as $crumb): $crumbs[] = $crumb; ?><span class="sep" aria-hidden="true">/</span><?php if (count($crumbs) === count($directoryParts)): ?><span aria-current="page"><?= h($crumb) ?></span><?php else: ?><a href="<?= h(route_url($script, $crumbs)) ?>"><?= h($crumb) ?></a><?php endif; ?><?php endforeach; ?></nav>
<section class="panel" aria-labelledby="contents-heading"><div class="panel-head"><div><h2 id="contents-heading"><?= $preview !== null ? 'File preview' : 'Files and folders' ?></h2><p><?= $preview !== null ? 'Text preview · files up to 1 MB' : $totalItems . ' ' . ($totalItems === 1 ? 'item' : 'items') . ($totalItems ? ' · click a file to download' : '') ?></p></div><div class="head-actions"><?php if ($preview !== null): ?><a class="button" href="<?= h($viewBackUrl) ?>"><?= icon_svg('back') ?> Back</a><?php elseif ($directoryParts): ?><a class="button" href="<?= h($parentUrl) ?>"><?= icon_svg('back') ?> Up one level</a><?php endif; ?></div></div>
<?php if ($preview === null) echo pagination_html($script, $directoryParts, $page, $pageCount, $totalItems, $showingStart, $showingEnd); ?>
<?php if ($preview !== null): ?><div class="preview"><div class="preview-top"><h2><?= h($viewName) ?></h2><a class="button primary" href="<?= h($viewDownloadUrl) ?>" download><?= icon_svg('download') ?> Download</a></div><?php if ($preview === false): ?><p class="preview-limit">This file is larger than 1 MB. Download it to view the full contents.</p><?php else: ?><pre class="preview-content"><?= h($preview) ?></pre><?php endif; ?></div>
<?php elseif (!$items): ?><div class="empty"><strong>This folder is empty</strong>There are no visible files or folders here.</div>
<?php else: ?><div class="listing-head" aria-hidden="true"><span>Name</span><span>Size</span><span class="modified-head">Modified</span><span>Action</span></div><?php foreach ($items as $item): $relativeParts = array_merge($directoryParts, [$item['name']]); $relative = implode('/', $relativeParts); $icon = $item['dir'] ? 'folder' : 'file'; $open = route_url($script, $relativeParts, $item['dir']); ?>
<div class="row"><a class="main-link" href="<?= h($open) ?>"<?= $item['dir'] ? '' : ' download' ?> aria-label="<?= h(($item['dir'] ? 'Open folder ' : 'Download ') . $item['name']) ?>"><span class="file-icon<?= $item['dir'] ? ' folder' : '' ?>"><?= icon_svg($icon) ?></span><span class="label"><span class="name"><?= h($item['name']) ?></span><span class="kind"><?= $item['dir'] ? 'Folder' : strtoupper(pathinfo($item['name'], PATHINFO_EXTENSION) ?: 'File') ?></span></span></a><span class="meta size"><?= $item['dir'] ? '—' : h(pretty_size($item['size'])) ?></span><span class="meta modified"><?= $item['mtime'] === null ? '—' : h(date('Y-m-d H:i', $item['mtime'])) ?></span><div class="actions"><?php if ($item['dir']): ?><a class="button" href="<?= h($open) ?>"><?= icon_svg('open') ?> Open</a><?php if (class_exists('ZipArchive')): ?><a class="button zip" href="<?= h(url_for($script, ['zipdir' => $relative])) ?>" aria-label="Download <?= h($item['name']) ?> as ZIP"><?= icon_svg('archive') ?> ZIP</a><?php endif; ?><?php else: ?><?php if (previewable($item['name'])): ?><a class="button" href="<?= h(url_for($script, ['view' => $relative])) ?>">Preview</a><?php endif; ?><a class="button primary" href="<?= h($open) ?>" download><?= icon_svg('download') ?> Download</a><?php endif; ?></div></div><?php endforeach; ?><?= pagination_html($script, $directoryParts, $page, $pageCount, $totalItems, $showingStart, $showingEnd) ?><?php endif; ?></section>

</main></body></html>
