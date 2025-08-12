<?php
/*
================================================================================
FILE OPERATIONS - Handle all local file system operations with metadata preservation
================================================================================
This module handles:
1. Creating markdown files with proper frontmatter
2. Setting file creation and modification times to match Outline
3. Moving and renaming files while preserving metadata
4. Folder structure creation and cleanup
5. Conflict detection and resolution helpers
================================================================================
*/





class FileOperations {
    private $baseFolder;
    
    public function __construct($baseFolder) {
        $this->baseFolder = rtrim($baseFolder, '/');
    }
    
    /**
     * Create markdown file with frontmatter and set proper timestamps
     */
    public function createMarkdownFile($document, $localPath, $isReadme = false) {
        $fullPath = $this->baseFolder . '/' . $localPath;
        
        // Ensure directory exists
        $directory = dirname($fullPath);
        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
            echo "  📁 Created directory: " . str_replace($this->baseFolder . '/', '', $directory) . "\n";
        }
        
        // Handle duplicate filenames
        $fullPath = $this->getUniqueFilepath($fullPath);
        
        // Create frontmatter - use short ID if available, fallback to long ID
        $frontmatter = [
            'id_outline' => $document['urlId'] ?? $document['id']
        ];
        
        // Create content
        $content = $this->buildMarkdownContent($frontmatter, $document['text'] ?? '');
        
        // Write file
        file_put_contents($fullPath, $content);
        
        // Set file timestamps to match Outline
        $this->setFileTimestamps($fullPath, $document);
        
        echo "  ✅ Created: " . str_replace($this->baseFolder . '/', '', $fullPath) . "\n";
        
