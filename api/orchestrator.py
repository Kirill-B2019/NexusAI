"""
NEXUS AI Orchestrator v2
- Эксперты загружаются из БД (experts_service)
- 3 режима: auto / manual list / single
- Параллельное исполнение, программный aggregator
"""
import asyncio
import httpx
import os
import re
from typing import List, Dict, Any, Optional, Tuple

import experts_service

MODEL_URL = os.getenv("MODEL_SERVER_URL", "http://nexus-model:8080")
MODEL_TIMEOUT = int(os.getenv("MODEL_TIMEOUT", "900"))
EXPERT_TIMEOUT = int(os.getenv("EXPERT_TIMEOUT", "900"))

SINGLE_MAX_TOKENS = 1024
SINGLE_THINKING_MAX_TOKENS = 4096
ORCH_MAX_TOKENS = 700
ORCH_THINKING_MAX_TOKENS = 2000

MAX_EXPERTS_PER_REQUEST = 6

CHITCHAT_PATTERNS = [
    r"^(привет|здравствуй|хай|hi|hello)",
    r"кто ты",
    r"как дела",
    r"что ты умеешь",
    r"твоя роль",
]


# ─────────────────────────────────────────────────────────────
# ROUTER
# ─────────────────────────────────────────────────────────────

def _is_chitchat(message: str) -> bool:
    low = message.lower().strip()
    if len(low) < 15:
        return True
    for pat in CHITCHAT_PATTERNS:
        if re.search(pat, low):
            return True
    return False


def _keyword_match(message: str, keywords_map: Dict[str, List[str]]) -> List[str]:
    low = message.lower()
    scores = {k: 0 for k in keywords_map}
    for expert, words in keywords_map.items():
        for w in words:
            if w and w.lower() in low:
                scores[expert] += 1
    matched = sorted(
        [(e, s) for e, s in scores.items() if s > 0],
        key=lambda x: -x[1]
    )
    return [e for e, _ in matched]


async def _llm_classify(message: str, available: List[str]) -> List[str]:
    keys_str = ", ".join(available)
    prompt = (
        "Ты — маршрутизатор задач в AI-платформе NEXUS AI. "
        f"Доступные эксперты: {keys_str}. "
        "Определи, какие эксперты нужны для ответа на запрос. "
        "Верни ТОЛЬКО список ключей через запятую, без пояснений. "
        f"Максимум {min(3, len(available))} эксперта.\n\n"
        f"Запрос: {message}"
    )
    try:
        async with httpx.AsyncClient(timeout=60) as client:
            r = await client.post(f"{MODEL_URL}/v1/chat/completions", json={
                "model": "qwen3-4b",
                "messages": [{"role": "user", "content": prompt}],
                "max_tokens": 50,
                "temperature": 0.1,
                "chat_template_kwargs": {"enable_thinking": False}
            })
            r.raise_for_status()
            content = r.json()["choices"][0]["message"]["content"].strip().lower()
            found = [k for k in available if k in content]
            return found if found else [available[0]]
    except Exception:
        return [available[0]] if available else []


