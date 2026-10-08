#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Hook: tvrdá zábrana proti dvěma nejdražším chybám v e-shopu
#   1) nákupní cena od dodavatele zapsaná do prodejní ceny ve WooCommerce
#   2) API klíč nebo heslo zapsané do souboru v repozitáři
#
# Spouští se PŘED každým zápisem: Write / Edit / MultiEdit / Bash.
# Exit 2 = operace se zablokuje a Claude dostane text ze stderr jako důvod.
#
# SELHÁVÁ ZAVŘENĚ. Když si hook není jistý (chybí parser JSON, rozbitý vstup),
# operaci zablokuje. Dřívější verze v takovém případě tiše povolila — a protože
# na stroji nebylo `jq`, nechala projít i kód, který měla zastavit.
#
# TOHLE NENÍ DŮKAZ BEZPEČNOSTI. Je to nástražný drát na nejčastější vzorce.
# Skutečný únik se stane až za běhu importu, ne při zápisu souboru — druhá
# pojistka patří přímo do importéru (porovnat cenu proti pokladnímu systému a shodit běh).
# ---------------------------------------------------------------------------
set -uo pipefail

zablokuj() {
  printf '%s\n' "$1" >&2
  exit 2
}

# Čte se přesměrováním, ne přes `cat` — hook nesmí spadnout na tom, že by
# chyběl externí příkaz. Nečitelný vstup se řeší níž, ne tichým povolením.
vstup=$(</dev/stdin)
[ -z "$vstup" ] && exit 0

