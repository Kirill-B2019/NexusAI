import json
import sys
from pathlib import Path

import pytest
from fastapi import HTTPException

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from dataset_validation import find_cross_batch_duplicates, validate_dataset


def test_validate_jsonl_accepts_documented_messages_format():
    content = (
        json.dumps({
            "messages": [
                {"role": "system", "content": "Ты эксперт."},
                {"role": "user", "content": " Как спроектировать API? "},
                {"role": "assistant", "content": "Сначала опишите контракт."},
            ],
            "metadata": {"expert": "system_architect", "source": "manual"},
        }, ensure_ascii=False) + "\n"
    ).encode()

    result = validate_dataset("batch.jsonl", content)

    assert result["total"] == result["valid"] == 1
    assert result["invalid"] == 0
    assert result["examples"][0]["question"] == "Как спроектировать API?"
    assert result["examples"][0]["metadata"]["expert"] == "system_architect"


def test_validate_jsonl_reports_invalid_rows_and_question_duplicates():
    lines = [
        {"messages": [
            {"role": "user", "content": "Как проектировать API?"},
            {"role": "assistant", "content": "Опишите контракт."},
        ]},
        {"messages": [
            {"role": "user", "content": "Как проектировать API"},
            {"role": "assistant", "content": "Уточните требования."},
        ]},
        {"messages": [{"role": "user", "content": "Без ответа"}]},
    ]
    content = "\n".join(json.dumps(line, ensure_ascii=False) for line in lines).encode()

    result = validate_dataset("batch.jsonl", content)

    assert result["total"] == 3
    assert result["valid"] == 2
    assert result["invalid"] == 1
    assert result["duplicates"] == [{
        "row": 2,
        "matches_row": 1,
        "similarity": 1.0,
    }]
    assert result["errors"][0]["row"] == 3


def test_validate_csv_accepts_question_and_answer_headers():
    result = validate_dataset(
        "batch.csv",
        b"question,answer,topic\n\"Question one\",\"Answer one\",api\n",
    )

    assert result["format_version"] == 2
    assert result["valid"] == 1
    assert result["examples"][0]["metadata"] == {"topic": "api"}
    assert result["examples"][0]["source_format"] == "csv"


def test_validate_jsonl_accepts_flat_question_answer_with_optional_fields():
    content = (
        json.dumps({
            "question": " Что такое оркестратор? ",
            "answer": " Компонент маршрутизации. ",
            "topic": "architecture",
            "labels": ["orchestrator"],
            "note": "проверить формулировку",
            "source_id": "manual-04",
        }, ensure_ascii=False) + "\n"
    ).encode()

    result = validate_dataset("batch.jsonl", content)

    assert result["valid"] == 1
    example = result["examples"][0]
    assert example["question"] == "Что такое оркестратор?"
    assert example["answer"] == "Компонент маршрутизации."
    assert example["source_format"] == "qna"
    assert example["metadata"] == {
        "topic": "architecture",
        "labels": ["orchestrator"],
        "note": "проверить формулировку",
        "source_id": "manual-04",
    }


def test_validate_jsonl_keeps_system_prompt_of_legacy_messages():
    content = (
        json.dumps({
            "messages": [
                {"role": "system", "content": "Ты системный архитектор."},
                {"role": "user", "content": "Объясни API Gateway."},
                {"role": "assistant", "content": "Точка входа трафика."},
            ],
        }, ensure_ascii=False) + "\n"
    ).encode()

    result = validate_dataset("batch.jsonl", content)

    assert result["valid"] == 1
    example = result["examples"][0]
    assert example["source_format"] == "messages"
    assert example["metadata"]["system_prompt"] == "Ты системный архитектор."


@pytest.mark.parametrize(
    ("record", "message"),
    [
        ({"foo": "bar"}, "Ожидается поле messages"),
        ({"question": "Вопрос"}, "В поле answer должен быть непустой"),
        ({"answer": "Ответ"}, "В поле question должен быть непустой"),
    ],
)
def test_validate_jsonl_reports_flat_format_errors(record, message):
    result = validate_dataset(
        "batch.jsonl",
        (json.dumps(record, ensure_ascii=False) + "\n").encode(),
    )

    assert result["invalid"] == 1
    assert message in result["errors"][0]["message"]


def test_find_cross_batch_duplicates_returns_matching_batch_and_source_row():
    duplicates = find_cross_batch_duplicates(
        [{
            "row": 4,
            "question": "Как проектировать API?",
            "valid": True,
        }],
        [{
            "batch_id": "previous-batch",
            "row": 2,
            "question": "Как проектировать API",
        }],
    )

    assert duplicates == [{
        "row": 4,
        "matches_batch_id": "previous-batch",
        "matches_row": 2,
        "matches_question": "Как проектировать API",
        "similarity": 1.0,
    }]


def test_find_cross_batch_duplicates_skips_invalid_examples():
    duplicates = find_cross_batch_duplicates(
        [{
            "row": 4,
            "question": "Как проектировать API?",
            "valid": False,
        }],
        [{
            "batch_id": "previous-batch",
            "row": 2,
            "question": "Как проектировать API",
        }],
    )

    assert duplicates == []


@pytest.mark.parametrize(
    ("filename", "content", "message"),
    [
        ("batch.txt", b"data", "Поддерживаются только файлы JSONL и CSV."),
        ("batch.csv", b"prompt,response\nq,a\n", "В CSV обязательны заголовки question и answer."),
        ("batch.jsonl", b"\xff", "Файл должен быть в кодировке UTF-8."),
        ("batch.jsonl", b"\n  \n", "Файл не содержит строк с примерами."),
    ],
)
def test_validate_dataset_rejects_unsupported_or_malformed_input(filename, content, message):
    with pytest.raises(ValueError, match=message):
        validate_dataset(filename, content)


def test_validate_training_dataset_checks_admin_and_expert(monkeypatch):
    from routers import training_datasets
    from auth import AuthContext

    try:
        from ._helpers import fake_request
    except ImportError:  # pragma: no cover - запуск как отдельного файла
        from _helpers import fake_request

    class Upload:
        filename = "batch.jsonl"

        async def read(self, size=-1):
            return b"{}"

    monkeypatch.setattr(
        training_datasets,
        "_conn",
        lambda: _async_result(FakeConnection(expert_exists=False)),
    )

    with pytest.raises(HTTPException) as exception:
        _run(training_datasets.validate_training_dataset(
            request=fake_request(),
            expert="unknown",
            file=Upload(),
            ctx=AuthContext(type="admin"),
        ))

    assert exception.value.status_code == 404


def _async_result(value):
    async def result():
        return value

    return result()


def _run(coroutine):
    import asyncio

    return asyncio.run(coroutine)


class FakeConnection:
    def __init__(self, expert_exists):
        self.expert_exists = expert_exists

    async def fetchval(self, query, expert):
        assert "deleted_at IS NULL" in query
        return self.expert_exists

    async def close(self):
        pass
