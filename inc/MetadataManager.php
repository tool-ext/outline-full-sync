<?php
/*
================================================================================
METADATA MANAGER - Handle sync metadata and .outline file management
================================================================================
This class manages the .outline file that stores sync metadata, document mappings,
and sync timestamps for efficient change detection.

Responsibilities:
1. Load and parse .outline metadata file
2. Update sync metadata with current state
3. Manage document mappings (local path ↔ remote ID)
4. Track last sync timestamps
5. Build and maintain hierarchy information
================================================================================
*/

class MetadataManager {
    private $fileScanner;
    private $baseFolder;
    private $collectionId;
    private $remoteSync;
    
    public function __construct($fileScanner, $baseFolder, $collectionId) {
        $this->fileScanner = $fileScanner;
        $this->baseFolder = $baseFolder;
        $this->collectionId = $collectionId;
    }
    
    /**
     * Set RemoteSync dependency for path generation
     */
    public function setRemoteSync($remoteSync) {
        $this->remoteSync = $remoteSync;
    }
    
    /**
     * Update metadata with current sync information
     */
    public function updateMetadata($localScan, $remoteDocuments, $hierarchy) {
        echo "💾 Updating sync metadata...\n";
        
        // Build document mapping
        $documentMapping = [];
        foreach ($remoteDocuments as $doc) {
            $localPath = $this->remoteSync ? $this->remoteSync->generateLocalPath($doc, $hierarchy) : $this->generateLocalPath($doc, $hierarchy);
            $documentMapping[] = [
                'id' => $doc['id'],
                'short_id' => $doc['urlId'] ?? null,
                'title' => $doc['title'],
                'parent_id' => $doc['parentDocumentId'],
                'updated_at' => $doc['updatedAt'],
                'local_path' => $localPath,
                'is_folder' => (basename($localPath) === 'README.md')
            ];
        }
        
        $metadata = [
            'last_sync' => date('c'),
            'collection_id' => $this->collectionId,
            'document_mapping' => $documentMapping
        ];
        
        $this->fileScanner->saveMetadata($localScan, $metadata);
    }
    
    /**
     * Get last sync time from metadata
     */
    public function getLastSyncTime() {
        $metadataPath = $this->baseFolder . '/.outline';
        
        if (!file_exists($metadataPath)) {
            return '1970-01-01T00:00:00Z'; // Unix epoch
        }
        
        $metadata = json_decode(file_get_contents($metadataPath), true);
        return $metadata['last_sync'] ?? '1970-01-01T00:00:00Z';
    }
    
    /**
     * Load metadata from .outline file
     */
    public function loadMetadata() {
        $metadataPath = $this->baseFolder . '/.outline';
        
        if (!file_exists($metadataPath)) {
            return [];
        }
        
        $content = file_get_contents($metadataPath);
        return json_decode($content, true) ?: [];
    }
    
    /**
     * Generate local path for a document based on hierarchy
     * This is a simplified version - the full logic would come from RemoteSync
     */
    private function generateLocalPath($document, $hierarchy) {
        // This is a placeholder - in practice this would delegate to RemoteSync
        // or we'd need to refactor RemoteSync to make this method accessible
        $title = $document['title'];
        $filename = $this->sanitizeFilename($title) . '.md';
        
        // If this document has children, it should be a README.md in a folder
        if (isset($hierarchy[$document['id']]) && $hierarchy[$document['id']]['is_parent']) {
            $folderName = $this->sanitizeFilename($title);
            return $folderName . '/README.md';
        }
        
        return $filename;
    }
    
    /**
     * Sanitize filename for filesystem compatibility
     */
    private function sanitizeFilename($filename) {
        // Remove/replace invalid characters
        $filename = preg_replace('/[\/\\\:*?"<>|]/', '-', $filename);
        $filename = preg_replace('/\s+/', '-', $filename);
        $filename = trim($filename, '-');
        
        return $filename ?: 'untitled';
    }
}
