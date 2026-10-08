"""Spuštění: python -m shop_tisk

Běží na pozadí, budí se každou minutu. Po zapnutí počítače se ptá na celou
frontu, takže ráno vyjede i to, co přišlo v noci.
"""

from __future__ import annotations

import logging
import os
import subprocess
import time
from pathlib import Path

from .klient import Klient
from .nastaveni import Nastaveni
from .smycka import Smycka
from .tiskarna import Tiskarna

SLOZKA = Path(os.environ.get("APPDATA", ".")) / "shop-tisk"
PAUZA_VTERIN = 60


def upozorni_uzivatele(zprava: str) -> None:
    """Okno, které samo zmizí po minutě. Nepotřebuje žádný doinstalovaný modul.

    Text se PowerShellu předává přes proměnnou prostředí, ne vložením do
    příkazu f-stringem — uvozovka nebo `$` v textu by jinak příkaz rozbily
    (nebo v horším případě spustily jako kód). Env proměnná je nejjednodušší
    způsob, jak dostat text do PowerShellu bez řešení escapování."""
    prostredi = os.environ.copy()
    prostredi["SHOP_TISK_ZPRAVA"] = zprava

    subprocess.run(
        [
            "powershell",
            "-NoProfile",
            "-Command",
            '(New-Object -ComObject Wscript.Shell).Popup($env:SHOP_TISK_ZPRAVA, 60, "Tisk objednávek", 48)',
        ],
        capture_output=True,
        env=prostredi,
    )


def main() -> None:
    SLOZKA.mkdir(parents=True, exist_ok=True)

    # Logování musí naskočit DŘÍV než čtení configu. Program běží z Plánovače
    # úloh bez konzole — stderr nikam nechodí, takže když config chybí nebo
    # je vadný, jediné místo, kam se to obsluha dozví, je tisk.log.
    logging.basicConfig(
        filename=SLOZKA / "tisk.log",
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        encoding="utf-8",
    )

    try:
        nastaveni = Nastaveni.nacti(SLOZKA / "config.ini")
    except Exception as chyba:  # noqa: BLE001 — jakákoli chyba configu (chybějící
        # soubor, chybějící klíč, i syntaktická chyba jako chybějící hlavička
        # [web], která vyhodí configparser.MissingSectionHeaderError) musí
        # skončit v logu, ne tiše propadnout ven beze stopy.
        logging.error("Program se nespustil, config je vadný: %s", chyba)
        raise SystemExit(1)

    smycka = Smycka(Klient(nastaveni), Tiskarna(), upozorni=upozorni_uzivatele)

    logging.info("Program spuštěn, hlídám frontu.")

    while True:
        try:
            smycka.jedno_kolo()
        except Exception:  # noqa: BLE001 — program nesmí spadnout na ničem
            logging.exception("Neočekávaná chyba v kole")

        time.sleep(PAUZA_VTERIN)


if __name__ == "__main__":
    main()
