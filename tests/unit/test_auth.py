"""Unit-тесты для auth.py."""
import sys
sys.path.insert(0, "/app")

from auth import hash_key, generate_api_key, AuthContext


def test_hash_key_deterministic():
    h1 = hash_key("test-key-123")
    h2 = hash_key("test-key-123")
    assert h1 == h2
    assert len(h1) == 64  # sha256 hex


def test_hash_key_different():
    assert hash_key("a") != hash_key("b")


def test_generate_api_key_format():
    key = generate_api_key()
    assert key.startswith("nx_")
    assert len(key) > 20


def test_generate_api_key_unique():
    keys = {generate_api_key() for _ in range(10)}
    assert len(keys) == 10


def test_auth_context_is_admin():
    admin = AuthContext(type="admin")
    assert admin.is_admin is True

    project = AuthContext(type="project", project_id="abc")
    assert project.is_admin is False


if __name__ == "__main__":
    import pytest
    pytest.main([__file__, "-v"])