async def route(
    message: str,
    explicit_expert: Optional[str] = None,
    explicit_experts: Optional[List[str]] = None,
) -> Dict[str, Any]:
    """
    Возвращает:
    {
      experts_requested: [...] | None,
      experts_used: [...],
      experts_skipped: [{key, reason}, ...],
      reason: str,
      method: "manual" | "explicit" | "keyword" | "llm" | "fallback" | "chitchat"
    }
    """
    # Загружаем активных экспертов из БД
    active = await experts_service.load_all(force=False)
    active_keys = [e["key"] for e in active]

    if not active_keys:
        return {
            "experts_requested": None,
            "experts_used": [],
            "experts_skipped": [],
            "reason": "Нет активных экспертов",
            "method": "error"
        }

    # 1. Manual list (наивысший приоритет)
    if explicit_experts:
        # Уникализация
        seen = set()
        unique = []
        for k in explicit_experts:
            if k not in seen:
                seen.add(k)
                unique.append(k)
        # Ограничение
        unique = unique[:MAX_EXPERTS_PER_REQUEST]

        used = []
        skipped = []
        for k in unique:
            if k not in active_keys:
                # Проверим, существует ли вообще в БД (отключён vs неизвестен)
                all_exp = await experts_service.load_all_including_disabled(force=True)
                all_keys = [e["key"] for e in all_exp]
                reason = "disabled" if k in all_keys else "unknown"
                skipped.append({"key": k, "reason": reason})
            else:
                used.append(k)

        if not used:
            return {
                "experts_requested": explicit_experts,
                "experts_used": [],
                "experts_skipped": skipped,
                "reason": "Все указанные эксперты недоступны",
                "method": "manual"
            }

        return {
            "experts_requested": explicit_experts,
            "experts_used": used,
            "experts_skipped": skipped,
            "reason": f"Ручной выбор: {', '.join(used)}",
            "method": "manual"
        }

    # 2. Single expert
    if explicit_expert:
        if explicit_expert in active_keys:
            return {
                "experts_requested": [explicit_expert],
                "experts_used": [explicit_expert],
                "experts_skipped": [],
                "reason": f"Явно выбран: {explicit_expert}",
                "method": "explicit"
            }
        return {
            "experts_requested": [explicit_expert],
            "experts_used": [],
            "experts_skipped": [{"key": explicit_expert, "reason": "unknown_or_disabled"}],
            "reason": f"Эксперт '{explicit_expert}' недоступен",
            "method": "explicit"
        }

    # 3. Chitchat
    if _is_chitchat(message):
        default = "system_architect" if "system_architect" in active_keys else active_keys[0]
        return {
            "experts_requested": None,
            "experts_used": [default],
            "experts_skipped": [],
            "reason": "Общий вопрос",
            "method": "chitchat"
        }

    # 4. Keyword match
    keywords_map = await experts_service.get_keywords()
    matched = _keyword_match(message, keywords_map)
    if matched:
        matched = matched[:MAX_EXPERTS_PER_REQUEST]
        return {
            "experts_requested": None,
            "experts_used": matched,
            "experts_skipped": [],
            "reason": f"Ключевые слова: {', '.join(matched)}",
            "method": "keyword"
        }

    # 5. LLM classify
    llm_matched = await _llm_classify(message, active_keys)
    return {
        "experts_requested": None,
        "experts_used": llm_matched[:MAX_EXPERTS_PER_REQUEST],
        "experts_skipped": [],
        "reason": "Классификация через LLM",
        "method": "llm"
    }


# ─────────────────────────────────────────────────────────────
# EXECUTOR
# ─────────────────────────────────────────────────────────────

async def _call_expert(
    expert_key: str,
    system_prompt: str,
    message: str,
    thinking: bool,
    max_tokens: int,
    rag_context: Optional[str] = None
) -> Dict[str, Any]:
    # Если есть RAG-контекст — добавляем к system_prompt
    full_system = system_prompt
    if rag_context:
        full_system = system_prompt + "\n\n" + rag_context

    payload = {
        "model": "qwen3-4b",
        "messages": [
            {"role": "system", "content": full_system},
            {"role": "user", "content": message}
        ],
        "max_tokens": max_tokens,
        "temperature": 0.6,
        "top_p": 0.9,
        "chat_template_kwargs": {"enable_thinking": thinking}
    }
    try:
        async with httpx.AsyncClient(timeout=EXPERT_TIMEOUT) as client:
            r = await client.post(f"{MODEL_URL}/v1/chat/completions", json=payload)
            r.raise_for_status()
            data = r.json()
            msg = data["choices"][0]["message"]
            return {
                "expert": expert_key,
                "ok": True,
                "content": msg.get("content", ""),
                "reasoning": msg.get("reasoning_content", ""),
                "timings": data.get("timings", {})
            }
    except httpx.ReadTimeout:
        return {"expert": expert_key, "ok": False, "error": "timeout"}
    except httpx.HTTPStatusError as e:
        return {"expert": expert_key, "ok": False, "error": f"HTTP {e.response.status_code}"}
    except Exception as e:
        return {"expert": expert_key, "ok": False, "error": str(e)}


