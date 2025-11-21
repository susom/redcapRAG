# REDCap RAG External Module (RedcapRAG)

This module provides a hybrid Retrieval-Augmented Generation (RAG) layer for REDCap. It supports storing text documents with both dense embeddings and sparse keyword vectors, and retrieving them through a hybrid weighted search pipeline. The system can operate using Pinecone as a vector database or fall back to REDCap Entity storage.

---

## Features

### 1. Hybrid Vector Search (Dense + Sparse)
- Dense semantic search via OpenAI embeddings  
- Sparse keyword search via TF-based hashing  
- Hybrid scoring with configurable dense/sparse weights  
- Top-K selection applied after merging

### 2. Two Storage Modes
- Vector DB Mode (Pinecone): full dense+sparse hybrid retrieval  
- MySQL Entity Mode: fallback cosine-similarity engine  

### 3. Namespace Isolation
Each REDCap project (or logical scope) defines a:

```
project_rag_project_identifier
```


Used as the Pinecone namespace or MySQL partition.

### 4. Document Deduplication
Documents use a stable SHA-256 hash to prevent duplicates from being inserted.

### 5. Admin Debug Panel (New)

A standalone system page for module administrators:

Location:  
Control Center → External Modules → REDCap RAG → RAG Debug

Capabilities:
- Enter a namespace (project identifier) and run test queries  
- Inspect per-document scores:  
  - Dense score  
  - Sparse score  
  - Hybrid score  
- Color-coded score heat mapping  
- Sortable result table  
- Collapsible full-content preview  
- Delete individual vectors  
- Purge an entire namespace  
- List all stored documents in the namespace  
- View sparse/dense-only mismatches if using hybrid mode  

This page mirrors the Chatbot EM's debugging console but is dedicated to RAG operations.

---

## Installation

1. Place the module folder into:

```
/redcap/modules/redcap_rag_vX.X.X/
```


2. Enable the module in:  
Control Center → External Modules.

3. Configure the following system settings.

### Vector DB Mode (Pinecone)
Required:
- Pinecone API Key  
- Pinecone Dense Host (serverless)  
- Pinecone Sparse Host (pod index)  
- Enable: use_vectordb  
- Hybrid weights:  
  - hybrid_dense_weight  
  - hybrid_sparse_weight

### All Modes
- project_rag_project_identifier

---

## Key Concepts

### Dense Embeddings
Generated through SecureChatAI using model ada-002.

### Sparse Vectors
- Simple tokenizer  
- Term-frequency normalized (TF/maxTF)  
- Stable hashed indices via crc32(term) % 200000  
- Upserted into Pinecone’s sparse pod index

### Hybrid Merge Process
1. Collect all unique document IDs  
2. Normalize sparse scores using log-scaling  
3. Weighted hybrid scoring:  

```
hybrid = (dense_weight * denseScore) + (sparse_weight * sparseScore)
```


4. Sort  
5. Slice to top-K  

---

## Public Methods

### storeDocument($projectId, $title, $content, $dateCreated)
Stores a document and generates dense + sparse vectors.

### checkAndStoreDocument(...)
Deduplication wrapper; prevents re-inserting identical content.

### getRelevantDocuments($projectId, $messages, $topK)
Retrieves context for SecureChatAI calls.

### debugSearchContext($projectId, $query, $topK)
Used by the Admin Debug Panel for test queries.

### listContextDocuments($projectId)
Lists documents for a namespace.

### deleteContextDocument($projectId, $id)
Deletes a vector from both dense and sparse Pinecone indexes.

### purgeContextNamespace($projectId)
Clears the entire namespace.

---

## Admin Debug Panel (Details)

A full-featured inspection tool for system admins.

Access:  
Control Center → External Modules → REDCap RAG → RAG Debug

Functions:
- Enter namespace manually (useful for projects, test spaces, multi-tenant setups)
- Run test vector searches
- Inspect dense, sparse, and hybrid contributions numerically
- Heat-mapped scoring columns
- Expand row → view full stored content
- Delete a single vector
- Purge entire namespace (requires manual confirmation)
- List all stored vectors (dense and sparse)

Intended Uses:
- Troubleshooting hybrid weighting  
- Verifying ingestion correctness  
- Ensuring dedupe is functioning  
- Manually managing small knowledge bases  
- QA before enabling RAG for production chatbots  

---

## Security Notes
- All admin functions require REDCap authentication  
- CSRF tokens applied to all write operations  
- No PHI is stored unless provided by the source documents  

---

## MySQL Fallback Mode

If Pinecone is disabled:

- Embeddings stored as JSON via REDCap Entity  
- Cosine similarity used for ranking  
- No sparse/hybrid scoring  
- Recommended only for small corpora or development environments  

Fallback mode activates automatically and is visible in debug logs.
