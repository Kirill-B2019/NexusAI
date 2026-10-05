"""Unit-тесты для chunker.py."""
import sys
sys.path.insert(0, "/app")

from chunker import chunk_text


def test_empty():
    assert chunk_text("") == []
    assert chunk_text("   ") == []


def test_short_text():
    chunks = chunk_text("Короткий текст.")
    assert len(chunks) == 1
    assert chunks[0]["text"] == "Короткий текст."
    assert chunks[0]["index"] == 0
    assert chunks[0]["char_start"] == 0


def test_long_text_multiple_chunks():
    text = "Предложение номер один. " * 50
    chunks = chunk_text(text, chunk_size=200, overlap=50)
    assert len(chunks) > 1
    # Проверка индексов
    for i, c in enumerate(chunks):
        assert c["index"] == i


def test_overlap():
    text = "Первое предложение. Второе предложение. Третье предложение. " * 10
    chunks = chunk_text(text, chunk_size=200, overlap=80)
    assert len(chunks) >= 2
    # Конец первого чанка должен частично совпадать с началом второго
    # (не строгая проверка, но должны быть общие символы)
    assert chunks[0]["char_end"] > chunks[1]["char_start"]


def test_no_punctuation():
    text = "a" * 2000
    chunks = chunk_text(text, chunk_size=500, overlap=100)
    assert len(chunks) == 5


def test_char_bounds():
    text = "Тестовый текст для проверки границ. " * 20
    chunks = chunk_text(text, chunk_size=150, overlap=30)
    for c in chunks:
        assert c["char_start"] < c["char_end"]
        assert c["char_end"] <= len(text)


if __name__ == "__main__":
    import pytest
    pytest.main([__file__, "-v"])