async def execute(
    experts: List[str],
    prompts: Dict[str, str],
    message: str,
    thinking: bool,
    max_tokens: int = ORCH_MAX_TOKENS,
    rag_context: Optional[str] = None
) -> List[Dict[str, Any]]:
    tasks = [
        _call_expert(e, prompts[e], message, thinking, max_tokens, rag_context)
        for e in experts if e in prompts
    ]
    return await asyncio.gather(*tasks, return_exceptions=False)


# ─────────────────────────────────────────────────────────────
# AGGREGATOR
# ─────────────────────────────────────────────────────────────

def _get_title_and_icon(expert_meta: Dict[str, Any], key: str) -> str:
    if expert_meta and expert_meta.get("name"):
        icon = expert_meta.get("icon", "")
        return f"{icon} {expert_meta['name']}".strip()
    return key


async def aggregate_programmatic(
    results: List[Dict[str, Any]],
    experts_meta: Dict[str, Dict[str, Any]] = None
) -> str:
    experts_meta = experts_meta or {}
    ok = [r for r in results if r.get("ok")]
    failed = [r for r in results if not r.get("ok")]

    if not ok:
        return "⚠️ Ни один эксперт не смог ответить."

    parts = []
    if len(ok) > 1:
        parts.append("## Совет экспертов NEXUS AI\n")
        parts.append(f"*Всего опрошено: {len(ok)} из {len(results)}*\n")

    for r in ok:
        title = _get_title_and_icon(experts_meta.get(r["expert"]), r["expert"])
        parts.append(f"\n### {title}\n")
        parts.append(r["content"].strip())
        parts.append("")

    if failed:
        parts.append("\n---\n**Не ответили:**")
        for r in failed:
            title = _get_title_and_icon(experts_meta.get(r["expert"]), r["expert"])
            parts.append(f"- {title}: {r.get('error', 'ошибка')}")

    return "\n".join(parts)


async def aggregate_llm(
    results: List[Dict[str, Any]],
    original_query: str,
    experts_meta: Dict[str, Dict[str, Any]] = None
) -> str:
    experts_meta = experts_meta or {}
    ok = [r for r in results if r.get("ok")]
    if len(ok) <= 1:
        return await aggregate_programmatic(results, experts_meta)

    MAX_PER_EXPERT = 800
    summaries = []
    for r in ok:
        title = _get_title_and_icon(experts_meta.get(r["expert"]), r["expert"])
        text = r["content"][:MAX_PER_EXPERT]
        if len(r["content"]) > MAX_PER_EXPERT:
            text += "..."
        summaries.append(f"### {title}\n{text}")

    agg_prompt = (
        "Ты — координатор совета экспертов NEXUS AI. "
        "Ниже — заключения экспертов по одному вопросу. "
        "Собери единый итоговый ответ: выдели главное, отметь противоречия, "
        "не выдумывай новых фактов. Отвечай на русском.\n\n"
        f"ВОПРОС: {original_query}\n\n"
        "ЗАКЛЮЧЕНИЯ:\n\n" + "\n\n".join(summaries)
    )
    try:
        async with httpx.AsyncClient(timeout=600) as client:
            r = await client.post(f"{MODEL_URL}/v1/chat/completions", json={
                "model": "qwen3-4b",
                "messages": [{"role": "user", "content": agg_prompt}],
                "max_tokens": 1500,
                "temperature": 0.4,
                "chat_template_kwargs": {"enable_thinking": False}
            })
            r.raise_for_status()
            return r.json()["choices"][0]["message"]["content"]
    except Exception as e:
        fallback = await aggregate_programmatic(results, experts_meta)
        return f"⚠️ LLM-агрегатор недоступен ({e}). Программная сборка:\n\n{fallback}"
