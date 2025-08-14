# Sync Flow

## Initialization
• Load config.yaml (API token, domain, collection mappings)
• Initialize CollectionSelector with API credentials
• User selects collection from available list
• Extract collection ID, name, and local path from config
• Initialize SyncOrchestrator with collection ID and local path

## Phase 1: Scanning Current State
• Scan local filesystem (FileSystemScanner)
  - Find all .md files
  - Extract frontmatter (outline IDs)
  - Calculate file hashes and timestamps
• Fetch remote documents from Outline API
  - Get all documents in collection
  - Extract IDs, titles, content, timestamps
• Build document hierarchy (parent-child relationships)

## Phase 2: Detecting Changes
• Detect local changes (compare with .outline metadata)
  - New files (no outline ID)
  - Modified files (changed content/timestamp)
  - Moved files (same ID, different path)
  - Deleted files (in metadata but not on disk)
• Detect remote changes (compare with last sync time)
  - New documents (not in previous mapping)
  - Updated documents (newer than last sync)
  - Deleted documents (in mapping but not in API)

## Phase 3: Conflict Detection
• Check for simultaneous local/remote changes
• Identify files modified both locally and remotely
• Handle conflicts or halt sync if manual resolution needed

## Phase 4: Executing Sync Operations
• Local to Remote sync
  - Create new documents from new local files
  - Update existing documents from modified files
  - Move documents when files are moved
  - Delete documents when files are deleted
• Remote to Local sync
  - Handle parent conversions (file ↔ folder/README.md)
  - Create local files from new remote documents
  - Update local files from modified documents
  - Move local files when documents are moved
  - Delete local files when documents are deleted

## Phase 5: Updating Metadata
• Save document mapping to .outline file
• Update last sync timestamp
• Store local file states for next run
