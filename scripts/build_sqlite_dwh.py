"""
Build Olist star-schema SQLite DWH from Data/*.csv.

Output: Data/olist_dwh.sqlite
Idempotent: replaces DB if it already exists.
"""

from __future__ import annotations

import sqlite3
import sys
from pathlib import Path

import pandas as pd

ROOT = Path(__file__).resolve().parents[1]
DATA_DIR = ROOT / "Data"
DB_PATH = DATA_DIR / "olist_dwh.sqlite"


def csv_path(name: str) -> Path:
    path = DATA_DIR / name
    if not path.exists():
        raise FileNotFoundError(f"Missing CSV: {path}")
    return path


def load_csv(name: str, dtype: dict | None = None) -> pd.DataFrame:
    return pd.read_csv(csv_path(name), dtype=dtype)


def build_geolocation(geo: pd.DataFrame) -> pd.DataFrame:
    """One row per zip: avg lat/lng, first city/state."""
    geo = geo.copy()
    geo["geolocation_zip_code_prefix"] = (
        geo["geolocation_zip_code_prefix"].astype(str).str.zfill(5)
    )
    agg = (
        geo.groupby("geolocation_zip_code_prefix", as_index=False)
        .agg(
            geolocation_lat=("geolocation_lat", "mean"),
            geolocation_lng=("geolocation_lng", "mean"),
            geolocation_city=("geolocation_city", "first"),
            geolocation_state=("geolocation_state", "first"),
        )
        .rename(columns={"geolocation_zip_code_prefix": "zip_code_prefix"})
    )
    return agg


def create_schema(conn: sqlite3.Connection) -> None:
    conn.executescript(
        """
        PRAGMA foreign_keys = OFF;

        DROP TABLE IF EXISTS fact_order_reviews;
        DROP TABLE IF EXISTS fact_order_payments;
        DROP TABLE IF EXISTS fact_order_items;
        DROP TABLE IF EXISTS fact_orders;
        DROP TABLE IF EXISTS dim_product;
        DROP TABLE IF EXISTS dim_seller;
        DROP TABLE IF EXISTS dim_customer;
        DROP TABLE IF EXISTS dim_geolocation;

        CREATE TABLE dim_geolocation (
            zip_code_prefix TEXT PRIMARY KEY,
            geolocation_lat REAL,
            geolocation_lng REAL,
            geolocation_city TEXT,
            geolocation_state TEXT
        );

        CREATE TABLE dim_customer (
            customer_id TEXT PRIMARY KEY,
            customer_unique_id TEXT NOT NULL,
            customer_zip_code_prefix TEXT,
            customer_city TEXT,
            customer_state TEXT
        );

        CREATE TABLE dim_seller (
            seller_id TEXT PRIMARY KEY,
            seller_zip_code_prefix TEXT,
            seller_city TEXT,
            seller_state TEXT
        );

        CREATE TABLE dim_product (
            product_id TEXT PRIMARY KEY,
            product_category_name TEXT,
            category_name_english TEXT,
            product_name_lenght REAL,
            product_description_lenght REAL,
            product_photos_qty REAL,
            product_weight_g REAL,
            product_length_cm REAL,
            product_height_cm REAL,
            product_width_cm REAL
        );

        CREATE TABLE fact_orders (
            order_id TEXT PRIMARY KEY,
            customer_id TEXT NOT NULL,
            order_status TEXT,
            order_purchase_timestamp TEXT,
            order_approved_at TEXT,
            order_delivered_carrier_date TEXT,
            order_delivered_customer_date TEXT,
            order_estimated_delivery_date TEXT,
            FOREIGN KEY (customer_id) REFERENCES dim_customer(customer_id)
        );

        CREATE TABLE fact_order_items (
            order_id TEXT NOT NULL,
            order_item_id INTEGER NOT NULL,
            product_id TEXT,
            seller_id TEXT,
            shipping_limit_date TEXT,
            price REAL,
            freight_value REAL,
            PRIMARY KEY (order_id, order_item_id),
            FOREIGN KEY (order_id) REFERENCES fact_orders(order_id),
            FOREIGN KEY (product_id) REFERENCES dim_product(product_id),
            FOREIGN KEY (seller_id) REFERENCES dim_seller(seller_id)
        );

        CREATE TABLE fact_order_payments (
            order_id TEXT NOT NULL,
            payment_sequential INTEGER NOT NULL,
            payment_type TEXT,
            payment_installments INTEGER,
            payment_value REAL,
            PRIMARY KEY (order_id, payment_sequential),
            FOREIGN KEY (order_id) REFERENCES fact_orders(order_id)
        );

        CREATE TABLE fact_order_reviews (
            review_id TEXT NOT NULL,
            order_id TEXT NOT NULL,
            review_score INTEGER,
            review_comment_title TEXT,
            review_comment_message TEXT,
            review_creation_date TEXT,
            review_answer_timestamp TEXT,
            PRIMARY KEY (review_id, order_id),
            FOREIGN KEY (order_id) REFERENCES fact_orders(order_id)
        );

        CREATE INDEX idx_orders_customer ON fact_orders(customer_id);
        CREATE INDEX idx_orders_purchase_ts ON fact_orders(order_purchase_timestamp);
        CREATE INDEX idx_orders_status ON fact_orders(order_status);
        CREATE INDEX idx_orders_status_ts ON fact_orders(order_status, order_purchase_timestamp);
        CREATE INDEX idx_items_order ON fact_order_items(order_id);
        CREATE INDEX idx_items_product ON fact_order_items(product_id);
        CREATE INDEX idx_items_seller ON fact_order_items(seller_id);
        CREATE INDEX idx_payments_order ON fact_order_payments(order_id);
        CREATE INDEX idx_reviews_order ON fact_order_reviews(order_id);
        CREATE INDEX idx_customer_state ON dim_customer(customer_state);
        CREATE INDEX idx_customer_city ON dim_customer(customer_city);
        CREATE INDEX idx_seller_state ON dim_seller(seller_state);
        CREATE INDEX idx_seller_city ON dim_seller(seller_city);
        CREATE INDEX idx_product_category_en ON dim_product(category_name_english);
        CREATE INDEX idx_payment_type ON fact_order_payments(payment_type);
        """
    )


