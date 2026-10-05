#!/usr/bin/env python3
"""
Разделяет склеенные JSON-объекты на отдельные строки JSONL.
Использование:
  python3 lora_split_glued.py <input_file>
Выводит результат в stdout.
"""
import sys
import json


def extract_objects(text: str):
    """Извлекает все JSON-объекты верхнего уровня из строки."""
    decoder = json.JSONDecoder()
    idx = 0
    n = len(text)
    objects = []

    while idx < n:
        # Пропускаем пробелы, переносы, запятые
        while idx < n and text[idx] in ' \t\n\r,':
            idx += 1
        if idx >= n:
            break

        try:
            obj, end = decoder.raw_decode(text, idx)
            objects.append(obj)
            idx = end
        except json.JSONDecodeError:
            # Ищем следующий {
            next_brace = text.find('{', idx + 1)
            if next_brace == -1:
                break
            idx = next_brace

    return objects


def main():
    if len(sys.argv) < 2:
        print("Использование: lora_split_glued.py <input_file>", file=sys.stderr)
        sys.exit(1)

    with open(sys.argv[1], encoding="utf-8") as f:
        text = f.read()

    objects = extract_objects(text)

    print(f"Извлечено объектов: {len(objects)}", file=sys.stderr)

    for obj in objects:
        print(json.dumps(obj, ensure_ascii=False))


if __name__ == "__main__":
    main()
