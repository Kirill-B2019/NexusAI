"""Unit-тесты для extractors.py."""
import sys
sys.path.insert(0, "/app")

from extractors import extract, get_mime, _sanitize, SUPPORTED_EXTENSIONS


def test_sanitize_null_bytes():
    text = "привет\x00мир"
    assert _sanitize(text) == "приветмир"


def test_sanitize_control_chars():
    text = "abc\x01\x02def"
    result = _sanitize(text)
    assert "\x01" not in result
    assert "\x02" not in result
    assert "abcdef" in result


def test_sanitize_keeps_newlines():
    text = "строка1\nстрока2\ttab\r\n"
    result = _sanitize(text)
    assert "\n" in result
    assert "\t" in result
    assert "\r" in result


def test_sanitize_empty():
    assert _sanitize("") == ""
    assert _sanitize(None) is None


def test_get_mime():
    assert get_mime("test.md") == "text/markdown"
    assert get_mime("doc.pdf") == "application/pdf"
    assert get_mime("main.py") == "text/x-python"
    assert get_mime("data.json") == "application/json"
    assert get_mime("unknown") == "text/plain"


def test_supported_extensions():
    for ext in [".md", ".pdf", ".docx", ".xlsx", ".csv", ".py"]:
        assert ext in SUPPORTED_EXTENSIONS


def test_extract_markdown():
    data = "# Заголовок\n\nТекст документа.".encode("utf-8")
    text = extract("test.md", data)
    assert "Заголовок" in text
    assert "Текст документа" in text


def test_extract_with_null_bytes():
    data = "abc\x00def\x00ghi".encode("utf-8")
    text = extract("test.txt", data)
    assert "\x00" not in text
    assert "abcdefghi" in text


if __name__ == "__main__":
    import pytest
    pytest.main([__file__, "-v"])
