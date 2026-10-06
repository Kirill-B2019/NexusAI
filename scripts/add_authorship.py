#!/usr/bin/env python3
"""
Добавляет авторство внизу всех .md файлов в /opt/nexus-ai/docs/.
Идемпотентно: не дублирует, если уже есть.
"""
import os
import re
from pathlib import Path

DOCS_DIR = Path("/opt/nexus-ai/docs")
FOOTER_MARKER = "| KB @CerberRus00 - Nexus Invest Team"
FOOTER_LINE = f"\n\n---\n\n{FOOTER_MARKER}\n"


def has_footer(content: str) -> bool:
    """Проверяет, есть ли уже футер."""
    return FOOTER_MARKER in content


def add_footer(path: Path) -> bool:
    """Добавляет футер. Возвращает True, если файл изменён."""
    with open(path, "r", encoding="utf-8") as f:
        content = f.read()

    if has_footer(content):
        return False

    # Убираем лишние переносы в конце
    content = content.rstrip() + FOOTER_LINE

    with open(path, "w", encoding="utf-8") as f:
        f.write(content)

    return True


def main():
    changed = []
    skipped = []

    # Обходим все .md рекурсивно
    for md_file in sorted(DOCS_DIR.rglob("*.md")):
        if add_footer(md_file):
            changed.append(md_file.relative_to(DOCS_DIR))
        else:
            skipped.append(md_file.relative_to(DOCS_DIR))

    print("═" * 60)
    print(f"Обновлено: {len(changed)} файлов")
    for f in changed:
        print(f"  ✓ {f}")

    print()
    print(f"Пропущено (уже есть футер): {len(skipped)} файлов")
    for f in skipped:
        print(f"  · {f}")

    print("═" * 60)


if __name__ == "__main__":
    main()
