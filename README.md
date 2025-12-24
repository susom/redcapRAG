# REDCap RAG External Module (RedcapRAG)

REDCap RAG is a **hybrid Retrieval-Augmented Generation (RAG)** external module for REDCap.  
It provides a shared, project-scoped knowledge store that can be queried using **semantic (dense) + keyword (sparse)** retrieval, with configurable hybrid weighting.

The module supports two execution modes:
- **Vector DB Mode (Pinecone)** – production hybrid RAG
- **Fallback Mode (REDCap Entity / MySQL)** – development or small corpora

---

## What This Module Does

At a high level, this module allows you to:

- Store arbitrary text documents as **retrievable context**
- Generate:
  - **Dense embeddings** (semantic similarity)
  - **Sparse vectors** (keyword overlap)
- Retrieve the most relevant documents using a **hybrid scoring model**
- Inspect, debug, tune, and manage RAG behavior via an **Admin Debug Panel**

This module is designed to be consumed by other REDCap External Modules  
(e.g. Chatbot EM, SecureChatAI workflows, custom automations).

---

## Core Concepts

### 1. Namespaces (Project Isolation)

All documents are stored under a **namespace**, typically:

```
project_identifier
```


This value is used as:
- The **Pinecone namespace**, or
- The **partition key** in the REDCap Entity table

Namespaces allow:
- Per-project isolation
- Multi-tenant usage
- Safe experimentation without cross-contamination

---

## Hybrid Search Theory (Dense + Sparse)

### Why Hybrid Search?

Pure vector search:
- Excellent semantic understanding
- Weak on exact terms, acronyms, IDs

Pure keyword search:
- Precise term matching
- Weak on paraphrasing and intent

**Hybrid search combines both.**

---

### Dense Search (Semantic)

- Generated using SecureChatAI embeddings
- Measures **conceptual similarity**
- Example:  
  > “heart attack” ≈ “myocardial infarction”

---

### Sparse Search (Keyword)

- Token-based term matching
- Captures:
  - Exact phrases
  - IDs
  - Domain-specific jargon
- Implemented using Pinecone sparse vectors  
  (with TF-based fallback)

---

### Hybrid Scoring

Both scores are merged using configurable weights:

```
hybrid_score = (dense_weight × dense_score) + (sparse_weight × sparse_score)
```

Default:

```
dense_weight = 0.65
sparse_weight = 0.35
```


Tuning guidance:
- Increase **dense weight** → favor semantic meaning
- Increase **sparse weight** → favor exact term matches

---

## Architecture Overview

This section describes how the Chatbot EM (Cappy), REDCap RAG, and SecureChatAI work together at runtime.

### Runtime Query Flow

1. **User submits a message** in the Chatbot UI (Cappy).

2. **Cappy manages conversation state** and determines whether retrieval-augmented generation (RAG) is required.

3. **Cappy calls REDCap RAG** with:
   - `project_identifier` (namespace)
   - full message history

4. **REDCap RAG performs retrieval**:
   - Extracts the latest user query
   - Generates dense embeddings
   - Generates sparse keyword vectors
   - Executes dense + sparse search
   - Merges results using configured hybrid weights
   - Returns top-K ranked context documents

5. **Cappy injects retrieved context** into the SecureChatAI request.

6. **SecureChatAI calls the selected LLM**, producing a grounded response.

7. **Final answer is returned to the user**.
   
<!-- ┌──────────────────────────┐
│        End User           │
│  (REDCap Chat UI / Cappy) │
└─────────────┬────────────┘
              │
              │ 1. User asks a question
              │
              ▼
┌──────────────────────────┐
│      Chatbot EM           │
│         (Cappy)           │
│                            │
│ - Manages conversation     │
│ - Holds chat history       │
│ - Decides when to invoke   │
│   RAG retrieval            │
│ - Provides ingestion tools │
└─────────────┬────────────┘
              │
              │ 2. RAG Retrieval Call
              │    (project_identifier, messages)
              ▼
┌──────────────────────────┐
│      REDCap RAG EM        │
│                            │
│ - Extracts query text     │
│ - Generates embeddings    │
│ - Performs hybrid search  │
│ - Returns top-K documents │
└─────────────┬────────────┘
              │
              │ 3. Hybrid Search
              ▼
      ┌─────────────────────────────┐
      │        Vector Storage         │
      │                               │
      │  Dense Index (Semantic)       │
      │  - Embeddings                │
      │  - Conceptual similarity     │
      │                               │
      │  Sparse Index (Keyword)       │
      │  - Term-based matching       │
      │  - IDs / acronyms / jargon   │
      └─────────────┬───────────────┘
                    │
                    │ 4. Merge + Weight
                    ▼
┌──────────────────────────┐
│   Hybrid Ranked Context  │
│  (Dense + Sparse Scores) │
└─────────────┬────────────┘
              │
              │ 5. Context Injection
              ▼
┌──────────────────────────┐
│     SecureChatAI EM       │
│                            │
│ - Receives user prompt    │
│ - Injects retrieved RAG   │
│   context                 │
│ - Calls selected LLM      │
└─────────────┬────────────┘
              │
              │ 6. Model Response
              ▼
┌──────────────────────────┐
│        End User           │
│   (Grounded Answer)       │
└──────────────────────────┘ -->

### Ingestion Flow (Cappy Tool → RAG)

1. **Admin or developer uses the ingestion tool** in the Chatbot EM (Cappy).

2. **Cappy validates input** and selects the target RAG namespace.

3. **Cappy calls `storeDocument()`** in REDCap RAG.

4. **REDCap RAG ingests content**:
   - Computes a SHA-256 hash (deduplication)
   - Generates dense and sparse vectors
   - Stores data in Pinecone or Entity fallback