# --- 0. ROZEBRÁNÍ VSTUPU --------------------------------------------------
# Preferuje `jq`, jinak Python (ten je na stroji jistota). Když není ani jeden,
# hook nedokáže vstup přečíst — a nečitelný vstup se nepovoluje.
#
# Výstup: 1. řádek = cesta k souboru, další řádky = zapisovaný obsah.
_JQ='[ .tool_input.file_path // "" ]
     + ([ .tool_input.content?, .tool_input.new_string?, .tool_input.command?,
          (.tool_input.edits? // [] | .[].new_string?) ]
        | map(select(. != null)))
     | .[]'

_PY='
import json, sys
d = (json.load(sys.stdin) or {}).get("tool_input") or {}
out = [d.get("file_path") or ""]
for k in ("content", "new_string", "command"):
    v = d.get(k)
    if isinstance(v, str) and v:
        out.append(v)
for e in (d.get("edits") or []):
    v = (e or {}).get("new_string")
    if isinstance(v, str) and v:
        out.append(v)
sys.stdout.write("\n".join(out))
'

if command -v jq >/dev/null 2>&1; then
  vystup=$(printf '%s' "$vstup" | jq -r "$_JQ" 2>/dev/null)
  parser_ok=$?
elif command -v python >/dev/null 2>&1; then
  vystup=$(printf '%s' "$vstup" | python -c "$_PY" 2>/dev/null)
  parser_ok=$?
elif command -v python3 >/dev/null 2>&1; then
  vystup=$(printf '%s' "$vstup" | python3 -c "$_PY" 2>/dev/null)
  parser_ok=$?
else
  zablokuj "ZABLOKOVÁNO — hook nemá čím přečíst vstup.

Na stroji není ani \`jq\`, ani \`python\`. Bez nich hook nedokáže zkontrolovat,
jestli se do souboru nedostává nákupní cena nebo API klíč, takže nic nepovolí.

Náprava:
  winget install jqlang.jq     (pak restartuj Claude Code kvůli PATH)

Ověření, že hook zase funguje:
  bash .claude/hooks/test-hooku.sh"
fi

if [ "$parser_ok" -ne 0 ]; then
  zablokuj "ZABLOKOVÁNO — hook nedokázal rozebrat vstupní JSON.

Nečitelný vstup se nepovoluje: hook nemůže potvrdit, že v zapisovaném kódu
není nákupní cena ani API klíč. Když se to opakuje, pusť diagnostiku:
  bash .claude/hooks/test-hooku.sh"
fi

soubor=$(printf '%s\n' "$vystup" | head -1)
obsah=$(printf '%s\n' "$vystup" | tail -n +2)

[ -z "$obsah" ] && exit 0

# Windows dává cesty se zpětnými lomítky — sjednotit, jinak výjimky níž nesednou.
soubor_n=${soubor//\\//}

# Sjednocení uvozovek: díky tomu můžou být vzory níž psané jen s "..." a nemusí
# se v nich eskapovat apostrofy. Na shodu vzoru to nemá vliv.
obsah_n=$(printf '%s' "$obsah" | tr "'" '"')

# --- 1. NÁKUPNÍ CENA ------------------------------------------------------
# Dokumentace pravidlo POPISUJE, neporušuje ho. Bez téhle výjimky nejde do
# CLAUDE.md ani do dokumentace napsat větu „sloupec Cena je nákupní cena, nikdy ji
# nedávej do _regular_price" — hook ji vyhodnotí jako únik a zápis zablokuje.
# Markdown se nespouští, únik z něj vzniknout nemůže.
# Kontrola tajemství (bod 2) pro dokumentaci platí dál — klíč v .md by skončil
# na GitHubu úplně stejně jako v kódu.
case "$soubor_n" in
  *.md|*.markdown|*.txt|*.rst) preskocit_ceny=1 ;;
  *) preskocit_ceny=0 ;;
esac

# Hledáme zápis do prodejní ceny SOUČASNĚ s něčím, co páchne nákupní cenou.
zapis_ceny='_regular_price|_sale_price|set_price|set_regular_price|set_sale_price|"regular_price"|"sale_price"|"price"[[:space:]]*=>|update_post_meta\([^)]*_price'
zdroj_nakupni='nakupni|nakupní|nákupni|nákupní|nakup_cena|purchase_price|cost_price|velkoobchod|wholesale|"Cena"|->Cena|\[Cena\]'

if [ "$preskocit_ceny" -eq 0 ] \
   && printf '%s' "$obsah_n" | grep -qiE "$zapis_ceny" \
   && printf '%s' "$obsah_n" | grep -qiE "$zdroj_nakupni"; then
  zablokuj "ZABLOKOVÁNO — podezření na únik nákupní ceny.

V zapisovaném kódu je současně zápis do prodejní ceny WooCommerce
a odkaz na nákupní cenu (sloupec \"Cena\" od dodavatele nebo proměnná,
která vypadá jako nákupní cena).

Nákupní ceny se nikdy nesmí dostat na web.
Prodejní ceny se berou výhradně z pokladního systému (POS).

Co udělat:
  - Pokud je to omyl, přepiš zdroj ceny na prodejní cenu z POS.
  - Pokud je to falešný poplach (např. jen počítáš marži do interního
    reportu), přejmenuj proměnné tak, aby bylo zřejmé, co je co,
    a zeptej se uživatele, než to zapíšeš."
fi

# --- 2. TAJEMSTVÍ V REPOZITÁŘI --------------------------------------------
# .env* je v .gitignore, tam tajemství patří — tam nekontrolujeme.
# Tenhle skript i jeho test obsahují ty vzorce samy o sobě.
case "$soubor_n" in
  *.env|*.env.*) exit 0 ;;
  */.claude/hooks/*) exit 0 ;;
esac

# Tři tvary, ve kterých se tajemství reálně dostane do kódu:
#   a) klíč poznatelný podle formátu (WooCommerce ck_/cs_, JWT)
#   b) pojmenovaná konstanta s hodnotou v uvozovkách — pokrývá i
#      define("X_TOKEN", "..."), "X_TOKEN" => "..." i X_TOKEN = "..."
#   c) vlepená řádka z .env (bez uvozovek, bez mezer kolem =)
# Vzor `X_TOKEN => getenv(...)` schválně neplatí za nález — tam žádné
# tajemství není a falešný poplach by vedl k vypnutí hooku.
_t_format='(ck|cs)_[0-9a-f]{32,}|eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}'
# Prostřední segment je volitelný — skutečné názvy z .env.example jsou
# WOO_CONSUMER_SECRET, SEARCH_MASTER_KEY, POS_API_TOKEN, ne jen WOO_SECRET.
_t_nazev='(POS|BANKA|WOO|MYSQL|DB|SEARCH|ANTHROPIC)_([A-Z]+_)*(TOKEN|KEY|SECRET|PASS|PASSWORD)'
tajemstvi="${_t_format}|${_t_nazev}\"?[[:space:]]*(=>|[=:,])[[:space:]]*\"[^\"\$]{6,}|${_t_nazev}=[A-Za-z0-9_./+-]{10,}"

if printf '%s' "$obsah_n" | grep -qE "$tajemstvi"; then
  zablokuj "ZABLOKOVÁNO — vypadá to na API klíč nebo heslo natvrdo v souboru.

Tajemství patří výhradně do .env (který je v .gitignore).
V kódu je načítej přes getenv() nebo \$_ENV, nikdy je nepiš napevno.

Pokud je to jen ukázková hodnota do dokumentace, použij zjevný
zástupný text typu VLOZ_SVUJ_KLIC a zkus to znovu."
fi

exit 0
