<?php
/*
================================================================================
LOCAL TO REMOTE SYNC - Handle syncing local changes to Outline
================================================================================
This class manages the process of pushing local file changes to the remote
Outline service, including creating, updating, moving, and deleting documents.

Responsibilities:
1. Create new documents from new local files
2. Update remote documents from modified local files
3. Handle file moves by updating document parent relationships
4. Delete remote documents for deleted local files
5. Timestamp comparison to prevent overwriting newer remote content
================================================================================
*/

class LocalToRemoteSync {
    private $remoteSync;
    private $fileOps;
    private $baseFolder;
    
    public function __construct($remoteSync, $fileOps, $baseFolder) {
        $this->remoteSync = $remoteSync;
        $this->fileOps = $fileOps;
        $this->baseFolder = $baseFolder;
    }
    
    /**
     * Execute local to remote sync operations
     */
    public function execute($localChanges, $hierarchy) {
        echo "📤 Syncing local changes to Outline...\n";
        
        // If there are modified files, add a small delay to avoid race conditions
        if (!empty($localChanges['modified_files'])) {
            echo "⏱️  Detected modified files - adding 2 second delay to avoid race conditions...\n";
            sleep(2);
        }
        
        // Track successful operations for summary
        $successful = [
            'created' => 0,
            'updated' => 0,
            'moved' => 0,
            'deleted' => 0
        ];
        
        // Create new documents from new local files
        foreach ($localChanges['new_files'] as $newFile) {
            if (!$newFile['has_outline_id']) {
                if ($this->createRemoteDocument($newFile, $hierarchy)) {
                    $successful['created']++;
                }
            }
        }
        
        // Update remote documents from modified local files
        foreach ($localChanges['modified_files'] as $modifiedFile) {
            echo "🔍 Processing modified file: {$modifiedFile['path']}\n";
            echo "  📋 Has outline ID: " . ($modifiedFile['has_outline_id'] ? 'Yes' : 'No') . "\n";
            echo "  🆔 Outline ID: " . ($modifiedFile['outline_id'] ?? 'null') . "\n";
            echo "  📁 Full path: " . $this->baseFolder . '/' . $modifiedFile['path'] . "\n";
            
            // Check the actual file content to see what ID is stored
            $fullPath = $this->baseFolder . '/' . $modifiedFile['path'];
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                if (preg_match('/id_outline:\s*"?([^"\n\r]+)"?/i', $content, $matches)) {
                    $storedId = trim($matches[1]);
                    echo "  🔍 ID stored in file: $storedId\n";
                    if ($storedId !== $modifiedFile['outline_id']) {
                        echo "  ⚠️  ID mismatch! File has: $storedId, metadata has: {$modifiedFile['outline_id']}\n";
                    }
                } else {
                    echo "  ⚠️  No id_outline found in file content\n";
                }
            }
            
            if ($modifiedFile['has_outline_id'] && !empty($modifiedFile['outline_id']) && $modifiedFile['outline_id'] !== 'null') {
                // Check if remote document is newer before overwriting
                $remoteDoc = $this->findRemoteDocumentById($modifiedFile['outline_id']);
                echo "  🔍 Remote doc found: " . ($remoteDoc ? 'Yes' : 'No') . "\n";
                
                if ($remoteDoc) {
                    $localModified = $modifiedFile['modified_time'];
                    $remoteModified = strtotime($remoteDoc['updatedAt']);
                    
                    // Add a tolerance buffer for race conditions (5 seconds)
                    $toleranceSeconds = 5;
                    $timeDifference = $localModified - $remoteModified;
                    
                    echo "🔍 Comparing timestamps for {$modifiedFile['path']}:\n";
                    echo "   Local: " . date('Y-m-d H:i:s', $localModified) . "\n";
                    echo "   Remote: " . date('Y-m-d H:i:s', $remoteModified) . "\n";
                    echo "   Local timestamp: $localModified\n";
                    echo "   Remote timestamp: $remoteModified\n";
                    echo "   Difference (local - remote): $timeDifference seconds\n";
                    echo "   Tolerance buffer: $toleranceSeconds seconds\n";
                    
                    // Check if content actually changed by comparing hashes
                    $localContent = $this->fileOps->extractContentFromFile($this->baseFolder . '/' . $modifiedFile['path']);
                    $localHash = md5($localContent);
                    $remoteHash = md5($remoteDoc['text'] ?? '');
                    
                    echo "   🔍 Content comparison:\n";
                    echo "      Local content hash: $localHash\n";
                    echo "      Remote content hash: $remoteHash\n";
                    echo "      Content changed: " . ($localHash !== $remoteHash ? 'Yes' : 'No') . "\n";
                    
                    // Only skip if remote is significantly newer AND content hasn't changed
                    if ($remoteModified > ($localModified + $toleranceSeconds) && $localHash === $remoteHash) {
                        echo "⚠️  Skipping local update for {$modifiedFile['path']} - remote document is newer and content is identical\n";
                        continue;
                    } else {
                        echo "✅ Proceeding with update (local is newer, within tolerance, or content changed)\n";
                    }
                } else {
                    echo "⚠️  Remote document not found, proceeding with update anyway\n";
                }
                
                if ($this->updateRemoteDocument($modifiedFile)) {
                    $successful['updated']++;
                }
            } else {
                echo "⚠️  Skipping update for file with invalid outline ID: {$modifiedFile['path']}\n";
            }
        }
        