5. **Content becomes immediately available** for retrieval in subsequent chat queries.
<!-- ┌──────────────────────────┐
│     Admin / Developer    │
│    (Cappy Ingestion UI)  │
└─────────────┬────────────┘
              │
              │ 1. Submit content
              │    + namespace
              ▼
┌──────────────────────────┐
│      Chatbot EM           │
│         (Cappy)           │
│                            │
│ - Validates input         │
│ - Selects namespace       │
│ - Calls RAG ingestion     │
└─────────────┬────────────┘
              │
              │ 2. storeDocument()
              ▼
┌──────────────────────────┐
│      REDCap RAG EM        │
│                            │
│ - SHA-256 dedupe          │
│ - Dense embedding         │
│ - Sparse vector           │
│ - Upsert to storage       │
└─────────────┬────────────┘
              │
              │ 3. Persist
              ▼
┌──────────────────────────┐
│   Pinecone / Entity DB   │
│  (Project Namespace)     │
└──────────────────────────┘ -->


## Storage Modes

### 1. Vector DB Mode (Pinecone) — Recommended

**Enabled when:** `use_vectordb = true`

Features:
- Dense semantic search
- Sparse keyword search
- Hybrid scoring
- Scales to large corpora
- Best retrieval quality

Architecture:
- Dense vectors → Pinecone serverless index
- Sparse vectors → Pinecone pod index
- Shared namespace across both

---

### 2. Fallback Mode (REDCap Entity / MySQL)

**Used automatically when Pinecone is disabled**

Features:
- Dense embeddings stored as JSON
- Cosine similarity ranking
- No sparse or hybrid scoring

Limitations:
- Slower for large datasets
- No keyword weighting
- Intended for:
  - Development
  - Testing
  - Small document sets

No configuration changes required — fallback is automatic.

---

## Installation

### 1. Install Module

Place the module in:

```
/redcap/modules/redcap_rag_vX.X.X/
```

Enable it in:

```
Control Center → External Modules
```


---

### 2. System Configuration

#### Required (Vector DB Mode)

- `use_vectordb`
- `pinecone_api_key`
- `pinecone_host` (dense index)
- `pinecone_host_sparse` (sparse index)
- `pinecone_inference_host`

Optional (recommended):
- `hybrid_candidate_k` (default: 20)
- `hybrid_dense_weight` (default: 0.6)
- `hybrid_sparse_weight` (default: 0.4)

#### Fallback Mode

No additional configuration required.

---

## Document Lifecycle

### Deduplication

- Documents are hashed using **SHA-256**
- Identical content is never re-inserted
- Works across:
  - Pinecone
  - Entity fallback

---

### Ingestion

```
storeDocument(projectId, title, content)
```


Steps:
1. Generate dense embedding
2. Generate sparse vector
3. Hash content
4. Upsert (or store entity)
5. Skip if duplicate

---

### Retrieval

```
getRelevantDocuments(projectId, messages, topK)
```


- Uses last user message as the query
- Performs dense + sparse search
- Merges results
- Returns top-K ranked documents

---

### Ingestion via Chatbot EM (Cappy)

In addition to programmatic ingestion, **interactive ingestion is available as a tool inside the REDCap Chatbot (Cappy) External Module**.

When enabled in Cappy:
- Administrators can ingest documents directly from the chatbot UI
- Documents are written into **specific RAG namespaces** (project identifiers)
- The same ingestion pipeline is used:
  - SHA-256 deduplication
  - Dense embedding generation
  - Sparse vector generation
  - Pinecone or Entity fallback storage

This allows:
- On-demand knowledge base updates
- Project-scoped ingestion without writing custom scripts
- Safe, auditable population of RAG context during chatbot setup

The Chatbot EM acts as a **controlled ingestion surface**, while RedcapRAG remains the underlying retrieval and storage engine.

---

## Admin Debug Panel

A built-in inspection and tuning UI for administrators.

**Location**

```
Control Center → External Modules → REDCap RAG → RAG Debug
```


---

### Capabilities

- Select or enter any namespace
- Run test queries
- Inspect:
  - Dense score
  - Sparse score
  - Hybrid score
- Heat-mapped score visualization
- Sortable tables
- Expand rows to view full content
- Delete individual documents
- Purge entire namespaces (with confirmation)
- List all stored documents

---

### Intended Uses

- Hybrid weight tuning
- Verifying ingestion correctness
- Diagnosing poor retrieval
- QA before enabling RAG in production
- Manual cleanup of small knowledge bases

---

## Public API Methods

### `storeDocument($projectId, $title, $content, $dateCreated)`
Stores a document with embeddings.

### `checkAndStoreDocument(...)`
Deduplication wrapper.

### `getRelevantDocuments($projectId, $messages, $topK)`
Primary RAG retrieval entrypoint.

### `debugSearchContext($projectId, $query, $topK)`
Used by the Admin Debug Panel.

### `listContextDocuments($projectId)`
List stored documents.

### `fetchContextDocument($projectId, $id)`
Fetch a single document.

### `deleteContextDocument($projectId, $id)`
Delete one document.

### `purgeContextNamespace($projectId)`
Delete all documents in a namespace.

---

## Security Notes

- Admin UI requires REDCap authentication
- CSRF tokens enforced for all write operations
- No PHI is introduced unless present in source documents
- Namespace isolation prevents cross-project leakage

---

## Summary

This module provides a **production-ready hybrid RAG foundation** for REDCap:
- Clean abstraction
- Safe fallback
- Debuggable and tunable
- Designed for integration, not demos

If you are building REDCap chatbots, copilots, or AI-assisted workflows,  
this module is the retrieval layer you want.


