#!/usr/bin/env python3
"""Отчёт о покрытии тем датасетов."""
import json
from pathlib import Path

REGISTRY = "/opt/nexus-ai/lora/datasets/meta/registry.jsonl"
TOPICS = "/opt/nexus-ai/lora/datasets/meta/topics.json"


def main():
    if not Path(TOPICS).exists():
        print(f"⚠️  Файл {TOPICS} не найден")
        return

    with open(TOPICS, encoding="utf-8") as f:
        topics = json.load(f)

    counts = {}
    if Path(REGISTRY).exists():
        with open(REGISTRY, encoding="utf-8") as f:
            for line in f:
                if line.strip():
                    rec = json.loads(line)
                    key = (rec["expert"], rec.get("topic", "unknown"))
                    counts[key] = counts.get(key, 0) + 1

    for expert, topic_map in topics.items():
        print(f"\n=== {expert} ===")
        total_asked = 0
        total_target = 0
        for topic, cfg in topic_map.items():
            asked = counts.get((expert, topic), 0)
            target = cfg["target"]
            total_asked += asked
            total_target += target
            filled = min(asked, target)
            bar = "█" * filled + "░" * max(0, target - filled)
            status = "✅" if asked >= target else "⏳"
            print(f"  {status} {topic:22s} {bar} {asked}/{target}")
        print(f"  ИТОГО: {total_asked}/{total_target}")


if __name__ == "__main__":
    main()
