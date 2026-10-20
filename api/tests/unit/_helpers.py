"""Общие помощники для unit-тестов роутеров."""
from types import SimpleNamespace


class FakeRequest:
    """Минимальный Request для проверки IP/User-Agent/request_id в аудите."""

    def __init__(self, ip="203.0.113.7", real_ip=None, user_agent="pytest",
                 request_id="req-test"):
        headers = {"User-Agent": user_agent}
        if real_ip:
            headers["X-Real-IP"] = real_ip
        self.headers = headers
        self.client = SimpleNamespace(host=ip) if ip else None
        self.state = SimpleNamespace(request_id=request_id)


def fake_request(**kwargs) -> FakeRequest:
    return FakeRequest(**kwargs)
