import asyncio
import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

import pytest
from fastapi import HTTPException

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from auth import AuthContext
from routers import lora_adapters

try:
    from ._helpers import fake_request
except ImportError:  # pragma: no cover - запуск как отдельного файла
    from _helpers import fake_request


class FakeTransaction:
    async def __aenter__(self):
        return self

    async def __aexit__(self, exc_type, exc, traceback):
        return False


def _now():
    return datetime.now(timezone.utc)


def _adapter_row(**overrides):
    row = {
        "id": "adapter-id",
        "expert_key": "system_architect",
        "version": 1,
        "status": "candidate",
        "storage_path": "system_architect/abc.gguf",
        "file_sha256": "a" * 64,
        "file_size_bytes": 1024,
        "base_model": "Qwen3-4B",
        "dataset_release_id": None,
        "training_manifest": {},
        "metrics": {},
        "notes": None,
        "created_by": "admin",
        "created_at": _now(),
        "published_by": None,
        "published_at": None,
        "rolled_back_by": None,
        "rolled_back_at": None,
    }
    row.update(overrides)
    return row


class FakeConnection:
    def __init__(self, adapters=None, expert_exists=True, release_expert=None):
        self.adapters = list(adapters or [])
        self.expert_exists = expert_exists
        self.release_expert = release_expert
        self.inserted = None
        self.executed = []
        self.deleted = []

    def transaction(self):
        return FakeTransaction()

    async def fetchval(self, query, *args):
        if "FROM experts" in query:
            return 1 if self.expert_exists else None
        if "FROM training_dataset_releases" in query:
            return self.release_expert
        if "WHERE storage_path = $1" in query:
            return sum(
                1 for row in self.adapters if row["storage_path"] == args[0]
            )
        if "COUNT(*)" in query:
            expert, status = args
            return sum(
                1 for row in self.adapters
                if (expert is None or row["expert_key"] == expert)
                and (status is None or row["status"] == status)
            )
        raise AssertionError(query)

    async def fetchrow(self, query, *args):
        if "INSERT INTO lora_adapters" in query:
            expert = args[0]
            version = 1 + max(
                (
                    row["version"] for row in self.adapters
                    if row["expert_key"] == expert
                ),
                default=0,
            )
            self.inserted = args
            row = _adapter_row(
                id=f"adapter-{version}",
                expert_key=expert,
                version=version,
                status="candidate",
                storage_path=args[1],
                file_sha256=args[2],
                file_size_bytes=args[3],
                base_model=args[4],
                dataset_release_id=args[5],
                training_manifest=json.loads(args[6]),
                metrics=json.loads(args[7]),
                notes=args[8],
                created_by=args[9],
            )
            self.adapters.append(row)
            return row
        if "WHERE expert_key = $1 AND file_sha256 = $2" in query:
            expert, sha = args
            matches = [
                row for row in self.adapters
                if row["expert_key"] == expert and row["file_sha256"] == sha
            ]
            return matches[-1] if matches else None
        if "AND status = 'published'" in query:
            expert = args[0]
            matches = [
                row for row in self.adapters
                if row["expert_key"] == expert and row["status"] == "published"
            ]
            return matches[0] if matches else None
        if query.lstrip().startswith("UPDATE lora_adapters"):
            if "SET status = 'published'" in query:
                row = self._by_id(args[1])
                row["status"] = "published"
                row["published_by"] = args[0]
                row["published_at"] = _now()
                return row
            if "SET status = 'rolled_back'" in query:
                row = self._by_id(args[1])
                row["status"] = "rolled_back"
                row["rolled_back_by"] = args[0]
                row["rolled_back_at"] = _now()
                return row
            raise AssertionError(query)
        if "FROM lora_adapters" in query:
            row = self._by_id(args[0])
            return row
        raise AssertionError(query)

    async def fetch(self, query, *args):
        expert, status, limit, offset = args
        rows = [
            row for row in self.adapters
            if (expert is None or row["expert_key"] == expert)
            and (status is None or row["status"] == status)
        ]
        return rows[offset:offset + limit]

    async def execute(self, query, *args):
        self.executed.append((query, args))
        if query.lstrip().startswith("DELETE FROM lora_adapters"):
            self.deleted.append(args[0])
            self.adapters = [
                row for row in self.adapters if str(row["id"]) != str(args[0])
            ]
        elif query.lstrip().startswith("UPDATE lora_adapters"):
            # Снятие предыдущего published в publish (execute без RETURNING).
            row = self._by_id(args[1])
            row["status"] = "rolled_back"
            row["rolled_back_by"] = args[0]
            row["rolled_back_at"] = _now()

    async def close(self):
        pass

    def _by_id(self, adapter_id):
        for row in self.adapters:
            if str(row["id"]) == str(adapter_id):
                return row
        raise AssertionError(f"adapter {adapter_id} not found")


