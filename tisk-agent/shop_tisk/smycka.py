"""Jedno kolo: vyzvedni frontu, vytiskni, potvrď.

Pořadí „vytiskni, teprve pak potvrď" je celé jádro spolehlivosti. Kdyby se
potvrzovalo dřív, každý výpadek tiskárny by nenávratně snědl jednu
objednávku. Takhle se v nejhorším vytiskne dvakrát. Proto je i `hotovo()`
uvnitř stejného ošetřeného bloku jako tisk — když selže potvrzení, úloha se
nesmí započítat jako vytištěná a vytiskne se znovu příště.

Selhání jedné úlohy (tiskárna, výpadek sítě při stažení HTML nebo při
potvrzení) nesmí shodit zpracování ostatních úloh v kole — proto se
odchytávají jen tři konkrétní typy, o kterých víme, že je za normálních
okolností vyvolá tisk nebo síť: `ChybaTisku` (Chrome/SumatraPDF selhaly),
`RuntimeError` (html() dostalo jinou odpověď než 200) a
`requests.RequestException` (výpadek spojení, i uvnitř hotovo()). Cokoli
jiného (např. AttributeError, TypeError) je programátorská chyba a smí
program shodit, aby se na ni přišlo, ne aby ji log tiše spolkl.

Druhé kolo recenze ukázalo, že rozlišení None/[] samo o sobě nestačí: server
si vyzvednutou úlohu drží 10 minut, takže MEZI POKUSY vrací opravdovou
prázdnou frontu ([]) i uprostřed nepřetržité poruchy — a „prázdno = vše
v pořádku" by počítadlo ticha uprostřed poruchy resetovalo. Proto se vedle
`posledni_uspech` drží i `bylo_selhani`: jakmile od posledního úspěchu došlo
k JAKÉMUKOLI selhání (None, chyba tisku, chyba potvrzení), prázdná fronta už
sama o sobě NEZNAMENÁ klid a `posledni_uspech` se neposouvá. Zruší to až
skutečný úspěch — vytištěná a potvrzená úloha.
"""

from __future__ import annotations

import logging
import time
from typing import Callable

import requests

from .tiskarna import ChybaTisku

log = logging.getLogger(__name__)

TICHO_VTERIN = 3600


class Smycka:
    def __init__(
        self,
        klient,
        tiskarna,
        upozorni: Callable[[str], None] | None = None,
        hodiny: Callable[[], float] = time.monotonic,
    ):
        self.klient = klient
        self.tiskarna = tiskarna
        self.upozorni = upozorni or (lambda zprava: None)
        self.hodiny = hodiny
        self.posledni_uspech = hodiny()
        self.bylo_selhani = False
        self.upozorneno = False

    def jedno_kolo(self) -> int:
        ulohy = self.klient.fronta()

        if ulohy is None:
            # Spojení se serverem se nepovedlo (výpadek, špatné heslo, 401…).
            # Tohle je selhání a počítadlo ticha se NESMÍ resetovat, jinak
            # by trvalý výpadek nezakřičel nikdy.
            self.bylo_selhani = True
            vytisteno = 0
            pocet_cekajicich = None
        elif not ulohy:
            # Fronta je prázdná — ale to samo o sobě NENÍ důkaz klidu.
            # Server si vyzvednutou úlohu drží 10 minut, takže mezi pokusy
            # vrací opravdové [] i uprostřed nepřetržité poruchy. Prázdno
            # smí resetovat počítadlo jen tehdy, když od posledního úspěchu
            # nedošlo k žádnému selhání — jinak zůstává vše beze změny.
            if not self.bylo_selhani:
                self.posledni_uspech = self.hodiny()
                self.upozorneno = False
            vytisteno = 0
            pocet_cekajicich = 0
        else:
            vytisteno = 0
            nejaky_neuspech = False

            for uloha in ulohy:
                uloha_id = int(uloha["id"])

                try:
                    html = self.klient.html(uloha_id)
                    self.tiskarna.vytiskni(html)
                    potvrzeno, duvod = self.klient.hotovo(uloha_id)
                except (ChybaTisku, RuntimeError, requests.RequestException) as chyba:
                    log.exception("Úloha %s se nevytiskla: %s", uloha_id, chyba)
                    nejaky_neuspech = True
                    continue

                if potvrzeno:
                    # D2: duvod=jiz_vytisteno (viz Klient.hotovo) je neškodná
                    # duplicita, ne selhání — jen se to zaznamená do logu,
                    # aby to nezpůsobilo planý poplach.
                    if duvod:
                        log.info(
                            "Úloha %s (objednávka %s) byla potvrzená už dřív (%s), bere se jako hotová.",
                            uloha_id,
                            uloha.get("objednavka", "?"),
                            duvod,
                        )
                    else:
                        log.info("Úloha %s (objednávka %s) vytištěna.", uloha_id, uloha.get("objednavka", "?"))
                    vytisteno += 1
                else:
                    # D2: nevyzvednuto/http_404 JSOU selhání a musí zůstat
                    # viditelné v logu i s důvodem, ne jen jako obecné hlášení.
                    log.error("Úloha %s se nepodařilo potvrdit (%s), zkusí se znovu.", uloha_id, duvod)
                    nejaky_neuspech = True

            if vytisteno:
                # Aspoň jedna úloha se vytiskla a potvrdila — to je skutečný
                # úspěch, který ruší i selhání předchozích kol.
                self.posledni_uspech = self.hodiny()
                self.bylo_selhani = False
                self.upozorneno = False
                return vytisteno

            if nejaky_neuspech:
                self.bylo_selhani = True

            pocet_cekajicich = len(ulohy)

        if not self.upozorneno and self.bylo_selhani and self.hodiny() - self.posledni_uspech > TICHO_VTERIN:
            self.upozorneno = True

            if pocet_cekajicich is None:
                zprava = (
                    "Tisk objednávek nefunguje už hodinu — spojení s webem "
                    "se nedaří navázat. Zkontroluj heslo v configu a připojení k internetu."
                )
            elif pocet_cekajicich:
                zprava = (
                    f"Tisk objednávek nefunguje už hodinu a čeká {pocet_cekajicich} papírů. "
                    "Zkontroluj tiskárnu."
                )
            else:
                zprava = (
                    "Tisk objednávek nefunguje už hodinu — fronta teď zrovna vypadá prázdně "
                    "(server si drží vyzvednuté úlohy 10 minut), ale poslední pokusy selhávaly. "
                    "Zkontroluj tiskárnu a spojení s webem."
                )

            self.upozorni(zprava)

        return vytisteno
