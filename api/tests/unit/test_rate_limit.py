"""Unit-тесты для rate_limit.py."""
import sys
import time
sys.path.insert(0, "/app")

from rate_limit import check_rate_limit, _buckets, _lock


def test_under_limit():
    key = f"test_under_{time.time()}"
    for i in range(5):
        allowed, remaining, _ = check_rate_limit(key, 10)
        assert allowed is True
        assert remaining == 10 - (i + 1)


def test_at_limit():
    key = f"test_at_{time.time()}"
    for i in range(3):
        allowed, _, _ = check_rate_limit(key, 3)
        assert allowed is True
    # 4-й — заблокирован
    allowed, remaining, reset_in = check_rate_limit(key, 3)
    assert allowed is False
    assert remaining == 0
    assert reset_in > 0


def test_independent_keys():
    k1 = f"ind1_{time.time()}"
    k2 = f"ind2_{time.time()}"
    for _ in range(5):
        check_rate_limit(k1, 5)
    # k2 всё ещё свободен
    allowed, _, _ = check_rate_limit(k2, 5)
    assert allowed is True


if __name__ == "__main__":
    import pytest
    pytest.main([__file__, "-v"])