class FakeUpload:
    def __init__(self, content, filename="adapter.gguf"):
        self.content = content
        self.filename = filename

    async def read(self, size):
        assert size == lora_adapters.MAX_ADAPTER_BYTES + 1
        return self.content


def _run(coroutine):
    return asyncio.run(coroutine)


async def _noop_audit(**kwargs):
    pass


def _resolved(value):
    async def result():
        return value

    return result()


def _upload(monkeypatch, conn, content=b"gguf-bytes", filename="adapter.gguf",
            **kwargs):
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(lora_adapters, "write_audit", _noop_audit)
    kwargs.setdefault("expert", "system_architect")
    kwargs.setdefault("base_model", "Qwen3-4B")
    kwargs.setdefault("dataset_release_id", None)
    kwargs.setdefault("training_manifest", None)
    kwargs.setdefault("metrics", None)
    kwargs.setdefault("notes", None)
    return _run(lora_adapters.upload_lora_adapter(
        request=fake_request(),
        file=FakeUpload(content, filename),
        ctx=AuthContext(type="admin", actor="admin"),
        **kwargs,
    ))


def test_upload_creates_candidate_with_auto_version_and_saves_file(
    monkeypatch, tmp_path,
):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    conn = FakeConnection()
    content = b"gguf-bytes"

    result = _upload(monkeypatch, conn, content)

    assert result["status"] == "candidate"
    assert result["version"] == 1
    assert result["sha256"] == hashlib.sha256(content).hexdigest()
    assert result["reused"] is False
    assert conn.inserted[0] == "system_architect"
    saved = tmp_path / "system_architect" / (
        hashlib.sha256(content).hexdigest() + ".gguf"
    )
    assert saved.read_bytes() == content


