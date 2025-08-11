<?php
/*
================================================================================
UNIFIED FULL SYNC - Complete bidirectional sync between Outline and local files
================================================================================
This is the main controller that orchestrates:
1. Local filesystem scanning and change detection
2. Remote Outline API synchronization  
3. Conflict detection and resolution
4. Bidirectional sync operations
5. Metadata management and preservation

Usage: php fullsync.php
================================================================================
*/

// Include required components
if (!function_exists('sendHttpRequest')) {
    require_once __DIR__ . '/inc/init.php';
}
require_once __DIR__ . '/fullsync/FileSystemScanner.php';
require_once __DIR__ . '/fullsync/RemoteSync.php';
require_once __DIR__ . '/fullsync/FileOperations.php';

class FullSync {
    private $config;
    private $fileScanner;
    private $remoteSync;
    private $fileOps;
    private $baseFolder;
    
    public function __construct() {
        global $syncConfig;
        $this->baseFolder = $syncConfig['base_folder'];
        $this->loadConfig();
        $this->initializeComponents();
    }
    
    /**
     * Main sync execution method
     */
    public function execute() {
        echo "🔄 Starting Unified Full Sync...\n";
        echo "📁 Local folder: {$this->baseFolder}\n";
        echo "🌐 Remote collection: {$this->config['collection_id']}\n\n";
        
        try {
            // Phase 1: Scan current state
            echo "═══ PHASE 1: SCANNING CURRENT STATE ═══\n";
            $localScan = $this->scanLocalFiles();
            $remoteDocuments = $this->fetchRemoteDocuments();
            $hierarchy = $this->remoteSync->buildDocumentHierarchy($remoteDocuments);
            
            // Phase 2: Detect changes
            echo "\n═══ PHASE 2: DETECTING CHANGES ═══\n";
            $localChanges = $this->detectLocalChanges($localScan);
            $remoteChanges = $this->detectRemoteChanges($remoteDocuments);
            
            // Phase 3: Check for conflicts
            echo "\n═══ PHASE 3: CONFLICT DETECTION ═══\n";
            $conflicts = $this->detectConflicts($localChanges, $remoteChanges);
            
            if (!empty($conflicts)) {
                $this->handleConflicts($conflicts);
                return; // Stop sync if conflicts need manual resolution
            }
            
            // Phase 4: Execute sync operations
            echo "\n═══ PHASE 4: EXECUTING SYNC OPERATIONS ═══\n";
            $this->executeLocalToRemoteSync($localChanges, $hierarchy);
            $this->executeRemoteToLocalSync($remoteChanges, $hierarchy);
            
            // Phase 5: Update metadata
            echo "\n═══ PHASE 5: UPDATING METADATA ═══\n";
            $this->updateMetadata($localScan, $remoteDocuments, $hierarchy);
            
            echo "\n✅ Full sync completed successfully!\n";
            
        } catch (Exception $e) {
            echo "\n❌ Sync failed: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
    }
    
    /**
     * Scan local filesystem for changes
     */
    private function scanLocalFiles() {
        echo "📂 Scanning local filesystem...\n";
        $currentScan = $this->fileScanner->scanFileSystem();
        echo "📄 Found " . count($currentScan) . " local files\n";
        return $currentScan;
    }
    
    /**
     * Fetch documents from Outline
     */
    private function fetchRemoteDocuments() {
        $documents = $this->remoteSync->fetchAllDocuments();
        return $documents;
    }
    
    /**
     * Detect local filesystem changes
     */
    private function detectLocalChanges($currentScan) {
        echo "🔍 Detecting local changes...\n";
        $changes = $this->fileScanner->detectChanges($currentScan);
        $this->fileScanner->printChangesSummary($changes);
        return $changes;
    }
    
    /**
     * Detect remote document changes
     */
    private function detectRemoteChanges($remoteDocuments) {
        echo "🔍 Detecting remote changes...\n";
        $lastSyncTime = $this->getLastSyncTime();
        $changes = $this->remoteSync->detectRemoteChanges($remoteDocuments, $lastSyncTime);
        $this->remoteSync->printRemoteChangesSummary($changes);
        return $changes;
    }
    
    /**
     * Detect conflicts between local and remote changes
     */
    private function detectConflicts($localChanges, $remoteChanges) {
        echo "⚠️  Checking for conflicts...\n";
        $conflicts = [];
        
        // Check for files modified both locally and remotely
        foreach ($localChanges['conflicts'] as $localConflict) {
            $outlineId = $localConflict['file_data']['outline_id'];
            
            // Check if this file was also updated remotely
            foreach ($remoteChanges['updated_documents'] as $remoteDoc) {
                if ($remoteDoc['id'] === $outlineId) {
                    $conflicts[] = [
                        'type' => 'bidirectional_edit',
                        'path' => $localConflict['path'],
                        'outline_id' => $outlineId,
                        'local_modified' => $localConflict['local_modified'],
                        'remote_modified' => strtotime($remoteDoc['updatedAt']),
                        'local_data' => $localConflict['file_data'],
                        'remote_data' => $remoteDoc
                    ];
                }
            }
        }
        
        if (empty($conflicts)) {
            echo "✅ No conflicts detected\n";
        } else {
            echo "⚠️  Found " . count($conflicts) . " conflicts\n";
        }
        
        return $conflicts;
    }
    
    /**
     * Handle conflicts by displaying them for manual resolution
     */
    private function handleConflicts($conflicts) {
        echo "\n🚨 CONFLICTS DETECTED - Manual Resolution Required\n";
        echo "══════════════════════════════════════════════════\n\n";
        
        foreach ($conflicts as $index => $conflict) {
            echo "Conflict #" . ($index + 1) . ":\n";
            echo "  File: {$conflict['path']}\n";
            echo "  Local modified: " . date('Y-m-d H:i:s', $conflict['local_modified']) . "\n";
            echo "  Remote modified: " . date('Y-m-d H:i:s', $conflict['remote_modified']) . "\n";
            echo "  Outline ID: {$conflict['outline_id']}\n\n";
        }
        
        echo "Please resolve these conflicts manually and run the sync again.\n";
        echo "You can:\n";
        echo "1. Edit the local file to match your preferred version\n";
        echo "2. Edit the remote document in Outline\n";
        echo "3. Or wait for one side to be updated before syncing\n\n";
    }
    
    /**
     * Execute local to remote sync operations
     */
    private function executeLocalToRemoteSync($localChanges, $hierarchy) {
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
     * Execute remote to local sync operations
     */
    private function executeRemoteToLocalSync($remoteChanges, $hierarchy) {
        echo "📥 Syncing remote changes to local...\n";
        
        // First, handle documents that became parents (need folder conversion)
        $this->handleParentConversions($hierarchy);
        
        // Create local files from new remote documents
        foreach ($remoteChanges['new_documents'] as $newDoc) {
            $this->createLocalFile($newDoc, $hierarchy);
        }
        
        // Update local files from modified remote documents
        foreach ($remoteChanges['updated_documents'] as $updatedDoc) {
            $this->updateLocalFile($updatedDoc, $hierarchy);
        }
        
        // Delete local files for deleted remote documents
        foreach ($remoteChanges['deleted_documents'] as $deletedDoc) {
            $this->deleteLocalFile($deletedDoc);
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
        // Find existing local file by Outline ID
        $localFile = $this->findLocalFileByOutlineId($document['id']);
        
        if ($localFile) {
            $newLocalPath = $this->remoteSync->generateLocalPath($document, $hierarchy);
            $currentPath = $localFile['path'];
            
            if ($newLocalPath !== $currentPath) {
                // File needs to be moved
                echo "🚚 Moving local file: $currentPath → $newLocalPath\n";
                try {
                    $this->fileOps->moveFile($localFile['full_path'], $newLocalPath);
                    $this->fileOps->updateMarkdownFile($this->baseFolder . '/' . $newLocalPath, $document);
                } catch (Exception $e) {
                    echo "  ❌ Failed to move local file: " . $e->getMessage() . "\n";
                }
            } else {
                // Just update content
                echo "📝 Updating local file: $currentPath\n";
                try {
                    $this->fileOps->updateMarkdownFile($localFile['full_path'], $document);
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
     * Update metadata with current sync information
     */
    private function updateMetadata($localScan, $remoteDocuments, $hierarchy) {
        echo "💾 Updating sync metadata...\n";
        
        // Build document mapping
        $documentMapping = [];
        foreach ($remoteDocuments as $doc) {
            $localPath = $this->remoteSync->generateLocalPath($doc, $hierarchy);
            $documentMapping[] = [
                'id' => $doc['id'],
                'title' => $doc['title'],
                'parent_id' => $doc['parentDocumentId'],
                'updated_at' => $doc['updatedAt'],
                'local_path' => $localPath,
                'is_folder' => (basename($localPath) === 'README.md')
            ];
        }
        
        $metadata = [
            'last_sync' => date('c'),
            'collection_id' => $this->config['collection_id'],
            'document_mapping' => $documentMapping
        ];
        
        $this->fileScanner->saveMetadata($localScan, $metadata);
    }
    
    /**
     * Load configuration
     */
    private function loadConfig() {
        // Use the same config as other scripts
        global $baseUrl, $headers, $syncConfig;
        
        $this->config = [
            'base_url' => $baseUrl,
            'headers' => $headers,
            'collection_id' => $syncConfig['collection_id']
        ];
    }
    
    /**
     * Initialize components
     */
    private function initializeComponents() {
        $this->fileScanner = new FileSystemScanner($this->baseFolder);
        $this->remoteSync = new RemoteSync(
            $this->config['base_url'], 
            $this->config['headers'], 
            $this->config['collection_id'],
            $this->baseFolder
        );
        $this->fileOps = new FileOperations($this->baseFolder);
    }
    
    /**
     * Get last sync time from metadata
     */
    private function getLastSyncTime() {
        $metadataPath = $this->baseFolder . '/.outline';
        
        if (!file_exists($metadataPath)) {
            return '1970-01-01T00:00:00Z'; // Unix epoch
        }
        
        $metadata = json_decode(file_get_contents($metadataPath), true);
        return $metadata['last_sync'] ?? '1970-01-01T00:00:00Z';
    }
    
    /**
     * Find local file by Outline ID
     */
    private function findLocalFileByOutlineId($outlineId) {
        $scan = $this->fileScanner->scanFileSystem();
        
        foreach ($scan as $file) {
            if ($file['outline_id'] === $outlineId) {
                return $file;
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
    
    /**
     * Handle documents that became parents - convert from file to folder/README.md
     * Also handle documents that are no longer parents - convert back to standalone files
     */
    private function handleParentConversions($hierarchy) {
        echo "🏗️  Checking for parent conversions...\n";
        
        $metadata = $this->loadMetadata();
        $currentScan = $this->fileScanner->scanFileSystem();
        
        // Handle documents that became parents (file → folder/README.md)
        foreach ($hierarchy as $docId => $docInfo) {
            // Skip if this document doesn't have children
            if (!$docInfo['is_parent'] || empty($docInfo['children'])) {
                continue;
            }
            
            // Check if we have this document locally as a standalone file
            $localFile = null;
            foreach ($currentScan as $file) {
                if ($file['outline_id'] === $docId && !$file['is_readme']) {
                    $localFile = $file;
                    break;
                }
            }
            
            if ($localFile) {
                $this->convertToParentFolder($localFile, $docInfo['document'], $hierarchy);
            }
        }
        
        // Handle documents that are no longer parents (folder/README.md → file)
        foreach ($currentScan as $file) {
            // Only check README.md files (potential ex-parents)
            if (!$file['is_readme'] || !$file['has_outline_id']) {
                continue;
            }
            
            $docId = $file['outline_id'];
            
            // Check if this document still has children in the hierarchy
            $stillHasChildren = false;
            if (isset($hierarchy[$docId])) {
                $stillHasChildren = $hierarchy[$docId]['is_parent'] && !empty($hierarchy[$docId]['children']);
            }
            
            if (!$stillHasChildren) {
                // This document no longer has children - convert back to standalone file
                $this->convertToStandaloneFile($file, $hierarchy[$docId]['document'] ?? null);
            }
        }
    }
    
    /**
     * Convert a standalone document file to a parent folder with README.md
     */
    private function convertToParentFolder($localFile, $document, $hierarchy) {
        $oldFilePath = $localFile['full_path'];
        $title = $this->fileOps->extractTitleFromPath($oldFilePath);
        
        echo "🔄 Converting to parent folder: $title\n";
        echo "   From: {$localFile['path']}\n";
        
        // Generate the new folder structure path
        $newLocalPath = $this->remoteSync->generateLocalPath($document, $hierarchy);
        $newFullPath = $this->baseFolder . '/' . $newLocalPath;
        
        echo "   To: $newLocalPath\n";
        
        try {
            // Create the folder if it doesn't exist
            $folderPath = dirname($newFullPath);
            if (!file_exists($folderPath)) {
                mkdir($folderPath, 0777, true);
                echo "   📁 Created folder: " . str_replace($this->baseFolder . '/', '', $folderPath) . "\n";
            }
            
            // Move the file to README.md in the new folder
            if (rename($oldFilePath, $newFullPath)) {
                echo "   ✅ Converted to parent folder successfully\n";
                
                // Update the content to ensure it has proper frontmatter
                $this->fileOps->updateMarkdownFile($newFullPath, $document);
                
            } else {
                echo "   ❌ Failed to convert to parent folder\n";
            }
            
        } catch (Exception $e) {
            echo "   ❌ Error converting to parent folder: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Convert a parent folder back to a standalone document file
     * (README.md in folder → standalone .md file)
     */
    private function convertToStandaloneFile($readmeFile, $document) {
        $readmeFilePath = $readmeFile['full_path'];
        $folderPath = dirname($readmeFilePath);
        $folderName = basename($folderPath);
        
        echo "🔄 Converting back to standalone file: $folderName\n";
        echo "   From: {$readmeFile['path']}\n";
        
        // Generate new standalone file path
        $parentDir = dirname($folderPath);
        $newFilePath = $parentDir . '/' . $folderName . '.md';
        $newRelativePath = str_replace($this->baseFolder . '/', '', $newFilePath);
        
        echo "   To: $newRelativePath\n";
        
        try {
            // Check if the folder has any other files (shouldn't, but safety check)
            $folderContents = array_diff(scandir($folderPath), ['.', '..', 'README.md']);
            
            if (!empty($folderContents)) {
                echo "   ⚠️  Folder still contains files - skipping conversion:\n";
                foreach ($folderContents as $item) {
                    echo "      - $item\n";
                }
                return;
            }
            
            // Move README.md to standalone file
            if (rename($readmeFilePath, $newFilePath)) {
                echo "   ✅ Moved README.md to standalone file\n";
                
                // Remove the now-empty folder
                if (rmdir($folderPath)) {
                    echo "   🗂️  Removed empty folder: " . str_replace($this->baseFolder . '/', '', $folderPath) . "\n";
                } else {
                    echo "   ⚠️  Could not remove folder (may not be empty)\n";
                }
                
                // Update the content to ensure it has proper frontmatter
                if ($document) {
                    $this->fileOps->updateMarkdownFile($newFilePath, $document);
                }
                
                echo "   ✅ Converted to standalone file successfully\n";
                
            } else {
                echo "   ❌ Failed to move README.md to standalone file\n";
            }
            
        } catch (Exception $e) {
            echo "   ❌ Error converting to standalone file: " . $e->getMessage() . "\n";
        }
    }
}

// Execute the sync if this file is run directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $sync = new FullSync();
    $sync->execute();
}
