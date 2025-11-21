# REDCap RAG External Module (RedcapRAG)

This module provides a hybrid RAG (Retrieval-Augmented Generation) layer for REDCap projects. It allows you to ingest text, PDF-derived chunks, or custom context data and retrieve them using **dense semantic vectors**, **sparse keyword vectors**, or a **hybrid of both** using Pinecone.

---

## Features

### **1. Hybrid Vector Search (Dense + Sparse)**

* Dense semantic search via embeddings
* Sparse keyword search via TF-based hashing
* Automated hybrid scoring with configurable weights
* Post-merge top‑K selection for accuracy

### **2. Two Storage Modes**

* **Vector DB mode (Pinecone)** – full hybrid retrieval
* **MySQL Entity mode** – fallback when Pinecone is disabled

### **3. RAG Namespace Isolation**

Each REDCap project can define its own:

* `project_rag_project_identifier`

This value becomes the Pinecone namespace or MySQL partition.

### **4. Deduplication**

Documents use a stable SHA‑256 hash to avoid re‑inserting duplicates.

### **5. Admin Debug Panel**

Allows you to:

* Search vectors
* Inspect dense/sparse scores
* Delete individual vectors
* Purge entire namespaces
* View and manage stored documents

### **6. Zero‑Vector Namespace Listing**

Serverless Pinecone hosts are auto-detected and skipped since they do not support listing.

---

## Installation

1. Place the module directory inside:

```
/redcap/modules/redcap_rag_vX.X.X/
```

2. Enable the module in **Control Center → External Modules**.

3. Configure required settings:

### **Required (Vector DB mode)**

* Pinecone API Key
* Pinecone Dense Host (serverless)
* Pinecone Sparse Host (pod index)
* Enable: `use_vectordb`

### **Required (Both modes)**

* `project_rag_project_identifier`

---

## Key Concepts

### **Dense Embeddings (Semantic)**

Generated through SecureChatAI using model `ada-002`.

### **Sparse Vectors (Keywords)**

Lightweight TF hash-based:

* tokenizes text
* computes normalized term frequency
* assigns stable indices via `crc32(term) % 200000`

### **Hybrid Merging**

Scores are normalized and combined:

```
hybrid = (dense_weight * denseScore) + (sparse_weight * sparseScore)
```

Top‑K is applied *after* merging.

---

## 📘 Public Methods

### `storeDocument($projectId, $title, $content, $dateCreated)`

Stores a document with embedding and sparse vector.

### `checkAndStoreDocument(...)`

Deduplicates by SHA‑256 and only stores if missing.

### `getRelevantDocuments($projectId, $messages, $topK)`

Retrieves RAG context for SecureChatAI calls.

### `debugSearchContext($projectId, $query, $topK)`

Used by the admin panel debug tool.

### `listContextDocuments($projectId)`

List all documents belonging to a namespace.

### `deleteContextDocument($projectId, $id)`

Delete a single document.

### `purgeContextNamespace($projectId)`

Delete *all* vectors for a namespace.

---

## 🛠 Internal Mechanics

### Embeddings

Uses SecureChatAI → OpenAI embeddings via:

```
callAI("ada-002", ["input" => $text])
```

### Sparse Upserts

Sent to Pinecone pod index for keyword augmentation.

### Hybrid Merge

1. Collect all unique IDs
2. Normalize sparse (log scale)
3. Weighted combine
4. Sort
5. Slice to top‑K

---

## Security Notes

* All admin actions require REDCap user permissions
* CSRF tokens included in all write operations
* No PHI is stored unless sources include it explicitly

---

## License

Internal Stanford Research IT use unless otherwise specified.

---

If you’d like, I can also generate:

* A **Bootstrap-styled Debug UI** README screenshots section
* An "Advanced Setup" section for multi-agent pipelines
* A "Troubleshooting" section (Pinecone issues, namespace confusion, etc.)

## MySQL Fallback Path

If Pinecone is disabled or unavailable, the module automatically falls back to REDCap Entity storage (`redcap_entity_generic_contextdb`).

* Computes cosine similarity over stored JSON embeddings
* No additional configuration required
* Ideal for dev/local or restricted environments
* Recommended for small corpora (<5k docs)

When fallback activates, logs show: `debugSearchContext Entity fallback`.
