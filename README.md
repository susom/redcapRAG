# REDCapRAG External Module

**REDCapRAG** is a powerful External Module for REDCap that enables Retrieval-Augmented Generation (RAG) functionality. This module allows REDCap administrators and users to store, query, and retrieve context documents using vector embeddings, enabling enhanced document search and retrieval within REDCap projects.

The module integrates with AI models via the **SecureChatAI External Module** to generate embeddings and supports multiple backends for flexibility:
- **Redis with Redis Search Module** for optimal performance.
- **REDCap Entity Table** as a fallback for environments without Redis.

## Features

- **Create & Manage Collections**: Dynamically group context documents into logical collections for easy management.
- **AI-Generated Embeddings**: Leverage **SecureChatAI** to generate high-quality vector embeddings for content.
- **Semantic Search**: Query collections using vector similarity to retrieve relevant documents.
- **Multiple Backend Support**:
    - Redis (preferred) for fast and efficient vector search.
    - REDCap Entity Table (fallback) for seamless integration in Redis-free environments.
- **Developer-Friendly API**: Includes public functions for interaction with other REDCap External Modules.

## Prerequisites

- **REDCap**: Version 9.1.0 or higher.
- **SecureChatAI External Module**: Installed and configured to handle embedding generation.
- **Redis**: If using Redis as the backend (requires Redis Search Module).
- **REDCap Entity Module**: Required for Entity Table support.

## Installation

1. Download and extract the module into your REDCap `modules` directory.
2. Enable the module via the **External Modules** interface in REDCap.
3. Configure your preferred backend:
    - Redis: Ensure Redis and the Redis Search Module are installed and accessible.
    - Entity Table: No additional setup required; the module will automatically use the Entity Table if Redis is unavailable.
4. Install and configure the **SecureChatAI External Module** for embedding generation.

## Usage

### Public Functions
Other REDCap External Modules can interact with REDCapRAG using these public functions:

1. **`storeDocument($collection_name, $title, $content)`**:
    - Stores a document in the specified collection with content and embeddings.

2. **`getRelevantDocuments($collection_name, $query_text, $limit = 3)`**:
    - Queries a collection for documents with the highest semantic similarity to the query text. Returns up to `$limit` results.

### Data Structure (Entity Table Backend)
When using the REDCap Entity Table backend, documents are stored with the following fields:

- `collection_name`: Logical grouping of documents.
- `content`: The document text.
- `content_type`: Type of content (e.g., `text`).
- `file_url`: Optional URL to a file associated with the document.
- `upvotes` / `downvotes`: User ratings for relevance.
- `source`: Source of the content.
- `vector_embedding`: AI-generated embedding for the content.
- `meta_summary`: Summary of the document.
- `meta_tags`: Comma-separated tags for categorization.
- `meta_timestamp`: Timestamp for the document.

### Example Workflow
1. Store a document:
   ```php
   $module->storeDocument('example_collection', 'Document Title', 'This is the document content.');
2. Query for relevant documents:
   ```php
   $results = $module->getRelevantDocuments('example_collection', 'What is the best way to...?', 5);


## Backend Selection

### Redis (Recommended)
- **Performance**: Optimized for fast retrieval of large datasets.
- **Setup**: Requires Redis Search Module.

### Entity Table (Fallback)
- **Ease of Use**: No additional infrastructure required.
- **Limitations**: Slower and less efficient compared to Redis.

---

## Developer Notes

- **Embedding Generation**: REDCapRAG uses SecureChatAI to generate vector embeddings.
- **Fallback Logic**: If Redis is unavailable, the module seamlessly falls back to the Entity Table.
- **Cosine Similarity**: Similarity calculations use embeddings for document matching.

---

## Known TODOs

- **Redis Integration**: Complete Redis connection logic and testing.
- **Error Handling**: Improve handling of embedding retrieval failures.
- **Schema Building**: Ensure `redcap_module_system_enable` initializes the database schema:
  ```php
  \REDCapEntity\EntityDB::buildSchema($this->PREFIX);
