<?php

// Load configuration from the consolidated config.yaml file
$configPath = __DIR__ . '/config.yaml';
if (!file_exists($configPath)) {
    die("❌ Error: config.yaml not found. Please create this file with your configuration.\n");
}

$configContent = file_get_contents($configPath);
$config = parseYaml($configContent);

$token = $config['outline']['token'];
$domain = $config['outline']['domain'];
$baseUrl = "https://$domain";

$headers = [
    'Authorization' => "Bearer $token",
    'Content-Type' => 'application/json'
];

/**
 * Simple YAML parser for our specific use case
 */
function parseYaml($yamlContent) {
    $lines = explode("\n", $yamlContent);
    $result = [];
    $currentPath = [];
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        
        // Skip empty lines and comments
        if (empty($line) || strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Calculate indentation level
        $indent = strlen($line) - strlen(ltrim($line));
        $trimmed = trim($line);
        
        // Adjust current path based on indentation
        $level = intval($indent / 2);
        $currentPath = array_slice($currentPath, 0, $level);
        
        if (strpos($trimmed, ':') !== false) {
            list($key, $value) = explode(':', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if (!empty($value) && strlen($value) >= 2) {
                $firstChar = $value[0];
                $lastChar = $value[strlen($value) - 1];
                if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            
            $currentPath[] = $key;
            
            if (!empty($value)) {
                // Set value
                setNestedValue($result, $currentPath, $value);
                array_pop($currentPath);
            }
        }
    }
    
    return $result;
}

/**
 * Set nested array value using path
 */
function setNestedValue(&$array, $path, $value) {
    $current = &$array;
    foreach ($path as $key) {
        if (!isset($current[$key])) {
            $current[$key] = [];
        }
        $current = &$current[$key];
    }
    $current = $value;
}

// Note: Sync configuration is now handled by sync-settings.yaml
// and managed by the CollectionSelector class








