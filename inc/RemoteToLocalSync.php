<?php
/*
================================================================================
REMOTE TO LOCAL SYNC - Handle syncing remote changes to local files
================================================================================
This class manages the process of pulling remote document changes from Outline
and applying them to local files, including creating, updating, moving, and deleting files.

Responsibilities:
1. Create new local files from new remote documents
2. Update local files from modified remote documents
3. Handle document moves by moving local files
4. Delete local files for deleted remote documents
5. Timestamp comparison to prevent overwriting newer local content
6. Coordinate with ParentConversionHandler for folder structure changes
================================================================================
*/

class RemoteToLocalSync {
    private $remoteSync;
    private $fileOps;
    private $fileScanner;
    private $baseFolder;
    private $parentConversionHandler;
    
    public function __construct($remoteSync, $fileOps, $fileScanner, $baseFolder) {
        $this->remoteSync = $remoteSync;
        $this->fileOps = $fileOps;
        $this->fileScanner = $fileScanner;
        $this->baseFolder = $baseFolder;
    }
    
    /**
     * Set the parent conversion handler (injected after construction to avoid circular dependency)
     */
    public function setParentConversionHandler($parentConversionHandler) {
        $this->parentConversionHandler = $parentConversionHandler;
    }
    
    /**
     * Execute remote to local sync operations
     */
    public function execute($remoteChanges, $hierarchy) {
        echo "📥 Syncing remote changes to local...\n";
        
        // First, handle documents that became parents (need folder conversion)
        if ($this->parentConversionHandler) {
            $this->parentConversionHandler->handleParentConversions($hierarchy);
        }
        
        // Create local files from new remote documents
        foreach ($remoteChanges['new_documents'] as $newDoc) {
            $this->createLocalFile($newDoc, $hierarchy);
        }
        
        // Update local files from modified remote documents
        foreach ($remoteChanges['updated_documents'] as $updatedDoc) {
            // Check if local file is newer before overwriting
            $localFile = $this->findLocalFileByDocument($updatedDoc);
            if ($localFile) {
                $localModified = $localFile['modified_time'];
                $remoteModified = strtotime($updatedDoc['updatedAt']);
                
                echo "🔍 Comparing timestamps for {$updatedDoc['title']}:\n";
                echo "   Local: " . date('Y-m-d H:i:s', $localModified) . "\n";
                echo "   Remote: " . date('Y-m-d H:i:s', $remoteModified) . "\n";
                
                if ($localModified > $remoteModified) {
                    echo "⚠️  Skipping remote update for {$updatedDoc['title']} - local file is newer\n";
                    continue;
                } else {
                    echo "✅ Remote document is newer, proceeding with update\n";
                }
            }
            
            $this->updateLocalFile($updatedDoc, $hierarchy);
        }
        
        // Delete local files for deleted remote documents
        foreach ($remoteChanges['deleted_documents'] as $deletedDoc) {
            $this->deleteLocalFile($deletedDoc);
        }
    }
    
    /**
     * Create local file from remote document
     */
    private function createLocalFile($document, $hierarchy) {
        $localPath = $this->remoteSync->generateLocalPath($document, $hierarchy);
        
        if ($localPath) {
            echo "📄 Creating local file: $localPath\n";
            
            try {
                $this->fileOps->createMarkdownFile($document, $localPath);
            } catch (Exception $e) {
                echo "  ❌ Failed to create local file: " . $e->getMessage() . "\n";
            }
        }
    }
    
    /**
     * Update local file from remote document
     */
    private function updateLocalFile($document, $hierarchy) {
        // Find existing local file by document (tries both long and short IDs)
        $localFile = $this->findLocalFileByDocument($document);
        
        if ($localFile) {
            $newLocalPath = $this->remoteSync->generateLocalPath($document, $hierarchy);
            $currentPath = $localFile['path'];
            
            if ($newLocalPath !== $currentPath) {
                // File needs to be moved
                echo "🚚 Moving local file: $currentPath → $newLocalPath\n";
                try {
                    $this->fileOps->moveFile($this->baseFolder . '/' . $localFile['path'], $newLocalPath);
                    $this->fileOps->updateMarkdownFile($this->baseFolder . '/' . $newLocalPath, $document, true);
                } catch (Exception $e) {
                    echo "  ❌ Failed to move local file: " . $e->getMessage() . "\n";
                }
            } else {
                // Just update content
                echo "📝 Updating local file: $currentPath\n";
                try {
                    $this->fileOps->updateMarkdownFile($this->baseFolder . '/' . $localFile['path'], $document, true);
                } catch (Exception $e) {
                    echo "  ❌ Failed to update local file: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    /**
     * Delete local file
     */
    private function deleteLocalFile($documentMapping) {
        if (isset($documentMapping['local_path'])) {
            echo "🗑️  Deleting local file: {$documentMapping['local_path']}\n";
            
            try {
                $this->fileOps->deleteFile($documentMapping['local_path']);
            } catch (Exception $e) {
                echo "  ❌ Failed to delete local file: " . $e->getMessage() . "\n";
            }
        }
    }
    
    /**
     * Find local file by document (tries both long and short IDs)
     */
    private function findLocalFileByDocument($document) {
        $scan = $this->fileScanner->scanFileSystem();
        
        foreach ($scan as $file) {
            // First try to match with the long ID
            if ($file['outline_id'] === $document['id']) {
                return $file;
            }
            // Then try to match with the short ID
            if (isset($document['urlId']) && $file['outline_id'] === $document['urlId']) {
                return $file;
            }
        }
        
        return null;
    }
}
