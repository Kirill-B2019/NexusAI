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
from routers import training_datasets

try:
    from ._helpers import fake_request
except ImportError:  # pragma: no cover - запуск как отдельного файла
    from _helpers import fake_request


class FakeTransaction:
    async def __aenter__(self):
        return self

    async def __aexit__(self, exc_type, exc, traceback):
        return False


class FakeConnection:
    def __init__(self, batch=None, expert_exists=True, existing_questions=None,
                 existing_batch=None, snapshots=None, release=None):
        self.batch = batch
        self.expert_exists = expert_exists
        self.existing_questions = existing_questions or []
        self.existing_batch = existing_batch
        self.snapshots = snapshots or []
        self.release = release
        self.inserted = []
        self.inserted_release = None
        self.executed = []

    def transaction(self):
        return FakeTransaction()

    async def fetchval(self, query, *args):
        if "FROM experts" in query:
            return 1 if self.expert_exists else None
        if "COUNT(*)" in query:
            return 1
        raise AssertionError(query)

    async def fetchrow(self, query, *args):
        if "source_sha256 = $2" in query:
            return self.existing_batch
        if "INSERT INTO training_dataset_releases" in query:
            self.inserted_release = args
            self.release = {
                "id": "release-id",
                "expert_key": args[0],
                "batch_ids": json.loads(args[1]),
                "example_count": args[2],
                "source_sha256": args[3],
                "format_version": args[4],
                "prep_params": json.loads(args[5]),
                "examples": json.loads(args[6]),
                "created_by": args[7],
                "created_at": datetime.now(timezone.utc),
            }
            return self.release
        if "INSERT INTO training_dataset_batches" in query:
            self.inserted = args
            self.batch = {
                "id": "batch-id",
                "expert_key": args[0],
                "original_filename": args[1],
                "source_sha256": args[2],
                "status": "pending_review",
                "total_count": args[3],
                "valid_count": args[4],
                "invalid_count": args[5],
                "duplicate_count": args[6],
                "validation_report": json.loads(args[7]),
                "created_by": args[8],
                "created_at": datetime.now(timezone.utc),
                "reviewed_by": None,
                "reviewed_at": None,
            }
            return self.batch
        if "FOR UPDATE" in query:
            return self.batch
        if query.lstrip().startswith("UPDATE training_dataset_batches"):
            if "SET validation_report" in query:
                self.batch["validation_report"] = json.loads(args[0])
                self.batch["total_count"] = args[1]
                self.batch["valid_count"] = args[2]
                self.batch["invalid_count"] = args[3]
                self.batch["duplicate_count"] = args[4]
                return self.batch
            self.batch["status"] = args[0]
            self.batch["reviewed_by"] = args[1]
            self.batch["reviewed_at"] = datetime.now(timezone.utc)
            return self.batch
        if "FROM training_dataset_releases" in query:
            return self.release
        if "FROM training_dataset_batches" in query:
            return self.batch
        raise AssertionError(query)

    async def fetch(self, query, *args):
        if "jsonb_array_elements" in query:
            return self.existing_questions
        if "training_dataset_snapshots" in query:
            return self.snapshots
        if "FROM training_dataset_releases" in query:
            return [self.release] if self.release else []
        return [self.batch] if self.batch else []

    async def execute(self, query, *args):
        self.executed.append((query, args))

    async def close(self):
        pass


class FakeUpload:
    filename = "batch.jsonl"

    def __init__(self, content):
        self.content = content

    async def read(self, size):
        assert size == training_datasets.MAX_DATASET_BYTES + 1
        return self.content


def _run(coroutine):
    return asyncio.run(coroutine)


def _jsonl_record(question="Вопрос", answer="Ответ", expert="system_architect"):
    return json.dumps({
        "messages": [
            {"role": "user", "content": question},
            {"role": "assistant", "content": answer},
        ],
        "metadata": {"expert": expert},
    }, ensure_ascii=False).encode()


