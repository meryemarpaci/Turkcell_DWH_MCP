"""
Query plan schema and validation helpers (shared by agent docs + MCP).
"""

from __future__ import annotations

from typing import Any

from dwh_core.semantic import ALLOWED_TABLES, GRAIN_CONFLICTS, JOIN_MAP, METRICS

REQUIRED_PLAN_FIELDS = (
    "intent",
    "metrics",
    "tables",
    "joins",
    "filters",
    "grain",
    "limits",
)

VALID_INTENTS = frozenset({"aggregate", "list", "compare", "trend"})


def _edge_ids() -> set[str]:
    return {e["id"] for e in JOIN_MAP}


def _metric_ids() -> set[str]:
    return {m["id"] for m in METRICS}


def validate_query_plan(plan: dict[str, Any]) -> dict[str, Any]:
    """Return {ok, errors, warnings}."""
    errors: list[str] = []
    warnings: list[str] = []

    if not isinstance(plan, dict):
        return {"ok": False, "errors": ["Plan must be a JSON object"], "warnings": []}

    for field in REQUIRED_PLAN_FIELDS:
        if field not in plan:
            errors.append(f"Missing field: {field}")

    intent = plan.get("intent")
    if intent is not None and intent not in VALID_INTENTS:
        errors.append(f"Invalid intent: {intent}. Expected one of {sorted(VALID_INTENTS)}")

    tables = plan.get("tables") or []
    if not isinstance(tables, list) or not tables:
        errors.append("tables must be a non-empty list")
    else:
        for t in tables:
            if t not in ALLOWED_TABLES:
                errors.append(f"Unknown or disallowed table: {t}")

    joins = plan.get("joins") or []
    if not isinstance(joins, list):
        errors.append("joins must be a list of edge ids")
    else:
        known = _edge_ids()
        for j in joins:
            if j not in known:
                errors.append(f"Unknown join edge id: {j}")

    metrics = plan.get("metrics") or []
    metric_grains: list[str] = []
    if not isinstance(metrics, list):
        errors.append("metrics must be a list")
    else:
        known_m = _metric_ids()
        metric_by_id = {m["id"]: m for m in METRICS}
        for mid in metrics:
            if mid not in known_m:
                errors.append(f"Unknown metric id: {mid}")
            else:
                metric_grains.append(metric_by_id[mid]["grain"])

    grain = plan.get("grain")
    if grain and metric_grains:
        for g in metric_grains:
            if g != grain and {g, grain} != {"order", "order_item"}:
                warnings.append(
                    f"Plan grain '{grain}' differs from metric grain '{g}'"
                )

    for g1, g2, msg in GRAIN_CONFLICTS:
        if g1 in metric_grains and g2 in metric_grains:
            errors.append(msg)

    filters = plan.get("filters") or []
    if not isinstance(filters, list):
        errors.append("filters must be a list")
    else:
        for f in filters:
            if not isinstance(f, dict):
                errors.append("Each filter must be an object")
                continue
            for key in ("column", "op", "source_table"):
                if key not in f:
                    errors.append(f"Filter missing '{key}'")
            src = f.get("source_table")
            if src and src not in ALLOWED_TABLES:
                errors.append(f"Filter source_table not allowed: {src}")
            if src and tables and src not in tables:
                errors.append(f"Filter table '{src}' not in plan.tables")

    limits = plan.get("limits") or {}
    if isinstance(limits, dict):
        max_rows = limits.get("max_rows")
        if max_rows is not None:
            try:
                if int(max_rows) <= 0 or int(max_rows) > 10000:
                    errors.append("limits.max_rows must be between 1 and 10000")
            except (TypeError, ValueError):
                errors.append("limits.max_rows must be an integer")
    else:
        errors.append("limits must be an object")

    open_q = plan.get("open_questions") or []
    if open_q:
        errors.append(
            "Plan has open_questions; clarify with user before execute "
            f"({open_q})"
        )

    return {"ok": len(errors) == 0, "errors": errors, "warnings": warnings}
