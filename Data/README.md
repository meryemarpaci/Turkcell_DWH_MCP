# Data

SQLite warehouse files live here (gitignored if large).

## Kim ne yapar?

| Rol | Ne yapar? |
|-----|-----------|
| **Sohbet kullanıcısı** | Sadece soru sorar. JSON/template doldurmaz. |
| **Sistem / sen (kurulum)** | SQLite yolunu verir; isteğe bağlı ince profil. |
| **Motor** | SQLite’tan tablo/kolon/FK/join adaylarını **otomatik keşfeder**; AI buna + canlı şemaya bakarak SQL yazar. |

`_template.json` sohbet kullanıcısı için değil — yeni warehouse bağlayan kişi içindir. Çoğu alan boş bırakılabilir (`auto_discover: true`).

## Yeni set (minimum)

```env
DWH_PROFILE=my_dataset
DWH_SQLITE_PATH=Data/my_dataset.sqlite
```

İsteğe bağlı: `php/config/profiles/my_dataset.json` (template’den).  
Profil yoksa bile env’deki sqlite ile auto-discover çalışır.

İsteğe bağlı zenginleştirme (AI’ya domain ipucu):
- `aliases` (São Paulo → SP gibi)
- `prompt.system_fragment`
- elle `joins` / `metrics` (keşfi override eder)

## Olist demo

```bash
python scripts/build_sqlite_dwh.py
```

`DWH_PROFILE=olist` + `Data/olist_dwh.sqlite`.