def test_create_training_batch_persists_report_and_source_hash(monkeypatch):
    conn = FakeConnection()
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)
    content = _jsonl_record()

    result = _run(training_datasets.create_training_batch(
        request=fake_request(),
        expert="system_architect",
        file=FakeUpload(content),
        ctx=AuthContext(type="admin"),
    ))

    assert result["status"] == "pending_review"
    assert result["valid"] == 1
    assert result["sha256"] == hashlib.sha256(content).hexdigest()
    assert conn.inserted[0] == "system_architect"
    assert conn.inserted[2] == hashlib.sha256(content).hexdigest()
    assert conn.batch["validation_report"]["examples"][0]["question"] == "Вопрос"


def test_create_training_batch_reuses_existing_batch_for_same_file(monkeypatch):
    existing_batch = {
        "id": "existing-batch",
        "expert_key": "system_architect",
        "original_filename": "batch.jsonl",
        "source_sha256": hashlib.sha256(_jsonl_record()).hexdigest(),
        "status": "pending_review",
        "total_count": 1,
        "valid_count": 1,
        "invalid_count": 0,
        "duplicate_count": 0,
        "validation_report": {
            "format_version": 2,
            "examples": [{"row": 1, "question": "Вопрос", "answer": "Ответ",
                          "metadata": {}, "valid": True}],
        },
        "created_by": "admin",
        "created_at": datetime.now(timezone.utc),
        "reviewed_by": None,
        "reviewed_at": None,
    }
    conn = FakeConnection(existing_batch=existing_batch)
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    result = _run(training_datasets.create_training_batch(
        request=fake_request(),
        expert="system_architect",
        file=FakeUpload(_jsonl_record()),
        ctx=AuthContext(type="admin"),
    ))

    assert result["reused"] is True
    assert result["id"] == "existing-batch"
    assert conn.inserted == []


def test_create_training_batch_rejects_unknown_expert_without_persisting(monkeypatch):
    conn = FakeConnection(expert_exists=False)
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))

    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.create_training_batch(
            request=fake_request(),
            expert="unknown",
            file=FakeUpload(_jsonl_record(expert="unknown")),
            ctx=AuthContext(type="admin"),
        ))

    assert exception.value.status_code == 404
    assert conn.inserted == []


def test_create_training_batch_finds_duplicates_in_prior_expert_batches(monkeypatch):
    conn = FakeConnection(existing_questions=[{
        "batch_id": "previous-batch",
        "example_row": "3",
        "question": "Вопрос",
    }])
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    result = _run(training_datasets.create_training_batch(
        request=fake_request(),
        expert="system_architect",
        file=FakeUpload(_jsonl_record()),
        ctx=AuthContext(type="admin"),
    ))

    assert result["duplicates"] == 1
    assert result["validation"]["duplicates"] == [{
        "row": 1,
        "matches_batch_id": "previous-batch",
        "matches_row": 3,
        "matches_question": "Вопрос",
        "similarity": 1.0,
    }]


def test_approval_creates_immutable_snapshot_and_rejects_invalid_rows(monkeypatch):
    batch = {
        "id": "batch-id",
        "expert_key": "system_architect",
        "original_filename": "batch.jsonl",
        "source_sha256": "a" * 64,
        "status": "pending_review",
        "total_count": 1,
        "valid_count": 1,
        "invalid_count": 0,
        "duplicate_count": 1,
        "validation_report": {
            "examples": [{
                "row": 1,
                "question": "Вопрос",
                "answer": "Ответ",
                "metadata": {},
                "valid": True,
            }]
        },
        "created_by": "admin",
        "created_at": datetime.now(timezone.utc),
        "reviewed_by": None,
        "reviewed_at": None,
    }
    conn = FakeConnection(batch=batch)
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.approve_training_batch(
            "batch-id",
            training_datasets.TrainingBatchReview(duplicates_reviewed=False),
            fake_request(),
            AuthContext(type="admin"),
        ))

    assert exception.value.status_code == 409
    assert conn.executed == []

    approved = _run(training_datasets.approve_training_batch(
        "batch-id",
        training_datasets.TrainingBatchReview(duplicates_reviewed=True),
        fake_request(),
        AuthContext(type="admin"),
    ))

    assert approved["status"] == "approved"
    assert len(conn.executed) == 1
    assert "INSERT INTO training_dataset_snapshots" in conn.executed[0][0]
    assert json.loads(conn.executed[0][1][2])[0]["question"] == "Вопрос"
    assert approved["duplicates"] == 1

    invalid_batch = dict(batch)
    invalid_batch["status"] = "pending_review"
    invalid_batch["invalid_count"] = 1
    conn = FakeConnection(batch=invalid_batch)
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))

    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.approve_training_batch(
            "batch-id",
            training_datasets.TrainingBatchReview(duplicates_reviewed=True),
            fake_request(),
            AuthContext(type="admin"),
        ))

    assert exception.value.status_code == 409
    assert conn.executed == []


