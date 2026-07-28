"""
SQLite execution + SQL safety checks for MCP tools.
"""

from __future__ import annotations

import re
import sqlite3
from typing import Any

from dwh_core.semantic import ALLOWED_TABLES, DB_PATH

FORBIDDEN_SQL = re.compile(
    r"\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|REPLACE|TRUNCATE|ATTACH|"
    r"DETACH|PRAGMA|VACUUM|REINDEX|GRANT|REVOKE|INTO)\b",
    re.IGNORECASE,
)

DEFAULT_MAX_ROWS = 200
HARD_MAX_ROWS = 10000


def connect() -> sqlite3.Connection:
    if not DB_PATH.exists():
        raise FileNotFoundError(
            f"DWH not found: {DB_PATH}. Run scripts/build_sqlite_dwh.py first."
        )
    conn = sqlite3.connect(f"file:{DB_PATH}?mode=ro", uri=True)
    conn.row_factory = sqlite3.Row
    return conn


def validate_sql(sql: str) -> dict[str, Any]:
    errors: list[str] = []
    warnings: list[str] = []
    text = (sql or "").strip()
    if not text:
        return {"ok": False, "errors": ["SQL is empty"], "warnings": []}

    # Strip trailing semicolons; reject multi-statement
    parts = [p.strip() for p in text.split(";") if p.strip()]
    if len(parts) > 1:
        errors.append("Only a single SQL statement is allowed")
    statement = parts[0] if parts else text

    if not re.match(r"^(WITH|SELECT)\b", statement, re.IGNORECASE):
        errors.append("Only SELECT / WITH…SELECT queries are allowed")

    if FORBIDDEN_SQL.search(statement):
        errors.append("Forbidden keyword detected (DDL/DML/PRAGMA/etc.)")

    # Soft table allowlist: referenced names that look like our tables
    for table in ALLOWED_TABLES:
        # no-op; used below
        pass
    referenced = set(
        re.findall(
            r"\b(dim_customer|dim_product|dim_seller|dim_geolocation|"
            r"fact_orders|fact_order_items|fact_order_payments|fact_order_reviews)\b",
            statement,
            flags=re.IGNORECASE,
        )
    )
    referenced = {t.lower() for t in referenced}
    unknownish = referenced - {t.lower() for t in ALLOWED_TABLES}
    if referenced and unknownish:
        errors.append(f"Disallowed tables referenced: {sorted(unknownish)}")

    if not referenced:
        warnings.append("No known DWH tables detected in SQL")

    if not re.search(r"\bLIMIT\b", statement, re.IGNORECASE):
        if re.search(r"\bGROUP\s+BY\b", statement, re.IGNORECASE):
            warnings.append("No LIMIT on aggregate query (usually OK)")
        else:
            warnings.append("No LIMIT clause; executor will enforce a row cap")

    return {"ok": len(errors) == 0, "errors": errors, "warnings": warnings}


def _clamp_limit(max_rows: int | None) -> int:
    n = DEFAULT_MAX_ROWS if max_rows is None else int(max_rows)
    return max(1, min(n, HARD_MAX_ROWS))


def execute_query(sql: str, max_rows: int | None = None) -> dict[str, Any]:
    check = validate_sql(sql)
    if not check["ok"]:
        return {"ok": False, "errors": check["errors"], "rows": [], "columns": []}

    limit = _clamp_limit(max_rows)
    statement = sql.strip().rstrip(";")
    # Wrap to enforce limit without breaking aggregates that already limit
    wrapped = f"SELECT * FROM ({statement}) AS _q LIMIT {limit}"

    conn = connect()
    try:
        cur = conn.execute(wrapped)
        rows = cur.fetchall()
        columns = [d[0] for d in cur.description] if cur.description else []
        data = [dict(zip(columns, row)) for row in rows]
        return {
            "ok": True,
            "columns": columns,
            "rows": data,
            "row_count": len(data),
            "truncated": len(data) >= limit,
            "max_rows": limit,
            "warnings": check.get("warnings", []),
        }
    except sqlite3.Error as exc:
        return {"ok": False, "errors": [str(exc)], "rows": [], "columns": []}
    finally:
        conn.close()


def get_schema() -> dict[str, Any]:
    conn = connect()
    try:
        tables: dict[str, Any] = {}
        for name in sorted(ALLOWED_TABLES):
            cols = conn.execute(f"PRAGMA table_info({name})").fetchall()
            count = conn.execute(f"SELECT COUNT(*) AS n FROM {name}").fetchone()["n"]
            tables[name] = {
                "row_count": count,
                "columns": [
                    {
                        "name": c["name"],
                        "type": c["type"],
                        "nullable": not c["notnull"],
                        "pk": bool(c["pk"]),
                    }
                    for c in cols
                ],
            }
        return {"ok": True, "database": str(DB_PATH), "tables": tables}
    finally:
        conn.close()


def run_report(sql: str, max_rows: int | None = 500) -> dict[str, Any]:
    """Execute aggregate/report SQL and return a compact summary payload."""
    result = execute_query(sql, max_rows=max_rows)
    if not result.get("ok"):
        return result
    rows = result["rows"]
    summary: dict[str, Any] = {
        "ok": True,
        "row_count": result["row_count"],
        "truncated": result.get("truncated", False),
        "columns": result["columns"],
        "preview": rows[:50],
        "warnings": result.get("warnings", []),
    }
    # Numeric column quick stats on preview
    for col in result["columns"]:
        nums = [r[col] for r in rows if isinstance(r.get(col), (int, float))]
        if nums:
            summary.setdefault("numeric_stats", {})[col] = {
                "min": min(nums),
                "max": max(nums),
                "sum": sum(nums),
                "avg": sum(nums) / len(nums),
            }
    return summary
