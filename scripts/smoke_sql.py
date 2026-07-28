"""
Smoke SQL tests against Data/olist_dwh.sqlite.
Run after scripts/build_sqlite_dwh.py.
"""

from __future__ import annotations

import sqlite3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "Data" / "olist_dwh.sqlite"

SMOKE_QUERIES: list[tuple[str, str]] = [
    (
        "monthly_orders_and_gmv",
        """
        SELECT strftime('%Y-%m', o.order_purchase_timestamp) AS month,
               COUNT(DISTINCT o.order_id) AS order_count,
               ROUND(SUM(i.price), 2) AS gmv
        FROM fact_orders o
        JOIN fact_order_items i ON i.order_id = o.order_id
        WHERE o.order_status = 'delivered'
        GROUP BY 1
        ORDER BY 1
        LIMIT 5
        """,
    ),
    (
        "orders_by_customer_state",
        """
        SELECT c.customer_state,
               COUNT(DISTINCT o.order_id) AS orders
        FROM fact_orders o
        JOIN dim_customer c ON c.customer_id = o.customer_id
        GROUP BY c.customer_state
        ORDER BY orders DESC
        LIMIT 5
        """,
    ),
    (
        "payment_type_distribution",
        """
        SELECT payment_type,
               COUNT(*) AS payment_count,
               ROUND(SUM(payment_value), 2) AS total_value
        FROM fact_order_payments
        GROUP BY payment_type
        ORDER BY total_value DESC
        """,
    ),
    (
        "sales_by_category_en",
        """
        SELECT COALESCE(p.category_name_english, 'unknown') AS category,
               COUNT(*) AS item_count,
               ROUND(SUM(i.price), 2) AS revenue
        FROM fact_order_items i
        JOIN dim_product p ON p.product_id = i.product_id
        GROUP BY 1
        ORDER BY revenue DESC
        LIMIT 5
        """,
    ),
    (
        "avg_delivery_days",
        """
        SELECT ROUND(
                 AVG(
                   julianday(order_delivered_customer_date)
                   - julianday(order_purchase_timestamp)
                 ),
                 2
               ) AS avg_delivery_days,
               COUNT(*) AS delivered_orders
        FROM fact_orders
        WHERE order_status = 'delivered'
          AND order_delivered_customer_date IS NOT NULL
          AND order_purchase_timestamp IS NOT NULL
        """,
    ),
    (
        "top_sellers_by_revenue",
        """
        SELECT s.seller_id, s.seller_state,
               ROUND(SUM(i.price), 2) AS revenue,
               COUNT(*) AS items_sold
        FROM fact_order_items i
        JOIN dim_seller s ON s.seller_id = i.seller_id
        GROUP BY s.seller_id, s.seller_state
        ORDER BY revenue DESC
        LIMIT 5
        """,
    ),
    (
        "review_score_avg_by_month",
        """
        SELECT strftime('%Y-%m', o.order_purchase_timestamp) AS month,
               ROUND(AVG(r.review_score), 2) AS avg_score,
               COUNT(*) AS reviews
        FROM fact_order_reviews r
        JOIN fact_orders o ON o.order_id = r.order_id
        GROUP BY 1
        ORDER BY 1
        LIMIT 5
        """,
    ),
    (
        "customer_geo_sample",
        """
        SELECT c.customer_city, g.geolocation_lat, g.geolocation_lng, COUNT(*) AS n
        FROM dim_customer c
        JOIN dim_geolocation g ON g.zip_code_prefix = c.customer_zip_code_prefix
        GROUP BY c.customer_city, g.geolocation_lat, g.geolocation_lng
        ORDER BY n DESC
        LIMIT 3
        """,
    ),
]


def main() -> int:
    if not DB_PATH.exists():
        print(f"DB missing: {DB_PATH}", file=sys.stderr)
        print("Run: python scripts/build_sqlite_dwh.py", file=sys.stderr)
        return 1

    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    failed = 0

    try:
        for name, sql in SMOKE_QUERIES:
            try:
                rows = conn.execute(sql).fetchall()
                print(f"\n[{name}] rows={len(rows)}")
                for row in rows[:5]:
                    print(" ", dict(row))
                if len(rows) == 0:
                    print("  WARNING: empty result")
                    failed += 1
            except Exception as exc:  # noqa: BLE001
                print(f"\n[{name}] FAIL: {exc}", file=sys.stderr)
                failed += 1
    finally:
        conn.close()

    print(f"\nSmoke done. failures={failed}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
