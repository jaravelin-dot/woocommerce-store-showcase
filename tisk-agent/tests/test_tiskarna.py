"""Tisk. Skutečná tiskárna se netestuje — sleduje se, jaké příkazy padnou ven."""

from pathlib import Path

import pytest

from shop_tisk.tiskarna import Tiskarna, ChybaTisku


def test_vytiskne_pres_chrome_a_sumatru(tmp_path, monkeypatch):
    prikazy = []

    def falesny_run(prikaz, **kwargs):
        prikazy.append(prikaz)

        # Chrome má po sobě nechat PDF; vyrobíme ho, ať se nezdá, že chybí.
        for argument in prikaz:
            if str(argument).startswith("--print-to-pdf="):
                Path(str(argument).split("=", 1)[1]).write_bytes(b"%PDF-1.4 test")

        class Vysledek:
            returncode = 0
            stderr = ""

        return Vysledek()

    monkeypatch.setattr("shop_tisk.tiskarna.subprocess.run", falesny_run)

    tiskarna = Tiskarna(chrome=Path("chrome.exe"), sumatra=Path("SumatraPDF.exe"))
    tiskarna.vytiskni("<html><body>test</body></html>")

    assert len(prikazy) == 2
    assert any("--headless" in str(a) for a in prikazy[0])
    assert "-print-to-default" in [str(a) for a in prikazy[1]]
    assert "-silent" in [str(a) for a in prikazy[1]]


def test_selhani_tisku_vyhodi_vyjimku(tmp_path, monkeypatch):
    def falesny_run(prikaz, **kwargs):
        for argument in prikaz:
            if str(argument).startswith("--print-to-pdf="):
                Path(str(argument).split("=", 1)[1]).write_bytes(b"%PDF-1.4 test")

        class Vysledek:
            # Sumatra skončí nenulově = papír nevyjel.
            returncode = 0 if "--headless" in [str(a) for a in prikaz] else 1
            stderr = "tiskarna neodpovida"

        return Vysledek()

    monkeypatch.setattr("shop_tisk.tiskarna.subprocess.run", falesny_run)

    tiskarna = Tiskarna(chrome=Path("chrome.exe"), sumatra=Path("SumatraPDF.exe"))

    with pytest.raises(ChybaTisku):
        tiskarna.vytiskni("<html></html>")


def test_chrome_bez_pdf_je_chyba(monkeypatch):
    def falesny_run(prikaz, **kwargs):
        class Vysledek:
            returncode = 0
            stderr = ""

        return Vysledek()  # PDF schválně nevznikne

    monkeypatch.setattr("shop_tisk.tiskarna.subprocess.run", falesny_run)

    tiskarna = Tiskarna(chrome=Path("chrome.exe"), sumatra=Path("SumatraPDF.exe"))

    with pytest.raises(ChybaTisku):
        tiskarna.vytiskni("<html></html>")


def test_tiskne_se_barevne(monkeypatch):
    """Barva je na výdejce hlavní vodítko, podle čeho skladník pozná zboží.

    Bez `-print-settings color` by se tisklo podle toho, co má tiskárna zrovna
    přednastavené — a černobílá výdejka je k poznání barvy k ničemu.
    """
    prikazy = []

    def falesny_run(prikaz, **kwargs):
        prikazy.append([str(a) for a in prikaz])

        for argument in prikaz:
            if str(argument).startswith("--print-to-pdf="):
                Path(str(argument).split("=", 1)[1]).write_bytes(b"%PDF-1.4 test")

        class Vysledek:
            returncode = 0
            stderr = ""

        return Vysledek()

    monkeypatch.setattr("shop_tisk.tiskarna.subprocess.run", falesny_run)

    Tiskarna(chrome=Path("chrome.exe"), sumatra=Path("SumatraPDF.exe")).vytiskni("<html></html>")

    prikaz_tisku = prikazy[1]
    assert "-print-settings" in prikaz_tisku
    assert prikaz_tisku[prikaz_tisku.index("-print-settings") + 1] == "color"
