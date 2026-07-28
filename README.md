# Turkcell DWH MCP

Prompt-tabanlı veri ambarı analisti: kullanıcı doğal dilde sorar, agent şema/join/filtre planını kurar, güvenli SQL çalıştırır ve KPI / trend / tablo çıktısı üretir.

## Özellikler

- Gemini tabanlı özel agent (tool-calling loop)
- SELECT-only SQL guard ve allowlist tablolar
- Semantik katman (join edge id’leri, metrikler, filter ipuçları)
- Chat UI + etkileşimli şema (ER) görünümü
- Trend raporlarında çizgi grafik; kompakt LLM payload (ham dump yok)
- Python MCP sunucusu (`mcp_server`) ve paylaşılan `dwh_core` (şema / validate / report)
- Cursor / IDE’ye zorunlu bağımlılık yok; uygulama bağımsız çalışır

## Mimari

```text
Browser (public/)
  → PHP API (api/chat.php)
    → AgentOrchestrator + Gemini
      → Tools (probe / report / schema) → SQLite DWH
```

İsteğe bağlı olarak `mcp_server` aynı DWH yeteneklerini bağımsız bir MCP tool yüzeyi olarak sunar (herhangi bir MCP client ile).

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
- `GEMINI_MODEL` (ör. `gemini-3-flash-preview`)
- `DWH_PROFILE=olist` → `php/config/profiles/<id>.json`
- `DWH_SQLITE_PATH=Data/olist_dwh.sqlite`

Yeni veri seti: SQLite’ı koy + `_template.json`’dan profil yaz + `.env`’de `DWH_PROFILE` / `DWH_SQLITE_PATH` değiştir. PHP kodu değiştirmen gerekmez (ayrıntı: `Data/README.md`).

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

6. MCP sunucusu (isteğe bağlı, bağımsız servis):

```bash
python -m mcp_server.server
```

Bu adım web sohbeti için zorunlu değildir. MCP istemcisi kullanan ekipler tool yüzeyini buradan bağlayabilir.
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
