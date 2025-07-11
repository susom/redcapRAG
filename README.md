# REDCapRAG External Module

**REDCapRAG** is a modular External Module for [REDCap](https://projectredcap.org/) that enables **Retrieval-Augmented Generation (RAG)** workflows in any REDCap project. It allows you to store, index, and semantically search knowledge base documents using vector embeddings—powering smarter AI assistants, chatbots, and search tools in REDCap.

---

## **Key Features**

- **Pluggable Context Store:**  
  Store arbitrary documents, summaries, or notes and retrieve them by semantic similarity (not just keyword match).

- **Project-Based Collections:**  
  Each project (or logical scope) has its own vector index for privacy and relevance.

- **Flexible Backends:**  
  - **Redis** (with Redis Search module): for fast, production-scale search  
  - **REDCap Entity Table:** fallback for smaller projects or environments without Redis

- **Embeddings Powered by SecureChatAI EM:**  
  Embeddings are generated using the SecureChatAI EM, which can use any supported model (e.g., OpenAI Ada, GPT, DeepSeek, etc.).

- **Cosine Similarity Search:**  
  Document retrieval is based on vector math—finds the most semantically relevant context.

- **Developer-Friendly Public API:**  
  Expose `storeDocument`, `getRelevantDocuments`, etc. so *any* other EM or workflow can use RAG.

---

## **Why This Module?**

- **Separation of Concerns:**  
  - RAG manages context storage & search only.  
  - SecureChatAI handles embedding generation and LLM API calls.  
  - Chatbots (or any AI workflows) can consume RAG without pulling in chat-specific code.
- **Composable:**  
  Use RAG for chatbots, form assistance, research search, or any AI augmentation.

---

## Typical Workflow

*You almost always access REDCapRAG from another External Module (EM) using a getter, **not** directly:*

```php
// Get the RAG EM instance via your main EM’s baseclass helper
$rag = $module->getRedcapRAGInstance(); // or $this->getRedcapRAGInstance() in a class

// Store a document for later retrieval
$rag->storeDocument($projectIdentifier, $title, $content);

// Retrieve relevant documents for a user query
$results = $rag->getRelevantDocuments($projectIdentifier, $chatArray, 5);
```

- **Plug RAG into any Chatbot/AI EM:**  
  - Example: Use RAG to fetch context for each user question in your chatbot, and inject into LLM prompt.

---

## **Public Functions (for other EMs)**

- `storeDocument($projectIdentifier, $title, $content, $dateCreated = null)`  
  Store a new document (auto-embeds and dedupes).

- `getRelevantDocuments($projectIdentifier, $queryArray, $limit = 3)`  
  Retrieve up to `$limit` most relevant docs for a query (pass in user message array).

- `checkAndStoreDocument($projectIdentifier, $title, $content, $dateCreated = null)`  
  Convenience function: only stores if unique.

---

## **Data Model (Entity Table backend)**

- `project_identifier`: Project or logical scope for grouping.
- `content`, `content_type`, `file_url`
- `vector_embedding`: JSON-encoded vector from SecureChatAI
- `source`, `meta_summary`, `meta_tags`, `meta_timestamp`
- `hash`: SHA256 for deduplication
- `upvotes`, `downvotes`: For user feedback (future relevance tuning)

---

## **Backend Selection**

- **Redis (Recommended):**
  - Fast, scalable, required for large or production setups.
  - Needs Redis Search module enabled.
- **Entity Table:**
  - Built-in, slower, best for small or demo sites.

---

## **Installation**

1. **Install SecureChatAI EM** and configure LLM/embedding endpoints.
2. **Install Redis** (optional, for high performance).
3. **Add REDCapRAG EM to your REDCap modules directory.**
4. **Enable the module** in the REDCap External Modules admin UI.
5. **Configure your backend** (choose Redis or fallback to Entity Table).
6. **Build entity schema** (auto-runs when enabled).

---

## **FAQ / Gotchas**

- **How does a chatbot (e.g., Cappy) use RAG?**  
  It calls `getRelevantDocuments()` each time it needs context for an LLM call.

- **How are embeddings generated?**  
  RAG calls SecureChatAI EM to generate them—so you can swap embedding providers by updating SecureChatAI.

- **What about relevance tuning?**  
  Upvote/downvote fields are included for future expansion—user feedback loops, etc.

---

## **Developer Notes**

- **Cosine similarity math is native PHP** for Entity Table; for Redis, you can implement server-side scoring.
- **Batch processing and backend abstraction** are ready for future scaling.
- **Public API is stable:** Use from any EM, service, or custom workflow.

---

## **See Also**

- [SecureChatAI External Module](https://github.com/susom/secureChatAI) (embedding and LLM orchestration)
- [REDCap Chatbot](https://github.com/susom/redcap-em-chatbot)

---

**Questions?**  
Read the code, see the docblocks, or ping the maintainer—this module was built for future composability and developer sanity.

---