def _release_snapshots():
    return [
        {
            "snapshot_id": "snap-1",
            "batch_id": "batch-1",
            "examples": [
                {"row": 1, "question": "Вопрос один", "answer": "Ответ один",
                 "metadata": {"expert": "system_architect"}, "valid": True},
            ],
        },
        {
            "snapshot_id": "snap-2",
            "batch_id": "batch-2",
            "examples": [
                {"row": 1, "question": "Вопрос два", "answer": "Ответ два",
                 "metadata": {}, "valid": True},
                {"row": 2, "question": "Вопрос три", "answer": "Ответ три",
                 "metadata": {}, "valid": True},
            ],
        },
    ]


def test_create_release_composes_approved_snapshots_with_hash_and_params(monkeypatch):
    conn = FakeConnection(snapshots=_release_snapshots())
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    result = _run(training_datasets.create_training_release(
        training_datasets.TrainingReleaseCreate(expert="system_architect"),
        fake_request(),
        AuthContext(type="admin"),
    ))

    assert result["id"] == "release-id"
    assert result["expert"] == "system_architect"
    assert result["batch_ids"] == ["batch-1", "batch-2"]
    assert result["example_count"] == 3
    assert result["format_version"] == 2
    assert len(result["sha256"]) == 64
    assert result["prep_params"]["duplicate_threshold"] == 0.75

    assert conn.inserted_release[0] == "system_architect"
    assert conn.inserted_release[2] == 3
    assert json.loads(conn.inserted_release[6])[1]["question"] == "Вопрос два"


def test_create_release_requires_approved_snapshots(monkeypatch):
    conn = FakeConnection(snapshots=[])
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.create_training_release(
            training_datasets.TrainingReleaseCreate(expert="system_architect"),
            fake_request(),
            AuthContext(type="admin"),
        ))

    assert exception.value.status_code == 409
    assert conn.inserted_release is None


def test_create_release_rejects_unknown_expert(monkeypatch):
    conn = FakeConnection(expert_exists=False, snapshots=_release_snapshots())
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.create_training_release(
            training_datasets.TrainingReleaseCreate(expert="unknown"),
            fake_request(),
            AuthContext(type="admin"),
        ))

    assert exception.value.status_code == 404
    assert conn.inserted_release is None


def test_get_training_release_returns_examples_or_404(monkeypatch):
    conn = FakeConnection(release={
        "id": "release-id",
        "expert_key": "system_architect",
        "batch_ids": ["batch-1"],
        "example_count": 1,
        "source_sha256": "b" * 64,
        "format_version": 2,
        "prep_params": {"ordering": "batch_created_at"},
        "examples": [{"row": 1, "question": "Вопрос", "answer": "Ответ"}],
        "created_by": "admin",
        "created_at": datetime.now(timezone.utc),
    })
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))

    detail = _run(training_datasets.get_training_release(
        "release-id", AuthContext(type="admin"),
    ))
    assert detail["examples"][0]["question"] == "Вопрос"

    conn.release = None
    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.get_training_release(
            "release-id", AuthContext(type="admin"),
        ))
    assert exception.value.status_code == 404


def _draft_batch(status="pending_review", examples=None, errors=None):
    examples = examples if examples is not None else [
        {"row": 1, "question": "Вопрос один", "answer": "Ответ один",
         "metadata": {"expert": "system_architect"}, "source_format": "qna",
         "valid": True, "status": "duplicate_review"},
        {"row": 2, "question": "Вопрос один", "answer": "Ответ два",
         "metadata": {"expert": "system_architect"}, "source_format": "qna",
         "valid": True, "status": "duplicate_review"},
    ]
    errors = errors if errors is not None else [
        {"row": 3, "status": "invalid", "message": "Некорректный JSON."},
    ]
    return {
        "id": "batch-id",
        "expert_key": "system_architect",
        "original_filename": "batch.jsonl",
        "source_sha256": "a" * 64,
        "status": status,
        "total_count": 3,
        "valid_count": 2,
        "invalid_count": 1,
        "duplicate_count": 1,
        "validation_report": {
            "format_version": 2,
            "total": 3,
            "valid": 2,
            "invalid": 1,
            "duplicates": [{"row": 2, "matches_row": 1, "similarity": 1.0}],
            "errors": errors,
            "examples": examples,
        },
        "created_by": "admin",
        "created_at": datetime.now(timezone.utc),
        "reviewed_by": None,
        "reviewed_at": None,
    }


