# Ручные датасеты NEXUS AI

Каждый файл — 300+ пар для LoRA-обучения.

## Формат (JSONL)

Одна строка — один JSON:

    {"messages":[{"role":"system","content":"..."},{"role":"user","content":"..."},{"role":"assistant","content":"..."}],"metadata":{"expert":"...","source":"manual","variant":"N"}}

## Файлы (будут созданы)

- system_architect.jsonl      300 пар
- software_engineer.jsonl     300 пар
- fintech.jsonl               300 пар
- digital_law.jsonl           300 пар
- project_scoring.jsonl       300 пар
- investment_advisor.jsonl    300 пар

## Как валидировать

    python3 -c "
    import json, sys
    with open('system_architect.jsonl') as f:
        lines = f.readlines()
    print(f'Строк: {len(lines)}')
    for i, line in enumerate(lines[:3], 1):
        obj = json.loads(line)
        assert 'messages' in obj
        assert len(obj['messages']) == 3
        assert obj['messages'][0]['role'] == 'system'
        assert obj['messages'][1]['role'] == 'user'
        assert obj['messages'][2]['role'] == 'assistant'
        print(f'  {i}: OK ({len(obj[\"messages\"][2][\"content\"])} символов)')
    print('✓ Формат валиден')
    "

## Как объединять пачки

Из чата вы копируете пачки в отдельные файлы:

    # В редакторе nano
    nano /tmp/pack_01.jsonl
    # Вставляете содержимое, сохраняете

    # Добавляете к датасету
    cat /tmp/pack_01.jsonl >> /opt/nexus-ai/lora/datasets/manual/system_architect.jsonl
