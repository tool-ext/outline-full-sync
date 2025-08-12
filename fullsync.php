<?php
/*
================================================================================
UNIFIED FULL SYNC - Complete bidirectional sync between Outline and local files
================================================================================
This is the main entry point that orchestrates:
1. Local filesystem scanning and change detection
2. Remote Outline API synchronization  
3. Conflict detection and resolution
4. Bidirectional sync operations
5. Metadata management and preservation

Usage: php fullsync.php
================================================================================
*/

// Include the main orchestrator (which includes all dependencies)
if (!function_exists('sendHttpRequest')) {
    require_once __DIR__ . '/init/init.php';
}
require_once __DIR__ . '/inc/SyncOrchestrator.php';

// Legacy FullSync class has been refactored into specialized components:
// - SyncOrchestrator: Main coordination
// - ConflictDetector: Conflict detection and resolution
// - LocalToRemoteSync: Pushing local changes to Outline
// - RemoteToLocalSync: Pulling remote changes to local
// - ParentConversionHandler: Document hierarchy management
// - MetadataManager: .outline file management

// Execute the sync if this file is run directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $sync = new SyncOrchestrator();
    $sync->execute();
}