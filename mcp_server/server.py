"""
DWH MCP server — SQLite-backed tools for the prompt-based analyst agent.

Run from repo root:
  python -m mcp_server.server
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from mcp.server.fastmcp import FastMCP

from dwh_core.db import execute_query, get_schema, run_report, validate_sql
from dwh_core.query_plan import validate_query_plan
from dwh_core.semantic import JOIN_MAP, METRICS

mcp = FastMCP(
    "olist-dwh",
    instructions=(
        "Olist SQLite DWH tools. Always validate_query_plan / validate_sql "
        "before execute_query or run_report. Prefer run_report for analytics."
    ),
)


@mcp.tool(name="get_schema")
def tool_get_schema() -> str:
    """List DWH tables, columns, types, and row counts."""
    return json.dumps(get_schema(), ensure_ascii=False, indent=2)


@mcp.tool(name="get_join_map")
def tool_get_join_map() -> str:
    """Return allowed join edges (use edge ids in query plans)."""
    return json.dumps({"joins": JOIN_MAP}, ensure_ascii=False, indent=2)


@mcp.tool(name="get_metrics")
def tool_get_metrics() -> str:
    """Return defined business metrics (use metric ids in query plans)."""
    return json.dumps({"metrics": METRICS}, ensure_ascii=False, indent=2)


@mcp.tool(name="validate_query_plan")
def tool_validate_query_plan(plan_json: str) -> str:
    """
    Validate a Query Plan JSON string before compiling SQL.

    Args:
        plan_json: JSON object with intent, metrics, tables, joins, filters, grain, limits.
    """
    try:
        plan = json.loads(plan_json)
    except json.JSONDecodeError as exc:
        return json.dumps(
            {"ok": False, "errors": [f"Invalid JSON: {exc}"], "warnings": []},
            ensure_ascii=False,
            indent=2,
        )
    return json.dumps(validate_query_plan(plan), ensure_ascii=False, indent=2)


@mcp.tool(name="validate_sql")
def tool_validate_sql(sql: str) -> str:
    """Validate SQL safety (SELECT-only, allowlisted tables)."""
    return json.dumps(validate_sql(sql), ensure_ascii=False, indent=2)


@mcp.tool(name="execute_query")
def tool_execute_query(sql: str, max_rows: int = 200) -> str:
    """
    Execute a validated SELECT against the SQLite DWH (read-only, row-capped).

    Args:
        sql: SELECT or WITH...SELECT statement
        max_rows: max rows to return (1-10000)
    """
    return json.dumps(
        execute_query(sql, max_rows=max_rows),
        ensure_ascii=False,
        indent=2,
        default=str,
    )


@mcp.tool(name="run_report")
def tool_run_report(sql: str, max_rows: int = 500) -> str:
    """
    Run an aggregate/report SELECT; returns preview + numeric stats.

    Args:
        sql: SELECT or WITH...SELECT statement
        max_rows: max rows to return (1-10000)
    """
    return json.dumps(
        run_report(sql, max_rows=max_rows),
        ensure_ascii=False,
        indent=2,
        default=str,
    )


def main() -> None:
    mcp.run()


if __name__ == "__main__":
    main()
