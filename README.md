# Turkcell DWH MCP

Prompt-tabanlı veri ambarı analisti: kullanıcı doğal dilde sorar, agent şema/join/filtre planını kurar, güvenli SQL çalıştırır ve KPI / trend / tablo çıktısı üretir.

## Özellikler

- Gemini tabanlı özel agent (tool-calling loop)
- SELECT-only SQL guard ve allowlist tablolar
- Semantik katman (join edge id’leri, metrikler, filter ipuçları)
- Chat UI + etkileşimli şema (ER) görünümü
- Trend raporlarında çizgi grafik; kompakt LLM payload (ham dump yok)
- Python MCP sunucusu (`mcp_server`) ve paylaşılan `dwh_core` (şema / validate / report)

## Mimari

```text
Browser (public/)
  → PHP API (api/chat.php)
    → AgentOrchestrator + Gemini
      → Tools (probe / report / schema) → SQLite DWH
```

Paralel olarak `mcp_server` aynı DWH yeteneklerini MCP tool yüzeyi olarak sunar.

## Gereksinimler

- PHP 8.2+ (`pdo_sqlite`, `curl`)
- Python 3.10+ (MCP / DWH build scriptleri)
- Gemini API anahtarı

## Kurulum

1. Depoyu klonlayın.
2. Bağımlılıklar:

```bash
pip install -r requirements.txt
```

3. Ortam değişkenleri:

```bash
copy .env.example .env
```

`.env` içinde en azından:

- `GEMINI_API_KEY`
- `GEMINI_MODEL` (ör. `gemini-flash-latest`)
- `DWH_SQLITE_PATH=Data/olist_dwh.sqlite`

4. SQLite DWH oluşturma (CSV’ler `Data/` altında ise):

```bash
python scripts/build_sqlite_dwh.py
```

5. Web uygulaması:

```bash
php -S localhost:8080 router.php
```

- Sohbet: http://localhost:8080  
- Şema haritası: http://localhost:8080/schema.html  

6. MCP sunucusu (isteğe bağlı):

```bash
python -m mcp_server.server
```

## Ana dizinler

| Yol | Açıklama |
|-----|----------|
| `public/` | Chat ve şema UI |
| `api/` | HTTP uçları |
| `php/` | Agent, Gemini istemcisi, tool’lar, prompt’lar |
| `mcp_server/` | FastMCP sunucusu |
| `dwh_core/` | Python DWH çekirdeği |
| `Data/` | Kaynak CSV / SQLite |
| `scripts/` | Build ve smoke testler |

## Güvenlik notları

- `.env` commit edilmez; yalnızca `.env.example` paylaşılır.
- Üretimde HTTPS, anahtar yönetimi ve rate limit eklenmelidir.
- Agent çıktısına ham büyük tablo verilmez; tool katmanı aggregate / örnek satır döner.

## Lisans

Özel / kurum içi kullanım — depo sahibi politikasına tabidir.