def main() -> int:
    print(f"Data dir: {DATA_DIR}")
    print(f"DB path:  {DB_PATH}")

    customers = load_csv(
        "olist_customers_dataset.csv",
        dtype={"customer_zip_code_prefix": str},
    )
    customers["customer_zip_code_prefix"] = (
        customers["customer_zip_code_prefix"].astype(str).str.zfill(5)
    )

    sellers = load_csv(
        "olist_sellers_dataset.csv",
        dtype={"seller_zip_code_prefix": str},
    )
    sellers["seller_zip_code_prefix"] = (
        sellers["seller_zip_code_prefix"].astype(str).str.zfill(5)
    )

    products = load_csv("olist_products_dataset.csv")
    cat_tr = load_csv("product_category_name_translation.csv")
    products = products.merge(cat_tr, on="product_category_name", how="left")
    products = products.rename(
        columns={"product_category_name_english": "category_name_english"}
    )

    orders = load_csv("olist_orders_dataset.csv")
    items = load_csv("olist_order_items_dataset.csv")
    payments = load_csv("olist_order_payments_dataset.csv")
    reviews = load_csv("olist_order_reviews_dataset.csv")
    # Olist can have duplicate review_id across rows; composite PK handles it.
    reviews = reviews.drop_duplicates(subset=["review_id", "order_id"])

    geo_raw = load_csv(
        "olist_geolocation_dataset.csv",
        dtype={"geolocation_zip_code_prefix": str},
    )
    geolocation = build_geolocation(geo_raw)

    if DB_PATH.exists():
        DB_PATH.unlink()

    conn = sqlite3.connect(DB_PATH)
    try:
        create_schema(conn)

        geolocation.to_sql("dim_geolocation", conn, if_exists="append", index=False)
        customers.to_sql("dim_customer", conn, if_exists="append", index=False)
        sellers.to_sql("dim_seller", conn, if_exists="append", index=False)
        products.to_sql("dim_product", conn, if_exists="append", index=False)
        orders.to_sql("fact_orders", conn, if_exists="append", index=False)
        items.to_sql("fact_order_items", conn, if_exists="append", index=False)
        payments.to_sql("fact_order_payments", conn, if_exists="append", index=False)
        reviews.to_sql("fact_order_reviews", conn, if_exists="append", index=False)

        conn.commit()

        print("\n=== Row counts ===")
        for table in (
            "dim_geolocation",
            "dim_customer",
            "dim_seller",
            "dim_product",
            "fact_orders",
            "fact_order_items",
            "fact_order_payments",
            "fact_order_reviews",
        ):
            n = conn.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
            print(f"  {table}: {n:,}")

        print("\n=== Smoke join (orders x items x customer) ===")
        sample = conn.execute(
            """
            SELECT o.order_id, c.customer_state, i.price, i.freight_value
            FROM fact_orders o
            JOIN dim_customer c ON c.customer_id = o.customer_id
            JOIN fact_order_items i ON i.order_id = o.order_id
            LIMIT 3
            """
        ).fetchall()
        for row in sample:
            print(f"  {row}")

        print(f"\nDone: {DB_PATH}")
    finally:
        conn.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
