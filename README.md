## What

Bi-directional syncronization between Outline (open source team knowlede sharing wiki) and local folder of simple markdown files. 

## Why
- TOOL AGNOSTIC: Allows folks to use any markdown editing tool they wish (Obsidian, TextEdit, VSCode) and still share work/research/writing via simple pull and push commands
- OFFLINE MODE: Enables an offline mode for an entire teams knowledge
- BACKUPS: Enables easy backups for remote, databased knowledge
- VERSIONING: Enables easy storage in Git for alternate form of version control
- AI TOOLS: Local files can make using AI tools simpler than wrestling with remote, Outline API all the time but this still gives ability to push any augmentations back to shared base

## How
In short, a JSON filed called ".outline" is created in the root directory that stores the structure of the remote database and compares this quickly to the local. Unique IDs are stored in this file and compared against ID values stored in the fronmatter of local, downloaded files and this helps with accuracy since the remote database can do things the local can't like create multiple files in same directory with the same name. 

In this case, on local, numbers are appended to the files (e.g. Readme.md, Readme2.md)

In outline parent pages are also turned into folders on the local file system and and README.md is inserted inside this new folder that contains and information that was in the converted parent page.

## Checks
These are all the different sync possibilities to check ... 

### Remote

- Create new
- Rename page
- Move page
  - New folder
  - Different folder
- Delete 
  - File
  - Folder

### Local

- Create new Yes
- Rename page Yes
- Move page 
  - New folder
  - Different folder
- Delete 
  - File
  - Folder



## Configuration

The tool uses a single `config.yaml` file for all settings:

1. **Copy the sample configuration**: `cp init/config-sample.yaml init/config.yaml`
2. **Edit the configuration**: Update with your Outline API token, domain, and collection mappings
3. **Protect the file**: The `config.yaml` file contains sensitive information and should not be committed to version control

### Configuration Structure

```yaml
# API Configuration
outline:
  token: "your_outline_api_token_here"
  domain: "your-outline-domain.com"

# Collection Mappings
collections:
  your-collection-id:
    name: "Collection Name"
    local_path: "/path/to/local/folder"
```

## Ideas
- Maybe break thing up so allows for 
- ✅ **COMPLETED**: Put all settings just into the YAML file instead of having two separate config files 