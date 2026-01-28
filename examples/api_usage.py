"""
Example: Using RAG EM API from Content Pipeline

This demonstrates how to call the RAG EM API endpoint to store documents
from an external content pipeline (e.g., Cloud Run service).
"""

import os
import requests
from typing import Dict, Any, Optional


def store_rag_document(
    title: str,
    content: str,
    metadata: Optional[Dict[str, Any]] = None,
    api_url: str = "http://localhost/api/",
    api_token: Optional[str] = None,
) -> Dict[str, Any]:
    """
    Store a single document to RAG vector database via REDCap API.

    Args:
        title: Document title (e.g., section_id like "sec_001")
        content: Full text content to embed and store
        metadata: Optional metadata dict (doc_id, section_id, source_uri, etc.)
        api_url: REDCap API endpoint (default: http://localhost/api/)
        api_token: REDCap API token (defaults to REDCAP_API_TOKEN env var)

    Returns:
        API response dict with status, namespace, title, message/error

    Raises:
        ValueError: If API token is missing
        RuntimeError: If API call fails
    """
    if not api_token:
        api_token = os.getenv("REDCAP_API_TOKEN")

    if not api_token:
        raise ValueError("Missing REDCAP_API_TOKEN in environment or parameters")

    payload = {
        "token": api_token,
        "content": "externalModule",
        "prefix": "redcap_rag",
        "action": "storeDocument",
        "format": "json",
        "returnFormat": "json",
        "title": title,
        "text": content,  # Use 'text' to avoid conflict with REDCap's 'content' param
    }

    if metadata:
        import json
        payload["metadata"] = json.dumps(metadata)

    try:
        resp = requests.post(api_url, data=payload, timeout=120)
        resp.raise_for_status()
        return resp.json()
    except Exception as e:
        raise RuntimeError(f"RAG API call failed: {e}")


def ingest_rpp_document(rpp_json: Dict[str, Any]) -> None:
    """
    Ingest a full rpp.v1 JSON document by looping through sections.

    Args:
        rpp_json: Parsed rpp.v1 JSON with documents[].sections[] structure
    """
    documents = rpp_json.get("documents", [])

    for document in documents:
        doc_id = document.get("doc_id", "unknown")
        source = document.get("source", {})
        source_uri = source.get("uri", "")
        source_type = source.get("type", "unknown")
        sections = document.get("sections", [])

        for section in sections:
            section_id = section.get("section_id", "unknown")
            text = section.get("text", "")

            if not text:
                continue  # Skip empty sections

            metadata = {
                "doc_id": doc_id,
                "section_id": section_id,
                "source_type": source_type,
                "source_uri": source_uri,
            }

            # Add optional fields if present
            if "section_version" in section:
                metadata["section_version"] = section["section_version"]
            if "section_updated" in section:
                metadata["section_updated"] = section["section_updated"]

            # Flatten location metadata
            if "location" in section:
                loc = section["location"]
                if "page" in loc:
                    metadata["location_page"] = loc["page"]
                if "section_title" in loc:
                    metadata["location_section_title"] = loc["section_title"]

            print(f"Storing section: {section_id}")
            result = store_rag_document(
                title=section_id,
                content=text,
                metadata=metadata,
            )

            if result.get("status") == "success":
                print(f"  ✅ Success: {section_id}")
            else:
                print(f"  ❌ Failed: {section_id}")
                print(f"     Error: {result.get('error')}")


if __name__ == "__main__":
    # Example usage
    import json

    # Load rpp.v1 JSON file
    with open("example_rpp.json", "r") as f:
        rpp_data = json.load(f)

    # Ingest all sections
    ingest_rpp_document(rpp_data)
