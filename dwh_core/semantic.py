"""
Shared DWH paths and semantic join/metric definitions for agent + MCP.
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DATA_DIR = ROOT / "Data"
DB_PATH = DATA_DIR / "olist_dwh.sqlite"

ALLOWED_TABLES = frozenset(
    {
        "dim_customer",
        "dim_product",
        "dim_seller",
        "dim_geolocation",
        "fact_orders",
        "fact_order_items",
        "fact_order_payments",
        "fact_order_reviews",
    }
)

# Allowed join edges: (left_table, right_table, left_key, right_key, edge_id)
JOIN_MAP: list[dict[str, str]] = [
    {
        "id": "orders_customer",
        "left_table": "fact_orders",
        "right_table": "dim_customer",
        "left_key": "customer_id",
        "right_key": "customer_id",
        "cardinality": "N:1",
        "description": "Sipariş → müşteri",
    },
    {
        "id": "items_orders",
        "left_table": "fact_order_items",
        "right_table": "fact_orders",
        "left_key": "order_id",
        "right_key": "order_id",
        "cardinality": "N:1",
        "description": "Kalem → sipariş",
    },
    {
        "id": "items_product",
        "left_table": "fact_order_items",
        "right_table": "dim_product",
        "left_key": "product_id",
        "right_key": "product_id",
        "cardinality": "N:1",
        "description": "Kalem → ürün",
    },
    {
        "id": "items_seller",
        "left_table": "fact_order_items",
        "right_table": "dim_seller",
        "left_key": "seller_id",
        "right_key": "seller_id",
        "cardinality": "N:1",
        "description": "Kalem → satıcı",
    },
    {
        "id": "payments_orders",
        "left_table": "fact_order_payments",
        "right_table": "fact_orders",
        "left_key": "order_id",
        "right_key": "order_id",
        "cardinality": "N:1",
        "description": "Ödeme → sipariş",
    },
    {
        "id": "reviews_orders",
        "left_table": "fact_order_reviews",
        "right_table": "fact_orders",
        "left_key": "order_id",
        "right_key": "order_id",
        "cardinality": "N:1",
        "description": "Yorum → sipariş",
    },
    {
        "id": "customer_geo",
        "left_table": "dim_customer",
        "right_table": "dim_geolocation",
        "left_key": "customer_zip_code_prefix",
        "right_key": "zip_code_prefix",
        "cardinality": "N:1",
        "description": "Müşteri zip → konum",
    },
    {
        "id": "seller_geo",
        "left_table": "dim_seller",
        "right_table": "dim_geolocation",
        "left_key": "seller_zip_code_prefix",
        "right_key": "zip_code_prefix",
        "cardinality": "N:1",
        "description": "Satıcı zip → konum",
    },
]

METRICS: list[dict[str, str]] = [
    {
        "id": "order_count",
        "name": "Sipariş adedi",
        "expression": "COUNT(DISTINCT fact_orders.order_id)",
        "grain": "order",
        "description": "Benzersiz sipariş sayısı",
    },
    {
        "id": "gmv",
        "name": "GMV / ciro",
        "expression": "SUM(fact_order_items.price)",
        "grain": "order_item",
        "description": "Kalem fiyatları toplamı (kargo hariç)",
    },
    {
        "id": "freight_total",
        "name": "Toplam kargo",
        "expression": "SUM(fact_order_items.freight_value)",
        "grain": "order_item",
        "description": "Kargo tutarı toplamı",
    },
    {
        "id": "avg_delivery_days",
        "name": "Ort. teslimat günü",
        "expression": (
            "AVG(julianday(fact_orders.order_delivered_customer_date) "
            "- julianday(fact_orders.order_purchase_timestamp))"
        ),
        "grain": "order",
        "description": "Teslim edilen siparişlerde ortalama gün",
    },
    {
        "id": "avg_review_score",
        "name": "Ort. yorum skoru",
        "expression": "AVG(fact_order_reviews.review_score)",
        "grain": "review",
        "description": "Ortalama review_score",
    },
    {
        "id": "payment_value",
        "name": "Ödeme tutarı",
        "expression": "SUM(fact_order_payments.payment_value)",
        "grain": "payment",
        "description": "Ödeme kayıtları toplamı (çoklu ödeme şişirebilir)",
    },
]

# Grain warnings: combining these grains without care double-counts
GRAIN_CONFLICTS: list[tuple[str, str, str]] = [
    (
        "order_item",
        "payment",
        "order_items x payments join sipariş başına kalem*ödeme çarpanı üretir; GMV için payments kullanma.",
    ),
    (
        "order_item",
        "review",
        "items x reviews join fan-out riski; skor için reviews+orders, ciro için items kullan.",
    ),
]
