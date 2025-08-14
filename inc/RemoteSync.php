<?php
/*
================================================================================
REMOTE SYNC - Handle Outline API interactions and remote change detection
================================================================================
This module handles:
1. Fetching documents from Outline API
2. Detecting remote changes since last sync
3. Building document hierarchy and relationships
4. Efficient API calls with filtering
================================================================================
*/

class RemoteSync {
    private $baseUrl;
    private $headers;
    private $collectionId;
    private $baseFolder;
    
    public function __construct($baseUrl, $headers, $collectionId, $baseFolder) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->headers = $headers;
        $this->collectionId = $collectionId;
        $this->baseFolder = rtrim($baseFolder, '/');
    }
    
    /**
     * Fetch all collections from Outline API
     */
    public function fetchAllCollections() {
        $endpointUrl = $this->baseUrl . "/api/collections.list";
        
        if (!function_exists('sendHttpRequest')) {
            throw new Exception('sendHttpRequest function not available. Please include init.php');
        }
        
        // Collections API expects an empty object, not an empty array
        $response = sendHttpRequest('POST', $endpointUrl, (object)[], $this->headers);
        
        // Check response structure
        if (!isset($response['data'])) {
            throw new Exception('Invalid collections API response structure');
        }
        
        // Handle Outline API response format
        if (isset($response['data']['data'])) {
            return $response['data']['data'];
        } elseif (isset($response['data']['ok']) && !$response['data']['ok']) {
            throw new Exception('API Error: ' . ($response['data']['message'] ?? 'Unknown error'));
        } else {
            return $response['data'];
        }
    }
    
    /**
     * Fetch all documents from Outline API
     */
    public function fetchAllDocuments() {
        $endpointUrl = $this->baseUrl . "/api/documents.list";
        
        $data = [
            'limit' => 100,
            'sort' => 'updatedAt',
            'direction' => 'DESC',
            'collectionId' => $this->collectionId
        ];
        
        echo "🌐 Fetching documents from Outline API...\n";
        
        if (!function_exists('sendHttpRequest')) {
            throw new Exception('sendHttpRequest function not available. Please include init.php');
        }
        
        $response = sendHttpRequest('POST', $endpointUrl, $data, $this->headers);
        
        if (!isset($response['data'])) {
            throw new Exception('Invalid API response structure');
        }
        
        // Handle Outline API response format
        $documentsData = isset($response['data']['data']) ? $response['data']['data'] : $response['data'];
        $documents = array_map([$this, 'simplifyDocument'], $documentsData);
        
        echo "📄 Fetched " . count($documents) . " documents from Outline\n";
        
        // Debug: Show document IDs for troubleshooting
        echo "🔍 Document IDs fetched:\n";
        foreach ($documents as $doc) {
            echo "   - {$doc['title']}: {$doc['id']} (urlId: {$doc['urlId']})\n";
        }
        
        return $documents;
    }
    
    /**
     * Detect changes in remote documents since last sync
     */
    public function detectRemoteChanges($allDocuments, $lastSyncTime) {
        $changes = [
            'new_documents' => [],
            'updated_documents' => [],
            'deleted_documents' => []
        ];
        
        echo "🔍 Detecting remote changes...\n";
        echo "   Last sync time: $lastSyncTime\n";
        echo "   Remote documents count: " . count($allDocuments) . "\n";
        
        // Load previous document mapping
        $previousMapping = $this->loadPreviousDocumentMapping();
        $existingDocIds = array_column($previousMapping, 'id');
        $remoteDocIds = array_column($allDocuments, 'id');
        
        echo "   Previous mapping count: " . count($previousMapping) . "\n";
        echo "   Existing doc IDs: " . implode(', ', $existingDocIds) . "\n";
        echo "   Remote doc IDs: " . implode(', ', $remoteDocIds) . "\n";
        
        foreach ($allDocuments as $doc) {
            if (!in_array($doc['id'], $existingDocIds)) {
                // New document
                $changes['new_documents'][] = $doc;
                echo "📄 New document: {$doc['title']}\n";
            } elseif (strtotime($doc['updatedAt']) > strtotime($lastSyncTime)) {
                // Updated document
                $changes['updated_documents'][] = $doc;
                echo "✏️  Updated document: {$doc['title']}\n";
            } else {
                echo "ℹ️  No change for: {$doc['title']}\n";
            }
        }
        
        // Find deleted documents
        foreach ($previousMapping as $mapping) {
            if (!in_array($mapping['id'], $remoteDocIds)) {
                $changes['deleted_documents'][] = $mapping;
                echo "🗑️  Deleted document: {$mapping['title']}\n";
            }
        }
        
        echo "📊 Changes summary:\n";
        echo "   New: " . count($changes['new_documents']) . "\n";
        echo "   Updated: " . count($changes['updated_documents']) . "\n";
        echo "   Deleted: " . count($changes['deleted_documents']) . "\n";
        
        return $changes;
    }
    
    /**
     * Simplify document structure from API response
     */
    private function simplifyDocument($doc) {
        return [
            'id' => $doc['id'] ?? null,
            'url' => $doc['url'] ?? null,
            'urlId' => $doc['urlId'] ?? null,
            'title' => $doc['title'] ?? '',
            'text' => $doc['text'] ?? '',
            'createdAt' => $doc['createdAt'] ?? date('c'),
            'createdBy' => [
                'id' => $doc['createdBy']['id'] ?? null,
                'name' => $doc['createdBy']['name'] ?? 'Unknown'
            ],
            'updatedAt' => $doc['updatedAt'] ?? date('c'),
            'updatedBy' => [
                'id' => $doc['updatedBy']['id'] ?? null,
                'name' => $doc['updatedBy']['name'] ?? 'Unknown'
            ],
            'collectionId' => $doc['collectionId'] ?? $this->collectionId,
            'parentDocumentId' => $doc['parentDocumentId'] ?? null
        ];
    }
    
    /**
     * Build document hierarchy mapping
     */
    public function buildDocumentHierarchy($documents) {
        $hierarchy = [];
        $parentMapping = [];
        
        foreach ($documents as $doc) {
            $docId = $doc['id'];
            $parentId = $doc['parentDocumentId'];
            
            $hierarchy[$docId] = [
                'document' => $doc,
                'children' => [],
                'parent' => $parentId,
                'is_parent' => false,
                'level' => 0
            ];
            
            if ($parentId) {
                $parentMapping[$docId] = $parentId;
            }
        }
        
        // Build parent-child relationships
        foreach ($parentMapping as $childId => $parentId) {
            if (isset($hierarchy[$parentId])) {
                $hierarchy[$parentId]['children'][] = $childId;
                $hierarchy[$parentId]['is_parent'] = true;
            }
        }
        
        // Calculate levels
        $this->calculateDocumentLevels($hierarchy);
        
        return $hierarchy;
    }
    
    /**
     * Calculate document nesting levels
     */
    private function calculateDocumentLevels(&$hierarchy, $docId = null, $level = 0) {
        if ($docId === null) {
            // Calculate for all root documents
            foreach ($hierarchy as $id => $data) {
                if ($data['parent'] === null) {
                    $this->calculateDocumentLevels($hierarchy, $id, 0);
                }
            }
            return;
        }
        
        if (isset($hierarchy[$docId])) {
            $hierarchy[$docId]['level'] = $level;
            
            foreach ($hierarchy[$docId]['children'] as $childId) {
                $this->calculateDocumentLevels($hierarchy, $childId, $level + 1);
            }
        }
    }
    
    /**
     * Generate local file path for a document based on hierarchy
     */
    public function generateLocalPath($doc, $hierarchy) {
        $docId = $doc['id'];
        
        if (!isset($hierarchy[$docId])) {
            return null;
        }
        
        $docInfo = $hierarchy[$docId];
        $pathParts = [];
        
        // Build path from root to this document
        $currentId = $docId;
        while ($currentId !== null) {
            $current = $hierarchy[$currentId];
            $folderName = $this->sanitizeFilename($current['document']['title']);
            
            if ($current['is_parent']) {
                // This is a parent - it becomes a folder
                array_unshift($pathParts, $folderName);
            }
            
            $currentId = $current['parent'];
        }
        
        // Generate final path
        if ($docInfo['is_parent']) {
            // Parent documents become folder/README.md
            $path = implode('/', $pathParts) . '/README.md';
        } else {
            // Child documents become folder/filename.md or just filename.md
            $filename = $this->sanitizeFilename($doc['title']) . '.md';
            if (!empty($pathParts)) {
                $path = implode('/', $pathParts) . '/' . $filename;
            } else {
                $path = $filename;
            }
        }
        
        echo "   Generated path for {$doc['title']}: $path\n";
        return $path;
    }
    
    /**
     * Sanitize filename for filesystem
     */
    private function sanitizeFilename($title) {
        $filename = preg_replace('/[^a-zA-Z0-9-_]/', '-', $title);
        return trim($filename, '-');
    }
    
    /**
     * Create new document in Outline
     */
    public function createDocument($title, $text, $parentDocumentId = null) {
        $endpointUrl = $this->baseUrl . "/api/documents.create";
        
        $data = [
            'title' => $title,
            'text' => $text,
            'collectionId' => $this->collectionId,
            'publish' => true  // Publish immediately so it's visible
        ];
        
        if ($parentDocumentId) {
            $data['parentDocumentId'] = $parentDocumentId;
        }
        
        echo "📝 Creating document in Outline: $title\n";
        
        $response = sendHttpRequest('POST', $endpointUrl, $data, $this->headers);
        
        if (!isset($response['data'])) {
            echo "🔍 API Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
            throw new Exception('Failed to create document: ' . json_encode($response));
        }
        
        // Handle Outline API response format
        $documentData = isset($response['data']['data']) ? $response['data']['data'] : $response['data'];
        
        return $this->simplifyDocument($documentData);
    }
    
    /**
     * Update existing document in Outline
     */
    public function updateDocument($documentId, $title = null, $text = null, $parentDocumentId = null) {
        $endpointUrl = $this->baseUrl . "/api/documents.update";
        
        $data = ['id' => $documentId];
        
        if ($title !== null) $data['title'] = $title;
        if ($text !== null) $data['text'] = $text;
        if ($parentDocumentId !== null) $data['parentDocumentId'] = $parentDocumentId;
        
        echo "✏️  Updating document in Outline: $documentId\n";
        
        $response = sendHttpRequest('POST', $endpointUrl, $data, $this->headers);
        
        if (!isset($response['data'])) {
            echo "🔍 API Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
            throw new Exception('Failed to update document: ' . json_encode($response));
        }
        
        // Handle Outline API response format
        $documentData = isset($response['data']['data']) ? $response['data']['data'] : $response['data'];
        
        echo "✅ Document updated successfully\n";
        return $this->simplifyDocument($documentData);
    }
    
    /**
     * Delete document in Outline
     */
    public function deleteDocument($documentId) {
        $endpointUrl = $this->baseUrl . "/api/documents.delete";
        
        $data = ['id' => $documentId];
        
        echo "🗑️  Deleting document in Outline: $documentId\n";
        
        $response = sendHttpRequest('POST', $endpointUrl, $data, $this->headers);
        
        return $response['ok'] ?? false;
    }
    
    /**
     * Load previous document mapping from metadata
     */
    private function loadPreviousDocumentMapping() {
        $metadataPath = $this->baseFolder . '/.outline';
        
        if (file_exists($metadataPath)) {
            $content = file_get_contents($metadataPath);
            $metadata = json_decode($content, true);
            
            if (isset($metadata['document_mapping'])) {
                echo "📖 Loaded previous document mapping from: $metadataPath\n";
                return $metadata['document_mapping'];
            }
        }
        
        echo "⚠️  No previous document mapping found - treating as first run\n";
        return [];
    }
    
    /**
     * Print summary of remote changes
     */
    public function printRemoteChangesSummary($changes) {
        echo "\n🌐 Remote Changes:\n";
        echo "- New documents: " . count($changes['new_documents']) . "\n";
        echo "- Updated documents: " . count($changes['updated_documents']) . "\n";
        echo "- Deleted documents: " . count($changes['deleted_documents']) . "\n\n";
    }
} 