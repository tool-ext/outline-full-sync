<?php
/*
================================================================================
PARENT CONVERSION HANDLER - Handle document-to-folder and folder-to-document conversions
================================================================================
This class manages the complex process of converting documents between standalone
files and parent folders (README.md) based on their hierarchical relationships.

Responsibilities:
1. Detect when documents become parents (need folder conversion)
2. Convert standalone files to folders with README.md
3. Detect when documents are no longer parents (need file conversion)
4. Convert folders with README.md back to standalone files
5. Handle folder creation and cleanup safely
================================================================================
*/

class ParentConversionHandler {
    private $remoteSync;
    private $fileOps;
    private $fileScanner;
    private $baseFolder;
    
    public function __construct($remoteSync, $fileOps, $fileScanner, $baseFolder) {
        $this->remoteSync = $remoteSync;
        $this->fileOps = $fileOps;
        $this->fileScanner = $fileScanner;
        $this->baseFolder = $baseFolder;
    }
    
    /**
     * Handle documents that became parents - convert from file to folder/README.md
     * Also handle documents that are no longer parents - convert back to standalone files
     */
    public function handleParentConversions($hierarchy) {
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
        $oldFilePath = $this->baseFolder . '/' . $localFile['path'];
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
        $readmeFilePath = $this->baseFolder . '/' . $readmeFile['path'];
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
