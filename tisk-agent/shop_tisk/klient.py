"""Spojení s webem e-shopu.

Výpadek sítě nesmí program shodit — fronta zůstane na serveru a zkusí se
za minutu znovu.
"""

from __future__ import annotations

import logging

import requests

from .nastaveni import Nastaveni

log = logging.getLogger(__name__)


class Klient:
    def __init__(self, nastaveni: Nastaveni, timeout: int = 30):
        self.nastaveni = nastaveni
        self.timeout = timeout
        self.relace = requests.Session()
        self.relace.auth = (nastaveni.uzivatel, nastaveni.heslo)

    def _adresa(self, cesta: str) -> str:
        return f"{self.nastaveni.url}/wp-json/shop/v1/{cesta}"

    def fronta(self) -> list[dict] | None:
        """Načte seznam úloh z fronty. POZOR: GET /tisk/fronta MĚNÍ STAV —
        server si tím úlohy zabere. Nevyzvednuté se po 10 minutách samy vrátí.

        Volej tuto metodu POUZE když hodláš úlohy opravdu zpracovat.
        Nikdy ne 'pro jistotu' nebo v cyklu opakování.

        Vrací None, když se spojení se serverem vůbec nepovedlo (výpadek sítě,
        špatné heslo, 401, 500…) — to je jiná situace než opravdu prázdná
        fronta ([]) a volající (Smycka) na ten rozdíl musí reagovat, jinak
        by trvalý výpadek vypadal stejně jako klidná prodejna bez objednávek."""
        try:
            odpoved = self.relace.get(self._adresa("tisk/fronta"), timeout=self.timeout)
        except requests.RequestException as chyba:
            log.warning("Frontu se nepodařilo stáhnout: %s", chyba)
            return None

        if odpoved.status_code != 200:
            log.warning("Fronta vrátila %s", odpoved.status_code)
            return None

        return list(odpoved.json().get("ulohy", []))

    def html(self, uloha_id: int) -> str:
        odpoved = self.relace.get(self._adresa(f"tisk/uloha/{uloha_id}"), timeout=self.timeout)

        if odpoved.status_code != 200:
            raise RuntimeError(f"Úloha {uloha_id} vrátila {odpoved.status_code}")

        return odpoved.text

    def hotovo(self, uloha_id: int) -> tuple[bool, str]:
        """Potvrdí vytištění úlohy. Vrací dvojici (potvrzeno, duvod).

        Server (class-shop-tisk.php) u 409 posílá i `duvod`:
        - 'jiz_vytisteno' — úlohu potvrdil někdo/něco už dřív. Neškodná
          duplicita (typicky vlastní opakovaný pokus po ztracené odpovědi),
          NE selhání — jinak by planě spustila hodinovou pojistku (NÁLEZ D2
          ze závěrečné recenze). Proto potvrzeno=True.
        - 'nevyzvednuto' — server úlohu mezitím sám uvolnil (vypršelo
          10minutové okno). To JE selhání, úloha se má zkusit znovu.

        404 (úloha vůbec neexistuje) i neočekávaný tvar 409 odpovědi (bez
        `duvod`) se berou jako obyčejné selhání, ne jako tichá duplicita.
        """
        odpoved = self.relace.post(self._adresa(f"tisk/uloha/{uloha_id}/hotovo"), timeout=self.timeout)

        if odpoved.status_code == 200:
            return True, ""

        if odpoved.status_code == 409:
            duvod = odpoved.json().get("duvod", "")
            return duvod == "jiz_vytisteno", duvod

        if odpoved.status_code == 404:
            return False, "http_404"

        return False, f"http_{odpoved.status_code}"
