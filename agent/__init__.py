"""
Agent architecture notes and helpers (prompt-based; no orchestration framework).
"""

from __future__ import annotations

from pathlib import Path

AGENT_DIR = Path(__file__).resolve().parent
SYSTEM_PROMPT_PATH = AGENT_DIR / "SYSTEM_PROMPT.md"
QUERY_PLAN_SCHEMA_PATH = AGENT_DIR / "query_plan.schema.json"

# Ordered tool contract the prompt-based agent must follow
TOOL_PIPELINE = [
    "get_schema",
    "get_join_map",
    "get_metrics",
    "validate_query_plan",
    "validate_sql",
    "run_report",  # preferred
    "execute_query",  # fallback for small lists
]

MEMORY_POLICY = {
    "keep": [
        "last validated query plan",
        "last SQL",
        "user clarifications (date range, metric choice)",
    ],
    "drop": [
        "raw row dumps larger than summary",
        "intermediate failed SQL unless debugging with user",
    ],
}


def load_system_prompt() -> str:
    return SYSTEM_PROMPT_PATH.read_text(encoding="utf-8")