def test_upload_reuses_existing_adapter_for_same_file(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    content = b"gguf-bytes"
    sha = hashlib.sha256(content).hexdigest()
    conn = FakeConnection(adapters=[_adapter_row(id="adapter-1", file_sha256=sha)])

    result = _upload(monkeypatch, conn, content)

    assert result["reused"] is True
    assert result["id"] == "adapter-1"
    assert conn.inserted is None


def test_upload_rejects_unknown_expert(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    conn = FakeConnection(expert_exists=False)

    with pytest.raises(HTTPException) as exception:
        _upload(monkeypatch, conn)

    assert exception.value.status_code == 404
    assert conn.inserted is None


def test_upload_rejects_non_gguf_filename(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    conn = FakeConnection()

    with pytest.raises(HTTPException) as exception:
        _upload(monkeypatch, conn, filename="adapter.bin")

    assert exception.value.status_code == 422
    assert conn.inserted is None


def test_upload_rejects_release_of_another_expert(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    conn = FakeConnection(release_expert="fintech")

    with pytest.raises(HTTPException) as exception:
        _upload(monkeypatch, conn, dataset_release_id="11111111-1111-1111-1111-111111111111")

    assert exception.value.status_code == 422
    assert conn.inserted is None


def test_publish_demotes_previous_published_adapter(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    previous = _adapter_row(
        id="old", version=1, status="published", published_at=_now(),
    )
    target = _adapter_row(id="new", version=2, status="candidate")
    conn = FakeConnection(adapters=[previous, target])
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(lora_adapters, "write_audit", _noop_audit)

    result = _run(lora_adapters.publish_lora_adapter(
        adapter_id="new",
        request=fake_request(),
        ctx=AuthContext(type="admin", actor="admin"),
    ))

    assert result["status"] == "published"
    assert previous["status"] == "rolled_back"
    assert previous["rolled_back_by"] == "admin"


def test_publish_rejects_already_published(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    target = _adapter_row(status="published", published_at=_now())
    conn = FakeConnection(adapters=[target])
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))

    with pytest.raises(HTTPException) as exception:
        _run(lora_adapters.publish_lora_adapter(
            adapter_id="adapter-id",
            request=fake_request(),
            ctx=AuthContext(type="admin", actor="admin"),
        ))

    assert exception.value.status_code == 409


def test_publish_rejects_archived_adapter(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    target = _adapter_row(status="archived")
    conn = FakeConnection(adapters=[target])
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))

    with pytest.raises(HTTPException) as exception:
        _run(lora_adapters.publish_lora_adapter(
            adapter_id="adapter-id",
            request=fake_request(),
            ctx=AuthContext(type="admin", actor="admin"),
        ))

    assert exception.value.status_code == 409


def test_rollback_moves_published_to_rolled_back(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    target = _adapter_row(status="published", published_at=_now())
    conn = FakeConnection(adapters=[target])
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(lora_adapters, "write_audit", _noop_audit)

    result = _run(lora_adapters.rollback_lora_adapter(
        adapter_id="adapter-id",
        request=fake_request(),
        ctx=AuthContext(type="admin", actor="admin"),
    ))

    assert result["status"] == "rolled_back"
    assert target["rolled_back_by"] == "admin"


def test_rollback_rejects_non_published_adapter(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    conn = FakeConnection(adapters=[_adapter_row(status="candidate")])
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))

    with pytest.raises(HTTPException) as exception:
        _run(lora_adapters.rollback_lora_adapter(
            adapter_id="adapter-id",
            request=fake_request(),
            ctx=AuthContext(type="admin", actor="admin"),
        ))

    assert exception.value.status_code == 409


def test_delete_rejects_published_and_removes_orphan_file(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    content = b"gguf-bytes"
    sha = hashlib.sha256(content).hexdigest()
    relative = f"system_architect/{sha}.gguf"
    target = tmp_path / "system_architect" / f"{sha}.gguf"
    target.parent.mkdir(parents=True)
    target.write_bytes(content)

    published = _adapter_row(
        id="pub", status="published", published_at=_now(),
        storage_path=relative, file_sha256=sha,
    )
    candidate = _adapter_row(
        id="cand", version=2, status="candidate",
        storage_path=relative, file_sha256=sha,
    )
    conn = FakeConnection(adapters=[published, candidate])
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(lora_adapters, "write_audit", _noop_audit)

    with pytest.raises(HTTPException) as exception:
        _run(lora_adapters.delete_lora_adapter(
            adapter_id="pub",
            request=fake_request(),
            ctx=AuthContext(type="admin", actor="admin"),
        ))
    assert exception.value.status_code == 409

    _run(lora_adapters.delete_lora_adapter(
        adapter_id="cand",
        request=fake_request(),
        ctx=AuthContext(type="admin", actor="admin"),
    ))
    # Один референс (published) остался — файл сохранён.
    assert target.exists()
    assert candidate not in conn.adapters
    assert published in conn.adapters


def test_delete_removes_file_when_no_references_left(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    content = b"gguf-bytes"
    sha = hashlib.sha256(content).hexdigest()
    relative = f"system_architect/{sha}.gguf"
    target = tmp_path / "system_architect" / f"{sha}.gguf"
    target.parent.mkdir(parents=True)
    target.write_bytes(content)

    candidate = _adapter_row(storage_path=relative, file_sha256=sha)
    conn = FakeConnection(adapters=[candidate])
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(lora_adapters, "write_audit", _noop_audit)

    _run(lora_adapters.delete_lora_adapter(
        adapter_id="adapter-id",
        request=fake_request(),
        ctx=AuthContext(type="admin", actor="admin"),
    ))

    assert not target.exists()


def test_non_admin_is_rejected(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    conn = FakeConnection()
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))

    with pytest.raises(HTTPException) as exception:
        _run(lora_adapters.upload_lora_adapter(
            request=fake_request(),
            file=FakeUpload(b"gguf-bytes"),
            ctx=AuthContext(type="project", project_id="p1", actor="project:p1"),
            expert="system_architect",
            base_model="Qwen3-4B",
        ))

    assert exception.value.status_code == 403


def test_list_filters_by_expert_and_status(monkeypatch, tmp_path):
    monkeypatch.setattr(lora_adapters, "LORA_STORAGE_DIR", str(tmp_path))
    conn = FakeConnection(adapters=[
        _adapter_row(id="a1", expert_key="system_architect", status="candidate"),
        _adapter_row(id="a2", expert_key="fintech", status="published",
                     published_at=_now()),
    ])
    monkeypatch.setattr(lora_adapters, "_conn", lambda: _resolved(conn))

    result = _run(lora_adapters.list_lora_adapters(
        expert="fintech",
        status=None,
        limit=50,
        offset=0,
        ctx=AuthContext(type="admin", actor="admin"),
    ))

    assert result["total"] == 1
    assert result["adapters"][0]["id"] == "a2"