        return $fullPath;
    }
    
    /**
     * Update existing markdown file content and timestamps
     */
    public function updateMarkdownFile($filePath, $document, $updateContentFromRemote = false) {
        if (!file_exists($filePath)) {
            throw new Exception("File does not exist: $filePath");
        }
        
        // Read existing content
        $existingContent = file_get_contents($filePath);
        $existingFrontmatter = $this->extractFrontmatter($existingContent);
        
        // If no frontmatter exists, create minimal frontmatter
        if (empty($existingFrontmatter)) {
            $existingFrontmatter = [];
        }
        
        // ONLY update the id_outline field, preserve all other frontmatter
        $existingFrontmatter['id_outline'] = $document['urlId'] ?? $document['id'];
        
        // Extract existing content (without frontmatter)
        $bodyContent = $this->extractContentFromFile($filePath);
        
        // Determine final content based on update type
        if ($updateContentFromRemote) {
            // This is a remote content update - use remote content for body
            $finalContent = $document['text'] ?? '';
            echo "  🔄 Updating body content from remote\n";
        } else {
            // This is just an ID update or file management - preserve local content
            $finalContent = $bodyContent ?: ($document['text'] ?? '');
            echo "  📝 Preserving local content, updating frontmatter only\n";
        }
        
        // Create new content with preserved frontmatter
        $newContent = $this->buildMarkdownContent($existingFrontmatter, $finalContent);
        
        // Write updated content
        file_put_contents($filePath, $newContent);
        
        // Update timestamps
        $this->setFileTimestamps($filePath, $document);
        
        echo "  ✅ Updated: " . str_replace($this->baseFolder . '/', '', $filePath) . "\n";
    }
    
    /**
     * Move file from one location to another, preserving metadata
     */
    public function moveFile($fromPath, $toPath) {
        $fullFromPath = $this->startsWith($fromPath, $this->baseFolder) ? $fromPath : $this->baseFolder . '/' . $fromPath;
        $fullToPath = $this->startsWith($toPath, $this->baseFolder) ? $toPath : $this->baseFolder . '/' . $toPath;
        
        if (!file_exists($fullFromPath)) {
            throw new Exception("Source file does not exist: $fullFromPath");
        }
        
        // Ensure destination directory exists
        $destDir = dirname($fullToPath);
        if (!file_exists($destDir)) {
            mkdir($destDir, 0777, true);
            echo "  📁 Created directory: " . str_replace($this->baseFolder . '/', '', $destDir) . "\n";
        }
        
        // Handle duplicate filenames at destination
        $fullToPath = $this->getUniqueFilepath($fullToPath);
        
        // Move file
        if (rename($fullFromPath, $fullToPath)) {
            echo "  🚚 Moved: " . str_replace($this->baseFolder . '/', '', $fullFromPath) . 
                 " → " . str_replace($this->baseFolder . '/', '', $fullToPath) . "\n";
            
            // Clean up empty source directory
            $this->cleanupEmptyDirectory(dirname($fullFromPath));
            
            return $fullToPath;
        } else {
            throw new Exception("Failed to move file from $fullFromPath to $fullToPath");
        }
    }
    
    /**
     * Rename entire folder (for parent document title changes)
     */
    public function renameFolder($fromPath, $toPath) {
        $fullFromPath = $this->startsWith($fromPath, $this->baseFolder) ? $fromPath : $this->baseFolder . '/' . $fromPath;
        $fullToPath = $this->startsWith($toPath, $this->baseFolder) ? $toPath : $this->baseFolder . '/' . $toPath;
        
        if (!is_dir($fullFromPath)) {
            throw new Exception("Source directory does not exist: $fullFromPath");
        }
        
        // Ensure parent directory exists for destination
        $parentDir = dirname($fullToPath);
        if (!file_exists($parentDir)) {
            mkdir($parentDir, 0777, true);
            echo "  📁 Created parent directory: " . str_replace($this->baseFolder . '/', '', $parentDir) . "\n";
        }
        
        // Rename folder
        if (rename($fullFromPath, $fullToPath)) {
            echo "  📁 Renamed folder: " . str_replace($this->baseFolder . '/', '', $fullFromPath) . 
                 " → " . str_replace($this->baseFolder . '/', '', $fullToPath) . "\n";
            
            // Clean up empty parent directory if needed
            $this->cleanupEmptyDirectory(dirname($fullFromPath));
            
            return $fullToPath;
        } else {
            throw new Exception("Failed to rename folder from $fullFromPath to $fullToPath");
        }
    }
    
    /**
     * Delete file and cleanup empty directories
     */
    public function deleteFile($filePath) {
        $fullPath = $this->startsWith($filePath, $this->baseFolder) ? $filePath : $this->baseFolder . '/' . $filePath;
        
        if (!file_exists($fullPath)) {
            echo "  ⚠️  File already deleted: " . str_replace($this->baseFolder . '/', '', $fullPath) . "\n";
            return true;
        }
        
        if (unlink($fullPath)) {
            echo "  🗑️  Deleted: " . str_replace($this->baseFolder . '/', '', $fullPath) . "\n";
            
            // Clean up empty directory
            $this->cleanupEmptyDirectory(dirname($fullPath));
            
            return true;
        } else {
            throw new Exception("Failed to delete file: $fullPath");
        }
    }
    
    /**
     * Extract text content from markdown file (without frontmatter)
     */
    public function extractContentFromFile($filePath) {
        if (!file_exists($filePath)) {
            return '';
        }
        
        $content = file_get_contents($filePath);
        
        // Check if file has frontmatter
        if (preg_match('/^---\s*\n.*?\n---\s*\n/s', $content)) {
            // Remove frontmatter
            $content = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
        }
        // If no frontmatter, use entire content
        
        return trim($content);
    }
    
    /**
     * Extract frontmatter from markdown content
     */
    public function extractFrontmatter($content) {
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
     * Get title from file path (extract from folder name or filename)
     */
    public function extractTitleFromPath($filePath) {
        $relativePath = str_replace($this->baseFolder . '/', '', $filePath);
        
        if (basename($relativePath) === 'README.md') {
            // For README files, use the parent folder name
            return basename(dirname($relativePath));
        } else {
            // For regular files, use the filename without extension
            return pathinfo(basename($relativePath), PATHINFO_FILENAME);
        }
    }
    
    /**
     * Set file creation and modification times to match Outline document
     */
    private function setFileTimestamps($filePath, $document) {
        // Handle null timestamps gracefully
        $createdTime = !empty($document['createdAt']) ? strtotime($document['createdAt']) : time();
        $modifiedTime = !empty($document['updatedAt']) ? strtotime($document['updatedAt']) : time();
        
        // Set modification time
        touch($filePath, $modifiedTime);
        
        // Set creation time using macOS stat command
        if (PHP_OS === 'Darwin' && $createdTime) {
            $createdTimeStr = date('m/d/Y H:i:s', $createdTime);
            $cmd = "SetFile -d '$createdTimeStr' " . escapeshellarg($filePath) . " 2>/dev/null";
            exec($cmd);
        }
    }
    
    /**
     * Build markdown content with frontmatter
     */
    private function buildMarkdownContent($frontmatter, $text) {
        $content = "---\n";
        foreach ($frontmatter as $key => $value) {
            // For simple string values, don't use quotes
            if (is_string($value) && !empty($value)) {
                $content .= "$key: $value\n";
            } else {
                // For complex values (arrays, objects, null), use json_encode
                $content .= "$key: " . json_encode($value) . "\n";
            }
        }
        $content .= "---\n\n";
        $content .= $this->cleanText($text);
        
        return $content;
    }
    
    /**
     * Clean text content
     */
    private function cleanText($text) {
        // Remove multiple backslashes followed by newlines
        $text = preg_replace('/\\\\\s*\n/', "\n", $text);
        
        // Remove standalone backslashes
        $text = preg_replace('/^\\\\\s*$/m', '', $text);
        
        // Remove excessive blank lines (more than 2 consecutive newlines)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        // Remove single blank line between header and its content
        $text = preg_replace('/^(#{2,4}.*)\n\n(?!#)/m', "$1\n", $text);
        
        // Trim extra whitespace at start and end
        return trim($text);
    }
    
    /**
     * Generate unique filepath when duplicates exist
     */
    private function getUniqueFilepath($filepath) {
        if (!file_exists($filepath)) {
            return $filepath;
        }
        
        $pathInfo = pathinfo($filepath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        
        $counter = 2;
        do {
            $newFilename = $filename . '-' . $counter . $extension;
            $newFilepath = $directory . '/' . $newFilename;
            $counter++;
        } while (file_exists($newFilepath));
        
        echo "  ⚠️  Duplicate filename - renamed to: " . basename($newFilepath) . "\n";
        
        return $newFilepath;
    }
    
    /**
     * Clean up empty directory if it's not the base folder
     */
    private function cleanupEmptyDirectory($dirPath) {
        if ($dirPath === $this->baseFolder || !is_dir($dirPath)) {
            return;
        }
        
        // Check if directory is empty
        $files = array_diff(scandir($dirPath), ['.', '..']);
        if (empty($files)) {
            rmdir($dirPath);
            echo "  🗂️  Removed empty directory: " . str_replace($this->baseFolder . '/', '', $dirPath) . "\n";
            
            // Recursively clean up parent if it's also empty
            $this->cleanupEmptyDirectory(dirname($dirPath));
        }
    }
    
    /**
     * Check if string starts with another string
     */
    private function startsWith($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
    
    /**
     * Determine parent document ID from file path
     */
    public function determineParentFromPath($filePath, $hierarchy) {
        $relativePath = str_replace($this->baseFolder . '/', '', $filePath);
        $pathParts = explode('/', $relativePath);
        
        // Remove the filename to get just the folder path
        array_pop($pathParts);
        
        if (empty($pathParts)) {
            return null; // Root level file
        }
        
        // Look for README.md in the parent folder to get the parent document ID
        $parentFolderPath = implode('/', $pathParts) . '/README.md';
        $parentFullPath = $this->baseFolder . '/' . $parentFolderPath;
        
        if (file_exists($parentFullPath)) {
            $frontmatter = $this->extractFrontmatter(file_get_contents($parentFullPath));
            return $frontmatter['id_outline'] ?? null;
        }
        
        return null;
    }
} 