<?php
/*
================================================================================
COLLECTION SELECTOR - Interactive collection selection and settings management
================================================================================
This class handles:
1. Fetching and displaying available collections
2. Interactive collection selection menu
3. Loading and validating sync-settings.yaml configuration
4. Mapping collection IDs to local paths
================================================================================
*/

class CollectionSelector {
    private $baseUrl;
    private $headers;
    private $settingsPath;
    
    public function __construct($baseUrl, $headers) {
        $this->baseUrl = $baseUrl;
        $this->headers = $headers;
        $this->settingsPath = __DIR__ . '/../init/sync-settings.yaml';
    }
    
    /**
     * Show collection selector and return selected collection info
     */
    public function selectCollection() {
        echo "🔍 Fetching available collections...\n\n";
        
        try {
            // Create temporary RemoteSync to fetch collections
            $remoteSync = new RemoteSync($this->baseUrl, $this->headers, '', '');
            $collections = $remoteSync->fetchAllCollections();
            
            if (empty($collections)) {
                throw new Exception("No collections found in your Outline workspace");
            }
            
            // Display collections in compact format
            echo "📚 Available Collections:\n";
            echo "═══════════════════════════════════════════════════\n";
            
            $index = 0;
            foreach ($collections as $collection) {
                $number = $index + 1;
                echo sprintf("%2d. %s - %s\n", $number, $collection['name'], $collection['id']);
                $index++;
            }
            echo "\n";
            
            // Get user selection
            echo "Enter the number of the collection you want to sync: ";
            $handle = fopen("php://stdin", "r");
            $selection = trim(fgets($handle));
            fclose($handle);
            
            $selectedIndex = intval($selection) - 1;
            
            // Convert collections to indexed array if needed
            $collectionsArray = array_values($collections);
            
            if ($selectedIndex < 0 || $selectedIndex >= count($collectionsArray)) {
                throw new Exception("Invalid selection. Please enter a number between 1 and " . count($collectionsArray));
            }
            
            $selectedCollection = $collectionsArray[$selectedIndex];
            
            echo "\n✅ Selected: {$selectedCollection['name']}\n";
            echo "🆔 Collection ID: {$selectedCollection['id']}\n\n";
            
            // Load and validate sync settings
            $syncSettings = $this->loadSyncSettings($selectedCollection['id']);
            
            return [
                'collection' => $selectedCollection,
                'settings' => $syncSettings
            ];
            
        } catch (Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    /**
     * Load sync settings from YAML file
     */
    private function loadSyncSettings($collectionId) {
        if (!file_exists($this->settingsPath)) {
            echo "❌ Error: sync-settings.yaml not found at: {$this->settingsPath}\n";
            echo "💡 Please create this file with your collection mappings.\n\n";
            echo "Example sync-settings.yaml:\n";
            echo "collections:\n";
            echo "  {$collectionId}:\n";
            echo "    name: \"My Collection\"\n";
            echo "    local_path: \"/path/to/local/folder\"\n";
            echo "    description: \"Description of this collection\"\n";
            exit(1);
        }
        
        // Parse YAML file
        $yamlContent = file_get_contents($this->settingsPath);
        $settings = $this->parseYaml($yamlContent);
        
        if (!isset($settings['collections'][$collectionId])) {
            echo "❌ Error: Collection ID '{$collectionId}' not defined in sync-settings.yaml\n";
            echo "💡 Please add this collection to your sync-settings.yaml file:\n\n";
            echo "collections:\n";
            echo "  {$collectionId}:\n";
            echo "    name: \"Collection Name\"\n";
            echo "    local_path: \"/path/to/local/folder\"\n";
            echo "    description: \"Description of this collection\"\n";
            exit(1);
        }
        
        $collectionSettings = $settings['collections'][$collectionId];
        
        // Validate required fields
        if (empty($collectionSettings['local_path'])) {
            echo "❌ Error: 'local_path' not defined for collection {$collectionId} in sync-settings.yaml\n";
            exit(1);
        }
        
        // Expand tilde in path
        if (isset($collectionSettings['local_path']) && strpos($collectionSettings['local_path'], '~') === 0) {
            $collectionSettings['local_path'] = str_replace('~', $_SERVER['HOME'], $collectionSettings['local_path']);
        }
        
        // Validate path exists or can be created
        $localPath = $collectionSettings['local_path'];
        if (!file_exists($localPath)) {
            echo "🔧 Creating local directory: {$localPath}\n";
            if (!mkdir($localPath, 0755, true)) {
                echo "❌ Error: Could not create directory: {$localPath}\n";
                exit(1);
            }
        }
        
        if (!is_dir($localPath)) {
            echo "❌ Error: local_path is not a directory: {$localPath}\n";
            exit(1);
        }
        
        if (!is_writable($localPath)) {
            echo "❌ Error: local_path is not writable: {$localPath}\n";
            exit(1);
        }
        
        echo "📁 Local path: {$localPath}\n\n";
        
        return $collectionSettings;
    }
    
    /**
     * Simple YAML parser for our specific use case
     */
    private function parseYaml($yamlContent) {
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
                if (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'")) {
                    $value = substr($value, 1, -1);
                }
                
                $currentPath[] = $key;
                
                if (!empty($value)) {
                    // Set value
                    $this->setNestedValue($result, $currentPath, $value);
                    array_pop($currentPath);
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Set nested array value using path
     */
    private function setNestedValue(&$array, $path, $value) {
        $current = &$array;
        foreach ($path as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        $current = $value;
    }
}
