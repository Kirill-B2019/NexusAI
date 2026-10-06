"""
NEXUS AI — Python клиент.

Использование:
    export NEXUS_URL="http://31.128.38.96/api"
    export NEXUS_KEY="<admin или project-ключ>"

    python client.py
"""

import os
import json
from typing import Optional, List, Dict, Any, Iterator
import httpx


class NexusClient:
    """Клиент NEXUS AI API."""

    def __init__(
        self,
        base_url: Optional[str] = None,
        api_key: Optional[str] = None,
        timeout: int = 300,
    ):
        self.base_url = (base_url or os.getenv("NEXUS_URL", "http://31.128.38.96/api")).rstrip("/")
        self.api_key = api_key or os.getenv("NEXUS_KEY", "")
        self.client = httpx.Client(
            timeout=timeout,
            headers={
                "X-API-Key": self.api_key,
                "Content-Type": "application/json",
                "Accept": "application/json",
            },
        )

    # ─── System ───────────────────────────────────────────
    def health(self) -> Dict[str, Any]:
        r = self.client.get(f"{self.base_url}/health")
        r.raise_for_status()
        return r.json()

    def version(self) -> Dict[str, Any]:
        r = self.client.get(f"{self.base_url}/version")
        r.raise_for_status()
        return r.json()

    # ─── Experts ──────────────────────────────────────────
    def list_experts(self, enabled_only: bool = True) -> List[Dict[str, Any]]:
        url = f"{self.base_url}/v1/experts" if enabled_only else f"{self.base_url}/v1/experts/all"
        r = self.client.get(url)
        r.raise_for_status()
        return r.json()["experts"]

    # ─── Projects ─────────────────────────────────────────
    def list_projects(self) -> List[Dict[str, Any]]:
        r = self.client.get(f"{self.base_url}/v1/projects")
        r.raise_for_status()
        return r.json()["projects"]

    def create_project(self, name: str, external_id: Optional[str] = None) -> Dict[str, Any]:
        payload = {"name": name}
        if external_id:
            payload["external_id"] = external_id
        r = self.client.post(f"{self.base_url}/v1/projects", json=payload)
        r.raise_for_status()
        return r.json()

    # ─── API Keys ─────────────────────────────────────────
    def create_api_key(
        self,
        project_id: str,
        name: str,
        allowed_experts: Optional[List[str]] = None,
        rate_limit_per_min: int = 60,
    ) -> Dict[str, Any]:
        payload = {
            "name": name,
            "rate_limit_per_min": rate_limit_per_min,
        }
        if allowed_experts:
            payload["allowed_experts"] = allowed_experts
        r = self.client.post(
            f"{self.base_url}/v1/projects/{project_id}/keys",
            json=payload,
        )
        r.raise_for_status()
        return r.json()

    # ─── Documents ────────────────────────────────────────
    def upload_document(self, project_id: str, file_path: str) -> Dict[str, Any]:
        with open(file_path, "rb") as f:
            files = {"file": (os.path.basename(file_path), f)}
            r = self.client.post(
                f"{self.base_url}/v1/projects/{project_id}/documents",
                files=files,
                headers={"X-API-Key": self.api_key},
            )
            r.raise_for_status()
            return r.json()

    def list_documents(self, project_id: str) -> List[Dict[str, Any]]:
        r = self.client.get(f"{self.base_url}/v1/projects/{project_id}/documents")
        r.raise_for_status()
        return r.json()["documents"]

    def document_status(self, doc_id: str) -> Dict[str, Any]:
        r = self.client.get(f"{self.base_url}/v1/documents/{doc_id}/status")
        r.raise_for_status()
        return r.json()

    # ─── Chat ─────────────────────────────────────────────
    def chat(
        self,
        message: str,
        expert: Optional[str] = None,
        experts: Optional[List[str]] = None,
        thinking: bool = False,
        project_id: Optional[str] = None,
        conversation_id: Optional[str] = None,
        use_rag: bool = True,
        rag_top_k: int = 4,
        document_ids: Optional[List[str]] = None,
        save_to_conversation: bool = False,
    ) -> Dict[str, Any]:
        payload: Dict[str, Any] = {
            "message": message,
            "thinking": thinking,
            "use_rag": use_rag,
            "rag_top_k": rag_top_k,
            "orchestrate": expert is None and experts is None,
        }
        if expert:
            payload["expert"] = expert
            payload["orchestrate"] = False
        if experts:
            payload["experts"] = experts
        if project_id:
            payload["project_id"] = project_id
        if conversation_id:
            payload["conversation_id"] = conversation_id
            payload["save_to_conversation"] = save_to_conversation
        if document_ids:
            payload["document_ids"] = document_ids

        r = self.client.post(f"{self.base_url}/v1/chat", json=payload)
        r.raise_for_status()
        return r.json()

    def stream_chat(
        self,
        message: str,
        expert: Optional[str] = None,
        thinking: bool = False,
        project_id: Optional[str] = None,
    ) -> Iterator[tuple]:
        """Генератор SSE-событий: (event_type, data)."""
        payload: Dict[str, Any] = {
            "message": message,
            "thinking": thinking,
            "use_rag": True,
        }
        if expert:
            payload["expert"] = expert
        if project_id:
            payload["project_id"] = project_id

        with self.client.stream(
            "POST",
            f"{self.base_url}/v1/chat/stream",
            json=payload,
        ) as response:
            response.raise_for_status()
            event_type = "message"
            for line in response.iter_lines():
                if line.startswith("event: "):
                    event_type = line[7:].strip()
                elif line.startswith("data: "):
                    yield event_type, json.loads(line[6:])


if __name__ == "__main__":
    # Демонстрация
    client = NexusClient()

    print("=== Healthcheck ===")
    print(client.health())

    print("\n=== Эксперты ===")
    for e in client.list_experts():
        print(f"  {e['icon']} {e['key']}: {e['name']}")

    print("\n=== Чат: single ===")
    result = client.chat(
        "Что такое API Gateway? Кратко.",
        expert="system_architect",
        use_rag=False,
    )
    print(f"  elapsed: {result['elapsed_s']}s")
    print(f"  content: {result['content'][:200]}...")

    print("\n=== Чат: стрим (первые 10 токенов) ===")
    count = 0
    for event_type, data in client.stream_chat(
        "Что такое монолит?",
        expert="system_architect",
    ):
        if event_type == "token":
            print(data["delta"], end="", flush=True)
            count += 1
            if count >= 10:
                break
    print()

# ---
# | KB @CerberRus00 - Nexus Invest Team
