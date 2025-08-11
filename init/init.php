<?php

$config = parse_ini_file(__DIR__ . '/config.ini');

$token = $config['token'];
$domain = $config['domain'];
$baseUrl = "https://$domain";

$headers = [
    'Authorization' => "Bearer $token",
    'Content-Type' => 'application/json'
];

// Sync Configuration
$syncConfig = [
    'base_folder' => $config['base_folder'],  // Main local sync folder
    'collection_id' => $config['collection_id']  // Outline collection ID
];


// Inclusion
$inclusion = $config['path_inclusion'];
if (file_exists($inclusion)) { require_once $inclusion; } else { echo "Inclusion file not found."; }

$settings = [
    'path' => '/Users/Reess/Code/',
    'files' => [
        // 'Inc/Utilities/SendEmail/inc/functions.php', // Mail had to be at top to make it work
        'Inc/Connect/Http/http.php',  // This is the API request snippet
        // 'Inc/Utilities/ArrayTable/ArrayTable.php',
        // 'Inc/Utilities/ArrayVis/ArrayVis.php',
        // 'Inc/Connect/Csv/Read.php',
    ],
    'suffix' => '.php', // Optional suffix
    'stats' => 'emoji', // Optional stats setting (bool, text, or emoji)
    'display' => 'text_csv', // Optional display setting (array or text_csv)
    'action' => 1, // Options: 'include' (1), 'merge' (2), 'functions' (3)
    'merged' => 'functions.php', // Relative Path for merged output if action is 'merge'
    'depth' => 'all' // New setting: determines directory scanning depth
];

if (function_exists('Inclusion')) {
    Inclusion($settings);
} else {
    include ($settings['merged']); // Allows me to not include the Inclusion code
}











