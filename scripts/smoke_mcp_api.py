"""Smoke-test MCP-facing Python APIs without starting stdio transport."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from dwh_core.db import execute_query, get_schema, run_report, validate_sql
from dwh_core.query_plan import validate_query_plan


def main() -> int:
    schema = get_schema()
    assert schema["ok"], schema
    assert "fact_orders" in schema["tables"]
    print("get_schema: OK", len(schema["tables"]), "tables")

    plan = {
        "intent": "aggregate",
        "metrics": ["gmv", "order_count"],
        "tables": ["fact_orders", "fact_order_items", "dim_customer"],
        "joins": ["items_orders", "orders_customer"],
        "filters": [
            {
                "column": "customer_state",
                "op": "=",
                "value": "SP",
                "source_table": "dim_customer",
            }
        ],
        "grain": "order_item",
        "limits": {"max_rows": 100},
        "open_questions": [],
        "assumptions": ["GMV = sum of item price"],
        "confidence": 0.9,
    }
    v = validate_query_plan(plan)
    assert v["ok"], v
    print("validate_query_plan: OK")

    bad = validate_query_plan({**plan, "joins": ["no_such_edge"]})
    assert not bad["ok"]
    print("validate_query_plan rejects bad join: OK")

    sql = """
    SELECT c.customer_state,
           COUNT(DISTINCT o.order_id) AS order_count,
           ROUND(SUM(i.price), 2) AS gmv
    FROM fact_orders o
    JOIN dim_customer c ON c.customer_id = o.customer_id
    JOIN fact_order_items i ON i.order_id = o.order_id
    WHERE c.customer_state = 'SP'
    GROUP BY c.customer_state
    """
    vs = validate_sql(sql)
    assert vs["ok"], vs
    print("validate_sql: OK")

    blocked = validate_sql("DELETE FROM fact_orders")
    assert not blocked["ok"]
    print("validate_sql blocks DELETE: OK")

    report = run_report(sql)
    assert report["ok"], report
    print("run_report:", json.dumps(report["preview"], ensure_ascii=False))

    rows = execute_query(
        "SELECT order_id, order_status FROM fact_orders LIMIT 3", max_rows=3
    )
    assert rows["ok"] and rows["row_count"] == 3
    print("execute_query: OK")

    print("\nAll MCP API smoke tests passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
