<?php
/*
================================================================================
SYNC ORCHESTRATOR - Main coordinator for bidirectional sync operations
================================================================================
This class orchestrates the entire sync process by coordinating specialized
components for each phase of the synchronization workflow.

Responsibilities:
1. Phase coordination (scan, detect, sync, update)
2. Component initialization and dependency injection
3. High-level error handling and logging
4. Configuration management
================================================================================
*/

// Include all base dependencies
require_once __DIR__ . '/FileSystemScanner.php';
require_once __DIR__ . '/RemoteSync.php';
require_once __DIR__ . '/FileOperations.php';

// Include specialized sync components
require_once __DIR__ . '/CollectionSelector.php';
require_once __DIR__ . '/ConflictDetector.php';
require_once __DIR__ . '/LocalToRemoteSync.php';
require_once __DIR__ . '/RemoteToLocalSync.php';
require_once __DIR__ . '/ParentConversionHandler.php';
require_once __DIR__ . '/MetadataManager.php';

class SyncOrchestrator {
    private $config;
    private $fileScanner;
    private $remoteSync;
    private $fileOps;
    private $baseFolder;
    private $collectionId;
    
    // Specialized components
    private $conflictDetector;
    private $localToRemoteSync;
    private $remoteToLocalSync;
    private $parentConversionHandler;
    private $metadataManager;
    
    public function __construct($collectionId = null, $baseFolder = null) {
        if ($collectionId && $baseFolder) {
            // Use provided parameters (multi-collection mode)
            $this->collectionId = $collectionId;
            $this->baseFolder = $baseFolder;
        } else {
            // Use global config (legacy mode)
            global $syncConfig;
            $this->baseFolder = $syncConfig['base_folder'];
            $this->collectionId = $syncConfig['collection_id'];
        }
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
            $conflicts = $this->conflictDetector->detectConflicts($localChanges, $remoteChanges);
            
            if (!empty($conflicts)) {
                $this->conflictDetector->handleConflicts($conflicts);
                return; // Stop sync if conflicts need manual resolution
            }
            
            // Phase 4: Execute sync operations
            echo "\n═══ PHASE 4: EXECUTING SYNC OPERATIONS ═══\n";
            $this->localToRemoteSync->execute($localChanges, $hierarchy);
            $this->remoteToLocalSync->execute($remoteChanges, $hierarchy);
            
            // Phase 5: Update metadata
            echo "\n═══ PHASE 5: UPDATING METADATA ═══\n";
            $this->metadataManager->updateMetadata($localScan, $remoteDocuments, $hierarchy);
            
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
        $changes = $this->fileScanner->detectChanges($currentScan);
        $this->fileScanner->printChangesSummary($changes);
        return $changes;
    }
    
    /**
     * Detect remote document changes
     */
    private function detectRemoteChanges($remoteDocuments) {
        $lastSyncTime = $this->metadataManager->getLastSyncTime();
        $changes = $this->remoteSync->detectRemoteChanges($remoteDocuments, $lastSyncTime);
        $this->remoteSync->printRemoteChangesSummary($changes);
        return $changes;
    }
    
    /**
     * Load configuration
     */
    private function loadConfig() {
        // Use the same config as other scripts
        global $baseUrl, $headers;
        
        $this->config = [
            'base_url' => $baseUrl,
            'headers' => $headers,
            'collection_id' => $this->collectionId
        ];
    }
    
    /**
     * Initialize components
     */
    private function initializeComponents() {
        // Core components
        $this->fileScanner = new FileSystemScanner($this->baseFolder);
        $this->remoteSync = new RemoteSync(
            $this->config['base_url'], 
            $this->config['headers'], 
            $this->config['collection_id'],
            $this->baseFolder
        );
        $this->fileOps = new FileOperations($this->baseFolder);
        
        // Specialized sync components
        $this->conflictDetector = new ConflictDetector();
        $this->localToRemoteSync = new LocalToRemoteSync($this->remoteSync, $this->fileOps, $this->baseFolder);
        $this->parentConversionHandler = new ParentConversionHandler($this->remoteSync, $this->fileOps, $this->fileScanner, $this->baseFolder);
        $this->remoteToLocalSync = new RemoteToLocalSync($this->remoteSync, $this->fileOps, $this->fileScanner, $this->baseFolder);
        $this->metadataManager = new MetadataManager($this->fileScanner, $this->baseFolder, $this->config['collection_id']);
        
        // Set dependencies after creation to avoid circular references
        $this->remoteToLocalSync->setParentConversionHandler($this->parentConversionHandler);
        $this->metadataManager->setRemoteSync($this->remoteSync);
    }
}
