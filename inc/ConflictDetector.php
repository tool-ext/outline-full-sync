<?php
/*
================================================================================
CONFLICT DETECTOR - Detect and handle sync conflicts between local and remote
================================================================================
This class handles the detection and resolution guidance for conflicts that
occur when both local files and remote documents have been modified.

Responsibilities:
1. Detect bidirectional edit conflicts
2. Detect simultaneous edit conflicts (within time threshold)
3. Provide conflict resolution guidance
4. Display conflict information for manual resolution
================================================================================
*/

class ConflictDetector {
    
    /**
     * Detect conflicts between local and remote changes
     */
    public function detectConflicts($localChanges, $remoteChanges) {
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
        
        // Additional conflict detection: check if any modified local files have newer remote versions
        foreach ($localChanges['modified_files'] as $modifiedFile) {
            if ($modifiedFile['has_outline_id'] && !empty($modifiedFile['outline_id']) && $modifiedFile['outline_id'] !== 'null') {
                foreach ($remoteChanges['updated_documents'] as $remoteDoc) {
                    if ($remoteDoc['id'] === $modifiedFile['outline_id']) {
                        $localModified = $modifiedFile['modified_time'];
                        $remoteModified = strtotime($remoteDoc['updatedAt']);
                        
                        // If both were modified and timestamps are close (within 5 minutes), it's a potential conflict
                        if (abs($localModified - $remoteModified) < 300) { // 5 minutes = 300 seconds
                            $conflicts[] = [
                                'type' => 'simultaneous_edit',
                                'path' => $modifiedFile['path'],
                                'outline_id' => $modifiedFile['outline_id'],
                                'local_modified' => $localModified,
                                'remote_modified' => $remoteModified,
                                'local_data' => $modifiedFile,
                                'remote_data' => $remoteDoc
                            ];
                        }
                    }
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
    public function handleConflicts($conflicts) {
        echo "\n🚨 CONFLICTS DETECTED - Manual Resolution Required\n";
        echo "══════════════════════════════════════════════════\n\n";
        
        foreach ($conflicts as $index => $conflict) {
            echo "Conflict #" . ($index + 1) . ":\n";
            echo "  File: {$conflict['path']}\n";
            echo "  Local modified: " . date('Y-m-d H:i:s', $conflict['local_modified']) . "\n";
            echo "  Remote modified: " . date('Y-m-d H:i:s', $conflict['remote_modified']) . "\n";
            echo "  Outline ID: {$conflict['outline_id']}\n";
            
            // Suggest resolution based on timestamps
            $timeDiff = abs($conflict['local_modified'] - $conflict['remote_modified']);
            if ($timeDiff > 300) { // More than 5 minutes difference
                if ($conflict['local_modified'] > $conflict['remote_modified']) {
                    echo "  💡 Suggestion: Local file appears to be newer (by " . round($timeDiff/60, 1) . " minutes)\n";
                    echo "     Consider keeping local version\n";
                } else {
                    echo "  💡 Suggestion: Remote document appears to be newer (by " . round($timeDiff/60, 1) . " minutes)\n";
                    echo "     Consider keeping remote version\n";
                }
            } else {
                echo "  ⚠️  Timestamps are very close - manual review recommended\n";
            }
            echo "\n";
        }
        
        echo "Please resolve these conflicts manually and run the sync again.\n";
        echo "You can:\n";
        echo "1. Edit the local file to match your preferred version\n";
        echo "2. Edit the remote document in Outline\n";
        echo "3. Or wait for one side to be updated before syncing\n\n";
    }
}
