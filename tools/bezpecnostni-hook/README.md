# Bezpečnostní hook pro práci s AI asistentem

Hook pro Claude Code, který **před každým zápisem souboru nebo příkazem** zablokuje dvě
nejdražší chyby v e-shopu:

1. **Únik nákupní ceny na web** — zápis do prodejní ceny WooCommerce (`_regular_price`,
   `set_price` …) současně s něčím, co páchne nákupní cenou (`nakupni`, `wholesale`,
   sloupec `"Cena"` z dodavatelského souboru).
2. **Tajemství v repozitáři** — API klíč nebo heslo natvrdo v kódu (formát klíče,
   pojmenovaná konstanta s hodnotou, vlepená řádka z `.env`).

Selhává **zavřeně**: když nemá čím přečíst vstup (chybí `jq` i Python) nebo je vstup
nečitelný, operaci zablokuje. Dokumentace smí pravidlo popsat (výjimka pro `.md`), ale
tajemství nesmí ani tam.

Není to důkaz bezpečnosti, je to nástražný drát na nejčastější vzorce. Druhá pojistka
patří do importu (porovnání ceny proti pokladnímu systému před zápisem).

## Instalace

1. `kontrola-cen-a-tajemstvi.sh` a `test-hooku.sh` zkopírovat do `.claude/hooks/` projektu.
2. Obsah `settings.vzor.json` sloučit do `.claude/settings.json`.

## Test

```
bash test-hooku.sh        # 19 případů: co musí zablokovat, co musí propustit
python -m pytest          # totéž přes pytest
```
