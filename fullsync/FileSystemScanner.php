<?php
/*
================================================================================
FILE SYSTEM SCANNER - Comprehensive local file tracking and change detection
================================================================================
This module handles:
1. Complete filesystem scanning of markdown files
2. Change detection by comparing current vs previous state
3. File metadata tracking (creation time, modification time, size)
4. Conflict detection by comparing local changes with remote sync times
================================================================================
*/

class FileSystemScanner {
    private $baseFolder;
    private $metadataPath;
    
    public function __construct($baseFolder) {
        $this->baseFolder = rtrim($baseFolder, '/');
        $this->metadataPath = $this->baseFolder . '/.outline';
    }
    
    /**
     * Perform complete scan of local filesystem
     * Returns array of all markdown files with metadata
     */
    public function scanFileSystem() {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseFolder, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'md') {
                $relativePath = $this->getRelativePath($file->getPathname());
                
                // Skip the .outline file
                if (strpos($relativePath, '.outline') !== false) {
                    continue;
                }
                
                $fileData = $this->analyzeFile($file->getPathname(), $relativePath);
                if ($fileData) {
                    $files[$relativePath] = $fileData;
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Analyze a single file and extract metadata
     */
    private function analyzeFile($fullPath, $relativePath) {
        if (!file_exists($fullPath)) {
            return null;
        }
        
        $stat = stat($fullPath);
        $content = file_get_contents($fullPath);
        
        // Extract frontmatter (if it exists)
        $frontmatter = $this->extractFrontmatter($content);
        $outlineId = $frontmatter['id_outline'] ?? null;
        
        return [
            'path' => $relativePath,
            'full_path' => $fullPath,
            'outline_id' => $outlineId,
            'size' => $stat['size'],
            'created_time' => $stat['ctime'],
            'modified_time' => $stat['mtime'],
            'has_outline_id' => !empty($outlineId) && $outlineId !== 'null',
            'is_readme' => (basename($relativePath) === 'README.md'),
            'frontmatter' => $frontmatter,
            'content_hash' => md5($content), // For conflict detection
            'has_frontmatter' => !empty($frontmatter)
        ];
    }
    
    /**
     * Extract frontmatter from markdown content
     */
    private function extractFrontmatter($content) {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            return [];
        }
        
        $frontmatter = [];
        $lines = explode("\n", $matches[1]);
        
        foreach ($lines as $line) {
            if (preg_match('/^([^:]+):\s*(.*)$/', trim($line), $lineMatch)) {
                $key = trim($lineMatch[1]);
                $value = trim($lineMatch[2], ' "\'');
                $frontmatter[$key] = $value;
            }
        }
        
        return $frontmatter;
    }
    
    /**
     * Compare current scan with previous metadata to detect changes
     */
    public function detectChanges($currentScan) {
        $previousMetadata = $this->loadPreviousMetadata();
        
        $changes = [
            'new_files' => [],
            'modified_files' => [],
            'moved_files' => [],
            'deleted_files' => [],
            'conflicts' => []
        ];
        
        // Get previous file mappings
        $previousFiles = [];
        if (isset($previousMetadata['local_files'])) {
            foreach ($previousMetadata['local_files'] as $file) {
                $previousFiles[$file['path']] = $file;
            }
        }
        
        // If this is the first run (no previous metadata), don't detect any changes
        if (empty($previousMetadata)) {
            echo "📥 First run detected - no changes to analyze\n";
            return $changes; // Return empty changes array
        }
        
        // Detect new and modified files
        foreach ($currentScan as $path => $fileData) {
            if (!isset($previousFiles[$path])) {
                if ($fileData['has_outline_id']) {
                    // Check if this is a moved file (same outline_id, different path)
                    $movedFrom = $this->findPreviousPathByOutlineId($fileData['outline_id'], $previousFiles);
                    if ($movedFrom) {
                        $changes['moved_files'][] = [
                            'outline_id' => $fileData['outline_id'],
                            'from_path' => $movedFrom,
                            'to_path' => $path,
                            'file_data' => $fileData
                        ];
                        continue;
                    }
                }
                
                // This is a new file
                $changes['new_files'][] = $fileData;
            } else {
                // Check if modified
                $previousFile = $previousFiles[$path];
                if ($fileData['modified_time'] > $previousFile['modified_time']) {
                    // Check for conflicts
                    $lastSync = $previousMetadata['last_sync'] ?? 0;
                    $lastSyncTime = is_string($lastSync) ? strtotime($lastSync) : $lastSync;
                    
                    if ($fileData['modified_time'] > $lastSyncTime) {
                        // File was modified after last sync - potential conflict
                        $changes['conflicts'][] = [
                            'path' => $path,
                            'local_modified' => $fileData['modified_time'],
                            'last_sync' => $lastSyncTime,
                            'file_data' => $fileData
                        ];
                    }
                    
                    $changes['modified_files'][] = $fileData;
                }
            }
        }
        
        // Detect deleted files
        foreach ($previousFiles as $path => $previousFile) {
            if (!isset($currentScan[$path])) {
                // Check if this was moved (not deleted)
                $moved = false;
                foreach ($changes['moved_files'] as $move) {
                    if ($move['from_path'] === $path) {
                        $moved = true;
                        break;
                    }
                }
                
                if (!$moved) {
                    $changes['deleted_files'][] = $previousFile;
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Find previous path for a file by its outline ID
     */
    private function findPreviousPathByOutlineId($outlineId, $previousFiles) {
        foreach ($previousFiles as $path => $file) {
            if (isset($file['outline_id']) && $file['outline_id'] === $outlineId) {
                return $path;
            }
        }
        return null;
    }
    
    /**
     * Load previous metadata from .outline file
     */
    private function loadPreviousMetadata() {
        if (!file_exists($this->metadataPath)) {
            return [];
        }
        
        $content = file_get_contents($this->metadataPath);
        return json_decode($content, true) ?: [];
    }
    
    /**
     * Save current scan results to metadata
     */
    public function saveMetadata($currentScan, $additionalData = []) {
        $metadata = array_merge([
            'last_scan' => date('c'),
            'local_files' => array_values($currentScan)
        ], $additionalData);
        
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->metadataPath, $json);
        
        echo "💾 Saved filesystem metadata to .outline file\n";
    }
    
    /**
     * Get relative path from full path
     */
    private function getRelativePath($fullPath) {
        return str_replace($this->baseFolder . '/', '', $fullPath);
    }
    
    /**
     * Print summary of detected changes
     */
    public function printChangesSummary($changes) {
        echo "\n📊 Local Filesystem Changes Summary:\n";
        echo "- New files: " . count($changes['new_files']) . "\n";
        echo "- Modified files: " . count($changes['modified_files']) . "\n";
        echo "- Moved files: " . count($changes['moved_files']) . "\n";
        echo "- Deleted files: " . count($changes['deleted_files']) . "\n";
        echo "- Potential conflicts: " . count($changes['conflicts']) . "\n\n";
        
        if (!empty($changes['conflicts'])) {
            echo "⚠️  CONFLICTS DETECTED:\n";
            foreach ($changes['conflicts'] as $conflict) {
                echo "   - {$conflict['path']} (modified after last sync)\n";
            }
            echo "\n";
        }
    }
} 