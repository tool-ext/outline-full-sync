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
        
        // Create new documents from new local files
        foreach ($localChanges['new_files'] as $newFile) {
            if (!$newFile['has_outline_id']) {
                $this->createRemoteDocument($newFile, $hierarchy);
            }
        }
        
        // Update remote documents from modified local files
        foreach ($localChanges['modified_files'] as $modifiedFile) {
            if ($modifiedFile['has_outline_id'] && !empty($modifiedFile['outline_id']) && $modifiedFile['outline_id'] !== 'null') {
                // Check if remote document is newer before overwriting
                $remoteDoc = $this->findRemoteDocumentById($modifiedFile['outline_id']);
                if ($remoteDoc) {
                    $localModified = $modifiedFile['modified_time'];
                    $remoteModified = strtotime($remoteDoc['updatedAt']);
                    
                    echo "🔍 Comparing timestamps for {$modifiedFile['path']}:\n";
                    echo "   Local: " . date('Y-m-d H:i:s', $localModified) . "\n";
                    echo "   Remote: " . date('Y-m-d H:i:s', $remoteModified) . "\n";
                    
                    if ($remoteModified > $localModified) {
                        echo "⚠️  Skipping local update for {$modifiedFile['path']} - remote document is newer\n";
                        continue;
                    } else {
                        echo "✅ Local file is newer, proceeding with update\n";
                    }
                }
                
                $this->updateRemoteDocument($modifiedFile);
            } else {
                echo "⚠️  Skipping update for file with invalid outline ID: {$modifiedFile['path']}\n";
            }
        }
        
        // Handle moved files
        foreach ($localChanges['moved_files'] as $movedFile) {
            $this->updateRemoteDocumentParent($movedFile, $hierarchy);
        }
        
        // Delete remote documents for deleted local files
        foreach ($localChanges['deleted_files'] as $deletedFile) {
            if ($deletedFile['has_outline_id']) {
                $this->deleteRemoteDocument($deletedFile);
            }
        }
    }
    
    /**
     * Create new document in Outline from local file
     */
    private function createRemoteDocument($localFile, $hierarchy) {
        $title = $this->fileOps->extractTitleFromPath($localFile['full_path']);
        $content = $this->fileOps->extractContentFromFile($localFile['full_path']);
        
        // Determine parent ID based on file location and existing .outline metadata
        $parentId = $this->determineParentFromFileLocation($localFile['full_path']);
        
        if ($parentId) {
            echo "📝 Creating remote document: $title (parent: $parentId)\n";
        } else {
            echo "📝 Creating remote document: $title (root level)\n";
        }
        
        try {
            $newDoc = $this->remoteSync->createDocument($title, $content, $parentId);
            
            echo "  ✅ Created in Outline with ID: {$newDoc['id']}\n";
            
            // Update local file with new Outline ID
            $this->fileOps->updateMarkdownFile($localFile['full_path'], $newDoc);
            
            echo "  📝 Added outline ID to local file frontmatter\n";
            
        } catch (Exception $e) {
            echo "  ❌ Failed to create remote document: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Update remote document from local file changes
     */
    private function updateRemoteDocument($localFile) {
        $title = $this->fileOps->extractTitleFromPath($localFile['full_path']);
        $content = $this->fileOps->extractContentFromFile($localFile['full_path']);
        
        echo "✏️  Updating remote document: {$localFile['outline_id']}\n";
        
        try {
            $this->remoteSync->updateDocument($localFile['outline_id'], $title, $content);
        } catch (Exception $e) {
            echo "  ❌ Failed to update remote document: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Update remote document parent relationship from local file move
     */
    private function updateRemoteDocumentParent($movedFile, $hierarchy) {
        $newParentId = $this->fileOps->determineParentFromPath($movedFile['file_data']['full_path'], $hierarchy);
        
        echo "🚚 Updating remote document parent: {$movedFile['outline_id']}\n";
        
        try {
            $this->remoteSync->updateDocument($movedFile['outline_id'], null, null, $newParentId);
        } catch (Exception $e) {
            echo "  ❌ Failed to update remote document parent: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Delete remote document
     */
    private function deleteRemoteDocument($deletedFile) {
        echo "🗑️  Deleting remote document: {$deletedFile['outline_id']}\n";
        
        try {
            $this->remoteSync->deleteDocument($deletedFile['outline_id']);
        } catch (Exception $e) {
            echo "  ❌ Failed to delete remote document: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Find remote document by ID
     */
    private function findRemoteDocumentById($outlineId) {
        $remoteDocuments = $this->remoteSync->fetchAllDocuments();
        foreach ($remoteDocuments as $doc) {
            if ($doc['id'] === $outlineId) {
                return $doc;
            }
        }
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
