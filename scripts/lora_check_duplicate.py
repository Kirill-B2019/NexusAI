#!/usr/bin/env python3
"""
Проверка дубликатов вопросов перед генерацией.
Использование:
  python3 lora_check_duplicate.py check <expert> "<question>"
  python3 lora_check_duplicate.py add <expert> "<question>" <topic> [variant1,variant2]
  python3 lora_check_duplicate.py list [expert]
"""
import sys
import json
import re
import os
from pathlib import Path
from datetime import datetime

REGISTRY = "/opt/nexus-ai/lora/datasets/meta/registry.jsonl"


def normalize(text: str) -> str:
    text = text.lower().strip()
    text = re.sub(r'[^\w\s]', '', text)
    text = re.sub(r'\s+', ' ', text)
    return text


def load_registry():
    if not os.path.exists(REGISTRY):
        return []
    records = []
    with open(REGISTRY, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line:
                records.append(json.loads(line))
    return records


def check_duplicate(expert: str, question: str, threshold: float = 0.75):
    records = load_registry()
    norm_q = normalize(question)
    words_q = set(norm_q.split())

    for rec in records:
        if rec["expert"] != expert:
            continue

        norm_r = normalize(rec["text"])
        words_r = set(norm_r.split())

        if norm_q == norm_r:
            return True, 1.0, rec

        if not words_q or not words_r:
            continue
        intersection = len(words_q & words_r)
        union = len(words_q | words_r)
        similarity = intersection / union if union > 0 else 0

        if similarity >= threshold:
            return True, similarity, rec

    return False, 0.0, None


def add_to_registry(expert: str, question: str, topic: str, variants: list):
    records = load_registry()
    expert_records = [r for r in records if r["expert"] == expert]
    next_num = len(expert_records) + 1

    record_id = f"{expert}_q_{next_num:03d}"

    entry = {
        "id": record_id,
        "expert": expert,
        "text": question,
        "topic": topic,
        "variants": variants,
        "asked_at": datetime.utcnow().isoformat() + "Z",
    }

    with open(REGISTRY, "a", encoding="utf-8") as f:
        f.write(json.dumps(entry, ensure_ascii=False) + "\n")

    return record_id


def list_records(expert: str = None):
    records = load_registry()
    if expert:
        records = [r for r in records if r["expert"] == expert]
    print(f"Всего: {len(records)}")
    for r in records:
        print(f"  [{r['id']}] {r['topic']:20s} {r['text'][:60]}")


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)

    cmd = sys.argv[1]

    if cmd == "check":
        if len(sys.argv) < 4:
            print("Использование: check <expert> <question>")
            sys.exit(1)
        expert = sys.argv[2]
        question = " ".join(sys.argv[3:])
        is_dup, sim, matched = check_duplicate(expert, question)
        if is_dup:
            print(f"❌ ДУБЛИКАТ (similarity={sim:.2f})")
            print(f"   Совпадает с: {matched['id']} — {matched['text'][:100]}")
            sys.exit(1)
        else:
            print(f"✅ Уникальный вопрос")
            sys.exit(0)

    elif cmd == "add":
        if len(sys.argv) < 5:
            print("Использование: add <expert> <question> <topic> [variant1,variant2]")
            sys.exit(1)
        expert = sys.argv[2]
        question = sys.argv[3]
        topic = sys.argv[4]
        variants = sys.argv[5].split(",") if len(sys.argv) > 5 else []
        record_id = add_to_registry(expert, question, topic, variants)
        print(f"✓ Добавлено: {record_id}")

    elif cmd == "list":
        expert = sys.argv[2] if len(sys.argv) > 2 else None
        list_records(expert)

    else:
        print(f"Неизвестная команда: {cmd}")
        print(__doc__)
        sys.exit(1)
