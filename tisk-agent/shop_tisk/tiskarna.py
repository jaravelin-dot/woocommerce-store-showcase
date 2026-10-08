"""Z HTML udělá PDF (Chrome) a pošle ho na tiskárnu (SumatraPDF).

Obojí je tichý běh bez okna. Když cokoli z toho selže, vyletí ChybaTisku
a volající NESMÍ úlohu potvrdit jako vytištěnou — jinak se objednávka
nenávratně ztratí.
"""

from __future__ import annotations

import logging
import subprocess
import tempfile
from pathlib import Path

log = logging.getLogger(__name__)

CHROME_VYCHOZI = Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe")
SUMATRA_VYCHOZI = Path(r"C:\shop-tisk\SumatraPDF.exe")


class ChybaTisku(RuntimeError):
    pass


class Tiskarna:
    def __init__(self, chrome: Path = CHROME_VYCHOZI, sumatra: Path = SUMATRA_VYCHOZI, timeout: int = 120):
        self.chrome = chrome
        self.sumatra = sumatra
        self.timeout = timeout

    def vytiskni(self, html: str) -> None:
        with tempfile.TemporaryDirectory(prefix="shop-tisk-") as docasna:
            slozka = Path(docasna)
            soubor_html = slozka / "vydejka.html"
            soubor_pdf = slozka / "vydejka.pdf"

            soubor_html.write_text(html, encoding="utf-8")

            self._na_pdf(soubor_html, soubor_pdf)
            self._na_papir(soubor_pdf)

    def _na_pdf(self, zdroj: Path, cil: Path) -> None:
        prikaz = [
            str(self.chrome),
            "--headless",
            "--disable-gpu",
            "--no-pdf-header-footer",
            f"--print-to-pdf={cil}",
            zdroj.as_uri(),
        ]

        try:
            vysledek = subprocess.run(prikaz, capture_output=True, text=True, timeout=self.timeout)
        except (OSError, subprocess.SubprocessError) as chyba:
            raise ChybaTisku(f"Chrome se nepodařilo spustit: {chyba}") from chyba

        if vysledek.returncode != 0:
            raise ChybaTisku(f"Chrome skončil chybou {vysledek.returncode}: {vysledek.stderr}")

        if not cil.exists():
            raise ChybaTisku("Chrome doběhl, ale PDF nevzniklo.")

    def _na_papir(self, pdf: Path) -> None:
        # "color" vynucuje barevny tisk bez ohledu na to, co ma tiskarna
        # prednastavene. Fotka zbozi je na vydejce hlavni voditko, podle ceho
        # skladnik pozna barvu - v odstinech sedi je k nicemu.
        prikaz = [
            str(self.sumatra),
            "-print-to-default",
            "-print-settings", "color",
            "-silent",
            str(pdf),
        ]

        try:
            vysledek = subprocess.run(prikaz, capture_output=True, text=True, timeout=self.timeout)
        except (OSError, subprocess.SubprocessError) as chyba:
            raise ChybaTisku(f"SumatraPDF se nepodařilo spustit: {chyba}") from chyba

        if vysledek.returncode != 0:
            raise ChybaTisku(f"Tisk selhal ({vysledek.returncode}): {vysledek.stderr}")
