# dwh-mcp-agent

A natural-language data warehouse analyst built on PHP, Gemini, and the Model Context Protocol (MCP). Users ask questions in plain language; the agent resolves the right metrics, constructs safe full-data SQL through a semantic join graph, and returns compact analytical summaries — without the LLM ever writing or seeing raw SQL rows.

---

## How it works

```
Browser (chat UI)
  → PHP agent API  (port 8080)
    → AgentOrchestrator + Gemini
      → MCP tool call  (port 8081)
        → AnalyticsTool: parametric SQL over full dataset
          → SQLite DWH
            → compact summary → Gemini → Turkish answer
```

The LLM selects **what to measure** (metric IDs, dimension IDs, filters).  
The PHP layer decides **how to compute it** (join path, fan-out guard, full-data scan).

---

## Key design decisions

| Concern | Approach |
|---------|----------|
| No raw SQL from LLM | Parametric `analyze_*` tools; SQL built server-side |
| 1:N fan-out safety | EXISTS semi-joins when filter tables expand grain |
| Full-data guarantee | `analyze_*` always scans full filtered warehouse; no LIMIT on facts |
| Join graph | Seeded from profile JSON; enriched by `JoinGraphBuilder` (FK + name match + probe) |
| Semantic registry | Metric / dimension IDs with physical expressions; persisted in SQLite |
| Security | SELECT-only `SqlGuard`; allowlisted tables; no raw SQL tool in agent surface |

---

## Requirements

- PHP 8.2+ with `pdo_sqlite` and `curl`
- Python 3.10+ (for building the SQLite DWH from CSVs)
- A Gemini API key (`gemini-3-flash-preview` or later)
- Optionally: a Groq API key for the alternative LLM backend

---

## Quick start

### 1. Clone and configure

```bash
git clone https://github.com/meryemarpaci/dwh_mcp_agent.git
cd dwh_mcp_agent
cp .env.example .env
```

Edit `.env` — at minimum:

```
GEMINI_API_KEY=your_key_here
GEMINI_MODEL=gemini-3-flash-preview
DWH_PROFILE=olist
DWH_SQLITE_PATH=Data/olist_dwh.sqlite
```

### 2. Build the SQLite warehouse

