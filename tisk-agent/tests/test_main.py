"""main(): rozbitý config nesmí nikdy zmizet beze stopy.

Recenze druhého kola: config se syntaktickou chybou (chybějící hlavička
[web], typicky vznikne ruční editací v Poznámkovém bloku) vyhazuje
configparser.MissingSectionHeaderError. Dřívější `except (FileNotFoundError,
KeyError)` ji nechytá — chyba proletí ven, log zůstane prázdný a návratový
kód je 0. Obsluha (prodejna bez konzole) se pak nemá kam podívat."""

import logging

import pytest

from shop_tisk import __main__ as hlavni


@pytest.fixture(autouse=True)
def _uklid_logovacich_handleru():
    """Po testu handler zase zavřít, ať na Windows nezůstane otevřený
    soubor v tmp_path, který si pytest chce po testu smazat."""
    yield
    for handler in list(logging.root.handlers):
        logging.root.removeHandler(handler)
        handler.close()


def _vycisti_root_handlery():
    """logging.basicConfig() je no-op, pokud má root logger už nějaký
    handler — a pytest sám jeden přidává (kvůli caplog) těsně před tělem
    testu, tedy PO fixturách. Proto se to musí volat přímo v těle testu,
    ne ve fixtuře. V ostrém běhu (python -m shop_tisk) je root logger na
    startu vždy prázdný, tohle je čistě obchod kolem pytestího zachytávání."""
    for handler in list(logging.root.handlers):
        logging.root.removeHandler(handler)
        handler.close()


def test_main_pri_syntakticky_vadnem_configu_zapise_chybu_do_logu_a_skonci_nenulove(tmp_path, monkeypatch):
    slozka = tmp_path / "shop-tisk"
    slozka.mkdir()
    # Chybí řádek "[web]" — přesně chyba, kterou udělá ruční editace v Poznámkovém bloku.
    (slozka / "config.ini").write_text(
        "url = https://obchod.example.cz\nuzivatel = tisk-agent\nheslo = tajne\n",
        encoding="utf-8",
    )
    monkeypatch.setattr(hlavni, "SLOZKA", slozka)
    _vycisti_root_handlery()

    with pytest.raises(SystemExit) as vyjimka:
        hlavni.main()

    assert vyjimka.value.code != 0

    log_obsah = (slozka / "tisk.log").read_text(encoding="utf-8")
    assert "config je vadný" in log_obsah