        // Handle moved files
        foreach ($localChanges['moved_files'] as $movedFile) {
            if ($this->updateRemoteDocumentParent($movedFile, $hierarchy)) {
                $successful['moved']++;
            }
        }
        
        // Delete remote documents for deleted local files
        foreach ($localChanges['deleted_files'] as $deletedFile) {
            if ($deletedFile['has_outline_id']) {
                if ($this->deleteRemoteDocument($deletedFile)) {
                    $successful['deleted']++;
                }
            }
        }
        
        // Print summary of what was actually synced
        echo "\n📊 Local to Remote Sync Summary:\n";
        echo "- Documents created: {$successful['created']}\n";
        echo "- Documents updated: {$successful['updated']}\n";
        echo "- Documents moved: {$successful['moved']}\n";
        echo "- Documents deleted: {$successful['deleted']}\n\n";
    }
    
    /**
     * Create new document in Outline from local file
     */
    private function createRemoteDocument($localFile, $hierarchy) {
        $title = $this->fileOps->extractTitleFromPath($this->baseFolder . '/' . $localFile['path']);
        $content = $this->fileOps->extractContentFromFile($this->baseFolder . '/' . $localFile['path']);
        
        // Determine parent ID based on file location and existing .outline metadata
        $parentId = $this->determineParentFromFileLocation($this->baseFolder . '/' . $localFile['path']);
        
        if ($parentId) {
            echo "📝 Creating remote document: $title (parent: $parentId)\n";
        } else {
            echo "📝 Creating remote document: $title (root level)\n";
        }
        
        try {
            $newDoc = $this->remoteSync->createDocument($title, $content, $parentId);
            
            echo "  ✅ Created in Outline with ID: {$newDoc['id']}\n";
            
            // Update local file with new Outline ID
            $this->fileOps->updateMarkdownFile($this->baseFolder . '/' . $localFile['path'], $newDoc);
            
            echo "  📝 Added outline ID to local file frontmatter\n";
            return true;
            
        } catch (Exception $e) {
            echo "  ❌ Failed to create remote document: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Update remote document from local file changes
     */
    private function updateRemoteDocument($localFile) {
        $fullPath = $this->baseFolder . '/' . $localFile['path'];
        $title = $this->fileOps->extractTitleFromPath($fullPath);
        $content = $this->fileOps->extractContentFromFile($fullPath);
        
        // Convert short ID to long ID for the API call
        $longId = $this->convertToLongId($localFile['outline_id']);
        
        echo "✏️  Updating remote document: {$localFile['outline_id']}\n";
        if ($longId !== $localFile['outline_id']) {
            echo "  🔄 Using converted long ID: $longId\n";
        }
        echo "  📁 File: {$localFile['path']}\n";
        echo "  📝 Title: $title\n";
        echo "  📄 Content length: " . strlen($content) . " characters\n";
        echo "  🔍 File exists: " . (file_exists($fullPath) ? 'Yes' : 'No') . "\n";
        
        try {
            $this->remoteSync->updateDocument($longId, $title, $content);
            return true;
        } catch (Exception $e) {
            echo "  ❌ Failed to update remote document: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Update remote document parent relationship from local file move
     */
    private function updateRemoteDocumentParent($movedFile, $hierarchy) {
        $newParentId = $this->fileOps->determineParentFromPath($this->baseFolder . '/' . $movedFile['file_data']['path'], $hierarchy);
        
        // Extract new title from the new file path
        $fullPath = $this->baseFolder . '/' . $movedFile['file_data']['path'];
        $newTitle = $this->fileOps->extractTitleFromPath($fullPath);
        
        // Convert short ID to long ID for the API call
        $longId = $this->convertToLongId($movedFile['outline_id']);
        
        echo "🚚 Updating moved document: {$movedFile['outline_id']}\n";
        echo "  📝 Old path: {$movedFile['from_path']}\n";
        echo "  📝 New path: {$movedFile['file_data']['path']}\n";
        echo "  📝 New title: $newTitle\n";
        if ($longId !== $movedFile['outline_id']) {
            echo "  🔄 Using converted long ID: $longId\n";
        }
        
        try {
            // Update both title and parent - title is required when file is renamed
            $this->remoteSync->updateDocument($longId, $newTitle, null, $newParentId);
            return true;
        } catch (Exception $e) {
            echo "  ❌ Failed to update moved document: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Delete remote document
     */
    private function deleteRemoteDocument($deletedFile) {
        echo "🗑️  Deleting remote document: {$deletedFile['outline_id']}\n";
        
        try {
            $this->remoteSync->deleteDocument($deletedFile['outline_id']);
            return true;
        } catch (Exception $e) {
            echo "  ❌ Failed to delete remote document: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Convert short ID to long ID using .outline metadata
     */
    private function convertToLongId($inputId) {
        $metadata = $this->loadMetadata();
        
        if (!isset($metadata['document_mapping'])) {
            return $inputId; // Return as-is if no mapping available
        }
        
        foreach ($metadata['document_mapping'] as $mapping) {
            // If input matches short_id, return the long id
            if (isset($mapping['short_id']) && $mapping['short_id'] === $inputId) {
                echo "  🔄 Converted short ID '$inputId' to long ID '{$mapping['id']}'\n";
                return $mapping['id'];
            }
            // If input matches long id, return as-is
            if (isset($mapping['id']) && $mapping['id'] === $inputId) {
                return $inputId;
            }
        }
        
        return $inputId; // Return as-is if not found in mapping
    }
    
    /**
     * Find remote document by ID (using .outline metadata for ID conversion)
     */
    private function findRemoteDocumentById($outlineId) {
        echo "  🔍 Searching for remote document with ID: $outlineId\n";
        
        // Convert short ID to long ID using .outline metadata
        $longId = $this->convertToLongId($outlineId);
        if ($longId !== $outlineId) {
            echo "  📝 Using converted long ID: $longId\n";
        }
        
        $remoteDocuments = $this->remoteSync->fetchAllDocuments();
        echo "  📄 Total remote documents fetched: " . count($remoteDocuments) . "\n";
        
        foreach ($remoteDocuments as $doc) {
            if ($doc['id'] === $longId) {
                echo "  ✅ Found remote document: {$doc['title']}\n";
                return $doc;
            }
        }
        
        echo "  ❌ Remote document with ID $longId not found\n";
        return null;
    }
    
    /**
     * Determine parent document ID from file location using .outline metadata
     */
    private function determineParentFromFileLocation($filePath) {
        $relativePath = str_replace($this->baseFolder . '/', '', $filePath);
        $pathParts = explode('/', $relativePath);
        
        // Remove the filename to get just the folder path
        array_pop($pathParts);
        
        // If file is in root, no parent
        if (empty($pathParts)) {
            return null;
        }
        
        // Look for README.md in the parent folder to get the parent document ID
        $parentFolderPath = implode('/', $pathParts) . '/README.md';
        $parentFullPath = $this->baseFolder . '/' . $parentFolderPath;
        
        if (file_exists($parentFullPath)) {
            // Extract outline ID from the README.md file
            $content = file_get_contents($parentFullPath);
            if (preg_match('/id_outline:\s*"?([^"\n\r]+)"?/i', $content, $matches)) {
                $parentId = trim($matches[1]);
                echo "  🔍 Found parent ID from README.md: $parentId\n";
                return $parentId;
            }
        }
        
        // Fallback: use .outline metadata to find parent by folder structure
        $metadata = $this->loadMetadata();
        if (isset($metadata['document_mapping'])) {
            foreach ($metadata['document_mapping'] as $mapping) {
                // Check if this mapping represents the parent folder
                if (isset($mapping['local_path']) && $mapping['local_path'] === $parentFolderPath) {
                    echo "  🔍 Found parent ID from metadata: {$mapping['id']}\n";
                    return $mapping['id'];
                }
            }
        }
        
        echo "  ⚠️  Could not determine parent ID for path: $relativePath\n";
        return null;
    }
    
    /**
     * Fix all local files to use long IDs instead of short IDs
     */
    public function fixAllLocalFileIds() {
        echo "🔧 Fixing all local files to use long IDs...\n";
        
        $metadata = $this->loadMetadata();
        if (!isset($metadata['document_mapping'])) {
            echo "  ⚠️  No document mapping found in .outline file\n";
            return;
        }
        
        $fixes = 0;
        
        // Build conversion map from short ID to long ID
        $idMap = [];
        foreach ($metadata['document_mapping'] as $mapping) {
            if (isset($mapping['short_id']) && isset($mapping['id'])) {
                $idMap[$mapping['short_id']] = $mapping['id'];
            }
        }
        
        // Scan all markdown files
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->baseFolder));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $filePath = $file->getPathname();
                $content = file_get_contents($filePath);
                $originalContent = $content;
                
                // Check for short IDs in the content
                foreach ($idMap as $shortId => $longId) {
                    if (preg_match('/id_outline:\s*"?' . preg_quote($shortId, '/') . '"?/', $content)) {
                        $content = preg_replace('/id_outline:\s*"?' . preg_quote($shortId, '/') . '"?/', "id_outline: $longId", $content);
                        echo "  📝 Fixed {$file->getFilename()}: $shortId → $longId\n";
                        $fixes++;
                    }
                }
                
                // Save if changed
                if ($content !== $originalContent) {
                    file_put_contents($filePath, $content);
                }
            }
        }
        
        echo "✅ Fixed $fixes files with long IDs\n";
    }
    
    /**
     * Load metadata from .outline file
     */
    private function loadMetadata() {
        $metadataPath = $this->baseFolder . '/.outline';
        
        if (!file_exists($metadataPath)) {
            return [];
        }
        
        $content = file_get_contents($metadataPath);
        return json_decode($content, true) ?: [];
    }
}
