# Data

Place Olist e-commerce CSV files in this directory, then build the SQLite warehouse:

```bash
python scripts/build_sqlite_dwh.py
```

Expected outputs:

- `olist_dwh.sqlite` (used by the PHP app and MCP server via `DWH_SQLITE_PATH`)

CSV sources are not stored in this repository due to size. Use the public Olist dataset (or your internal extract) and keep files local / in object storage for deployment.