Download the [Olist Brazilian E-Commerce dataset](https://www.kaggle.com/datasets/olistbr/brazilian-ecommerce) CSVs into `Data/`, then:

```bash
pip install -r requirements.txt
python scripts/build_sqlite_dwh.py
```

### 3. Start the servers

```bash
# Terminal 1 — chat UI and agent API
php -S localhost:8080 router.php

# Terminal 2 — MCP tool server
php -S localhost:8081 mcp_router.php
```

Open **http://localhost:8080** and start asking questions.

---

## Bringing your own dataset

1. Put your SQLite database in `Data/`.
2. Copy `php/config/profiles/_template.json` → `php/config/profiles/<your_id>.json`.
3. Fill in `allowed_tables`, `joins`, `metrics`, and `dimensions`.
4. Set `DWH_PROFILE=<your_id>` and `DWH_SQLITE_PATH=Data/<your_file>.sqlite` in `.env`.

No PHP code changes needed — the entire behavior is driven by the profile.

---

## Architecture overview

```
php/
  AgentOrchestrator.php     # tool-calling loop (max 8 steps)
  GeminiEngine.php          # Gemini API client
  GroqEngine.php            # Groq alternative backend
  Mcp/
    McpServer.php           # MCP Streamable HTTP transport
    McpClient.php           # HTTP client used by orchestrator
    DwhToolRegistry.php     # maps tool names → PHP handlers
  Tools/
    AnalyticsTool.php       # parametric SQL builder + full-data runner
    ReportTool.php          # SQL executor
    SqlGuard.php            # SELECT-only safety + allowlist
    IndexBootstrap.php      # auto-creates indexes from join catalog
    LlmPayload.php          # compact summary formatter
  Semantic/
    RegistryService.php     # read/write metric & dimension registry
    RegistryStore.php       # SQLite-backed persistent store
    SchemaChecker.php       # schema drift detection
  Discovery/
    JoinGraphBuilder.php    # discovers & scores join edges
    QueryPlanner.php        # cross-domain path planning
    TableProfiler.php       # column/sample profiling
    EntityResolver.php      # canonical entity matching
    JoinSafetyGuard.php     # fan-out risk evaluation
    PiiGuard.php            # identity field masking in samples

api/
  chat.php                  # POST /api/chat
  mcp.php                   # POST /api/mcp  (MCP endpoint)
  schema.php                # GET  /api/schema
  profile.php               # GET  /api/profile

scripts/
  build_sqlite_dwh.py       # build Olist SQLite from CSVs
  smoke_sql.py              # raw SQL correctness checks
  smoke_mcp_api.py          # MCP HTTP endpoint smoke test
  check_analytics_tools.php # KPI / breakdown / trend smoke
  check_fanout_semijoin.php # 1:N fan-out + EXISTS correctness
  check_full_data_large.php # full-scan timing and correctness
  check_full_data_mode.php  # full-data flag verification
  check_limit_strip.php     # LIMIT removal guard
  check_registry.php        # semantic registry smoke
  check_discovery.php       # discovery + join graph smoke
  check_dims_ui.php         # dimension catalog smoke
```

---

## Running smoke tests

```bash
php scripts/check_analytics_tools.php
php scripts/check_fanout_semijoin.php
php scripts/check_registry.php
php scripts/check_discovery.php
php scripts/check_full_data_mode.php
php scripts/check_limit_strip.php
```

For large-scan timing (run separately, not on every PR):

```bash
php scripts/check_full_data_large.php
```

---

## Agent tool surface

The agent has access to three layers of tools:

**Discovery** (used when tables/joins are unknown)
- `search_tables`, `describe_table`, `find_join_path`
- `register_table_semantics`, `register_join`, `register_canonical_entity`

**Semantic registry**
- `search_metrics`, `describe_column`
- `register_metric`, `register_dimension`

**Analysis** (primary — used on every query)
- `analyze_kpi` — single-period KPI rollup
- `analyze_breakdown` — group-by analysis with optional top-N
- `analyze_trend` — time-series over date dimension
- `analyze_top_per_group` — "each X's top Y" partition ranking

---

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `GEMINI_API_KEY` | — | Required. Gemini API key. |
| `GEMINI_MODEL` | `gemini-3-flash-preview` | Primary model. |
| `GEMINI_FALLBACK_MODELS` | see `.env.example` | Comma-separated fallback cascade. |
| `GROQ_API_KEY` | — | Optional. Alternative Groq backend. |
| `GROQ_MODEL` | `llama-3.3-70b-versatile` | Groq model. |
| `DWH_PROFILE` | `olist` | Profile ID under `php/config/profiles/`. |
| `DWH_SQLITE_PATH` | `Data/olist_dwh.sqlite` | Path to SQLite file. |
| `MCP_ENDPOINT` | `http://localhost:8081/api/mcp` | MCP server URL used by orchestrator. |
| `MCP_TIMEOUT_SECONDS` | `300` | Max execution time for MCP tool calls. |
| `MCP_LOCAL_FALLBACK` | `1` | Fall back to in-process dispatch if MCP is unreachable. |
| `DWH_AGGREGATE_GROUP_CAP` | `50000` | Max groups before context cap on breakdowns. |
| `APP_DEBUG` | `1` | Enable verbose error output (set to `0` in production). |

---

## Security notes

- `.env` is git-ignored; only `.env.example` is committed.
- The SQL guard restricts all queries to SELECT and the allowlisted tables defined in the profile.
- PII fields (e.g. identity columns) are masked in discovery samples.
- For production: add HTTPS, rate limiting, and proper key management.

---

## License

Private / internal use — subject to the repository owner's policy.
