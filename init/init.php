<?php

$config = parse_ini_file(__DIR__ . '/config.ini', true);

$token = $config['outline']['token'];
$domain = $config['outline']['domain'];
$baseUrl = "https://$domain";

$headers = [
    'Authorization' => "Bearer $token",
    'Content-Type' => 'application/json'
];

// Note: Sync configuration is now handled by sync-settings.yaml
// and managed by the CollectionSelector class








