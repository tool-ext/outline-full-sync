<?php

$config = parse_ini_file(__DIR__ . '/config.ini');

$token = $config['token'];
$domain = $config['domain'];
$baseUrl = "https://$domain";

$headers = [
    'Authorization' => "Bearer $token",
    'Content-Type' => 'application/json'
];

// Note: Sync configuration is now handled by sync-settings.yaml
// and managed by the CollectionSelector class