def test_delete_example_recomputes_counters_and_writes_audit(monkeypatch):
    conn = FakeConnection(batch=_draft_batch())
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    audits = []

    async def _record(**kwargs):
        audits.append(kwargs)

    monkeypatch.setattr(training_datasets, "write_audit", _record)

    result = _run(training_datasets.delete_training_example(
        "batch-id", fake_request(), 2, AuthContext(type="admin"),
    ))

    assert result["total"] == 2
    assert result["valid"] == 1
    assert result["invalid"] == 1
    assert result["duplicates"] == 0
    assert conn.batch["total_count"] == 2
    assert conn.batch["duplicate_count"] == 0
    assert [example["row"] for example in result["validation"]["examples"]] == [1]
    assert audits[0]["action"] == "training_dataset.example.delete"


def test_update_example_repairs_invalid_row_and_clears_error(monkeypatch):
    conn = FakeConnection(batch=_draft_batch())
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    result = _run(training_datasets.update_training_example(
        "batch-id",
        training_datasets.TrainingExampleUpdate(question="Новый вопрос", answer="Новый ответ"),
        fake_request(),
        3,
        AuthContext(type="admin"),
    ))

    assert result["invalid"] == 0
    assert result["total"] == 3
    assert result["valid"] == 3
    assert result["validation"]["errors"] == []
    repaired = result["validation"]["examples"][-1]
    assert repaired["row"] == 3
    assert repaired["source_format"] == "edited"
    assert repaired["status"] == "ready"


def test_update_example_flags_expert_mismatch_as_invalid(monkeypatch):
    conn = FakeConnection(batch=_draft_batch())
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    result = _run(training_datasets.update_training_example(
        "batch-id",
        training_datasets.TrainingExampleUpdate(metadata={"expert": "other_expert"}),
        fake_request(),
        1,
        AuthContext(type="admin"),
    ))

    assert result["valid"] == 1
    assert result["invalid"] == 2
    assert result["validation"]["examples"][0]["status"] == "invalid"


def test_update_example_requires_full_content_for_invalid_row(monkeypatch):
    conn = FakeConnection(batch=_draft_batch())
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.update_training_example(
            "batch-id",
            training_datasets.TrainingExampleUpdate(question="Только вопрос"),
            fake_request(),
            3,
            AuthContext(type="admin"),
        ))

    assert exception.value.status_code == 422


def test_update_example_rejected_after_review(monkeypatch):
    conn = FakeConnection(batch=_draft_batch(status="approved"))
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.update_training_example(
            "batch-id",
            training_datasets.TrainingExampleUpdate(question="Вопрос", answer="Ответ"),
            fake_request(),
            1,
            AuthContext(type="admin"),
        ))

    assert exception.value.status_code == 409


def test_delete_example_rejected_when_last_row(monkeypatch):
    batch = _draft_batch(
        examples=[{"row": 1, "question": "Вопрос", "answer": "Ответ",
                   "metadata": {}, "source_format": "qna", "valid": True,
                   "status": "ready"}],
        errors=[],
    )
    batch["validation_report"].update({
        "total": 1, "valid": 1, "invalid": 0, "duplicates": [],
    })
    conn = FakeConnection(batch=batch)
    monkeypatch.setattr(training_datasets, "_conn", lambda: _resolved(conn))
    monkeypatch.setattr(training_datasets, "write_audit", _noop_audit)

    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.delete_training_example(
            "batch-id", fake_request(), 1, AuthContext(type="admin"),
        ))

    assert exception.value.status_code == 409


def _resolved(value):
    async def result():
        return value

    return result()


async def _noop_audit(**kwargs):
    return None
