<?php
require __DIR__ . '/Chat/Chat.php';

$ip = '0.0.0.0'; // ip
$port = 8888;      // 端口号

new \Chat\Chat($ip, $port);
