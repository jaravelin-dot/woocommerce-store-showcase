#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Ověření, že ochranný hook opravdu blokuje to, co má, a nepřekáží tam,
# kde nemá. Pusť po každé změně hooku a po přeinstalaci nástrojů:
#
#     bash .claude/hooks/test-hooku.sh
#
# Vrací 0 když projdou všechny případy, jinak 1.
# ---------------------------------------------------------------------------
set -uo pipefail

HOOK="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/kontrola-cen-a-tajemstvi.sh"
chyb=0

# pripad <popis> <ocekavany_exit> <json>
pripad() {
  local popis="$1" ocekavano="$2" json="$3"
  local skutecnost
  printf '%s' "$json" | bash "$HOOK" >/dev/null 2>&1
  skutecnost=$?
  if [ "$skutecnost" -eq "$ocekavano" ]; then
    printf '  OK    %s (exit %s)\n' "$popis" "$skutecnost"
  else
    printf '  CHYBA %s — čekáno exit %s, dostal jsem %s\n' "$popis" "$ocekavano" "$skutecnost"
    chyb=$((chyb + 1))
  fi
}

echo "Test hooku: $HOOK"
echo

# --- musí ZABLOKOVAT (exit 2) --------------------------------------------
pripad "nákupní cena přes Write" 2 \
  '{"tool_name":"Write","tool_input":{"file_path":"scripts/import.php","content":"<?php $p->set_regular_price($radek[\"Cena\"]);"}}'

pripad "nákupní cena přes Edit" 2 \
  '{"tool_name":"Edit","tool_input":{"file_path":"scripts/import.php","new_string":"update_post_meta($id, \"_regular_price\", $nakupni_cena);"}}'

pripad "nákupní cena schovaná v Bash heredoc" 2 \
  '{"tool_name":"Bash","tool_input":{"command":"cat > scripts/import.php <<EOF\n<?php $p->set_regular_price($row[\"Cena\"]);\nEOF"}}'

pripad "WooCommerce klíč natvrdo v kódu" 2 \
  '{"tool_name":"Write","tool_input":{"file_path":"scripts/woo.php","content":"<?php $key = \"ck_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0\";"}}'

pripad "heslo k pokladnímu systému natvrdo" 2 \
  '{"tool_name":"Write","tool_input":{"file_path":"src/config.php","content":"<?php define(\"X\", \"POS_TOKEN=abcdef123456\");"}}'


pripad "klíč přes define()" 2   '{"tool_name":"Write","tool_input":{"file_path":"src/config.php","content":"<?php define(\"POS_TOKEN\", \"abcdef123456xyz\");"}}'

pripad "klíč v poli => " 2   '{"tool_name":"Write","tool_input":{"file_path":"src/config.php","content":"<?php return [\"WOO_CONSUMER_SECRET\" => \"abcdef123456xyz\"];"}}'

pripad "klíč k AI natvrdo v konstantě" 2   '{"tool_name":"Write","tool_input":{"file_path":"wp-config.php","content":"<?php define(\"ANTHROPIC_KEY\", \"abcdef123456xyz\");"}}'

pripad "načtení klíče přes getenv (falešný poplach)" 0   '{"tool_name":"Write","tool_input":{"file_path":"src/config.php","content":"<?php return [\"MYSQL_PASS\" => getenv(\"MYSQL_PASS\")];"}}'

# --- musí PROPUSTIT (exit 0) ---------------------------------------------
pripad "prodejní cena z POS" 0 \
  '{"tool_name":"Write","tool_input":{"file_path":"scripts/import.php","content":"<?php $p->set_regular_price($pos[\"priceWithVat\"]);"}}'

pripad "čtení sloupce Cena bez zápisu do e-shopu" 0 \
  '{"tool_name":"Write","tool_input":{"file_path":"scripts/marze.php","content":"<?php $marze = $radek[\"Cena\"] * 1.4; // interní report"}}'

pripad "tajemství v .env (tam patří)" 0 \
  '{"tool_name":"Write","tool_input":{"file_path":".env","content":"POS_TOKEN=\"abcdef123456\""}}'

pripad "běžný příkaz v Bash" 0 \
  '{"tool_name":"Bash","tool_input":{"command":"composer run lint"}}'


# --- výjimka pro dokumentaci: popsat pravidlo se musí dát ------------------
pripad "pravidlo popsané v dokumentaci (malými písmeny)" 0   '{"tool_name":"Edit","tool_input":{"file_path":"docs/pravidla.md","new_string":"Sloupec Cena je nákupní cena, nikdy ji nedávej do _regular_price."}}'

pripad "pravidlo popsané v CLAUDE.md" 0   '{"tool_name":"Write","tool_input":{"file_path":"CLAUDE.md","content":"Nikdy nezapisuj nakupni cenu do _regular_price ani _sale_price."}}'

pripad "výjimka NEPLATÍ na tajemství v .md" 2   '{"tool_name":"Write","tool_input":{"file_path":"docs/navod.md","content":"Vloz klic: ck_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0"}}'

pripad "výjimka NEPLATÍ na kód" 2   '{"tool_name":"Write","tool_input":{"file_path":"scripts/import.php","content":"<?php $p->set_regular_price($radek[\"Cena\"]);"}}'

pripad "výjimka NEPLATÍ na .md.php" 2   '{"tool_name":"Write","tool_input":{"file_path":"tpl/cena.md.php","content":"<?php $p->set_regular_price($radek[\"Cena\"]);"}}'

# --- musí SELHAT ZAVŘENĚ (exit 2), když chybí parser JSON ------------------
# /usr/bin:/bin má coreutils, ale ani jq, ani python — přesně stav, kvůli
# kterému stará verze hooku tiše propouštěla všechno.
vysledek=$(printf '%s' '{"tool_name":"Write","tool_input":{"file_path":"a.php","content":"x"}}' \
  | env PATH=/usr/bin:/bin bash "$HOOK" >/dev/null 2>&1; echo $?)
if [ "$vysledek" -eq 2 ]; then
  printf '  OK    bez jq i python selže zavřeně (exit %s)\n' "$vysledek"
else
  printf '  CHYBA bez jq i python — čekáno exit 2, dostal jsem %s\n' "$vysledek"
  chyb=$((chyb + 1))
fi

echo
if [ "$chyb" -eq 0 ]; then
  echo "VŠECHNO PROŠLO — hook funguje."
  exit 0
fi
echo "SELHALO: $chyb případů. Hook nechrání to, co si myslíš."
exit 1
