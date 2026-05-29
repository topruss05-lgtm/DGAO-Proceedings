# Lokale LLM-Pipeline für Autoren-Vornamen-Vervollständigung

Läuft Qwen3.5-27B-Claude-4.6-Opus-Distilled (MLX 4bit) auf Apple Silicon.
Verbraucht keine Cloud-Tokens — alles lokal.

## Voraussetzungen

- **Mac mit Apple Silicon** (M1/M2/M3/M4)
- **mind. 32 GB RAM** (16 GB nur knapp, 64 GB komfortabel)
- **Python 3.11+** und **pip**
- Ein freier Abend / eine Nacht (3500-4500 Autoren → ca. 8-12 h)

## Setup (einmalig, ~10 Min)

```bash
# 1) Python-Venv
cd /Users/tobias/Documents/VS\ Code/DGAO_Proceedings
python3 -m venv bin/local_llm/.venv
source bin/local_llm/.venv/bin/activate

# 2) MLX-LM + Requests + DuckDuckGo-Search
pip install -U pip
pip install 'mlx-lm>=0.20' requests ddgs

# 3) Modell ziehen (~15 GB Download)
# Wird beim ersten Inferenz-Lauf automatisch von HF gezogen, oder explizit:
hf download mlx-community/Qwen3.5-27B-Claude-4.6-Opus-Distilled-MLX-4bit \
    --local-dir ~/models/qwen35-27b-claude-distilled
```

## Pipeline

### Dry-Run (nur 5 Autoren, kein DB-Update)

```bash
source bin/local_llm/.venv/bin/activate
python bin/local_llm/run_autor_vornamen.py --limit 5 --dry-run
```

### Stichprobe gegen Ground Truth (20 Autoren)

```bash
python bin/local_llm/run_autor_vornamen.py --groundtruth-only
# Ergebnisse in autor_vorname_audit, Source 'qwen_local'
# Vergleich mit autor_vorname_groundtruth danach via:
python bin/local_llm/compare_groundtruth.py
```

### Vollständiger Lauf (alle Autoren)

```bash
nohup python bin/local_llm/run_autor_vornamen.py --resume \
  > bin/local_llm/run.log 2>&1 &
tail -f bin/local_llm/run.log
```

- `--resume` skipt Autoren, die schon in `autor_vorname_audit` stehen
- Über Nacht laufen lassen — bei Crash via `--resume` weitermachen

## Architektur

```
┌─────────────────────────────────────────────────────────────┐
│ run_autor_vornamen.py                                       │
│  ├─ MLX-Model laden (Qwen3.5-27B Claude-distilled, 4bit)    │
│  ├─ SQLite-Connection (proceedings.db)                      │
│  ├─ Pro Autor (Cursor-basiert, low memory):                 │
│  │    1. Skip wenn audit_log[autor_id] existiert (resume)   │
│  │    2. Web-Suche via ddgs (DuckDuckGo, kein API-Key)      │
│  │    3. Top-3 Snippets → Prompt                            │
│  │    4. MLX-Inferenz: JSON-Output {neuer_vorname,          │
│  │       confidence, reason}                                 │
│  │    5. INSERT audit_log + UPDATE autoren (bei conf≥0.9)   │
│  └─ Throughput-Log                                          │
└─────────────────────────────────────────────────────────────┘
```

## Erwartung

- ~5-8 Sekunden pro Autor (1-2 Web-Calls + ~600 Tokens MLX-Output)
- Für 4500 Autoren: ~6-10 Stunden
- Tokens/Sec MLX 4bit auf M1 64GB: ~12-15 tok/s

## Output-Felder in `autor_vorname_audit`

| Feld | Wert |
|---|---|
| `source` | `qwen_local` |
| `confidence` | 0.0-1.0 |
| `neuer_vorname` | "Christian" / NULL |
| `reason` | LLM-Begründung mit Quelle |
