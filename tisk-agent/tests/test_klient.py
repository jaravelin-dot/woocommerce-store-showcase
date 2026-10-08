"""Klient k webu. Skutečná síť se netestuje — podstrčí se falešná odpověď."""

import json
from pathlib import Path

import pytest
import requests

from shop_tisk.klient import Klient
from shop_tisk.nastaveni import Nastaveni


class FalesnaOdpoved:
    def __init__(self, stav: int, telo: str):
        self.status_code = stav
        self.text = telo

    def json(self):
        return json.loads(self.text)


@pytest.fixture
def nastaveni():
    return Nastaveni(url="https://obchod.example.cz", uzivatel="tisk-agent", heslo="tajne")


def test_fronta_vrati_seznam_uloh(nastaveni, monkeypatch):
    def falesne_get(url, **kwargs):
        assert url == "https://obchod.example.cz/wp-json/shop/v1/tisk/fronta"
        return FalesnaOdpoved(200, '{"ulohy":[{"id":7,"objednavka":"1042","druh":"vydejka"}]}')

    klient = Klient(nastaveni)
    monkeypatch.setattr(klient.relace, "get", falesne_get)

    assert klient.fronta() == [{"id": 7, "objednavka": "1042", "druh": "vydejka"}]


def test_fronta_pri_chybovem_stavu_vrati_none_a_nespadne(nastaveni, monkeypatch):
    """Jiná odpověď než 200 (např. 401 při špatném heslu) se nesmí tvářit
    jako prázdná fronta — Smycka na None a [] reaguje jinak."""
    def falesne_get(url, **kwargs):
        return FalesnaOdpoved(500, "chyba serveru")

    klient = Klient(nastaveni)
    monkeypatch.setattr(klient.relace, "get", falesne_get)

    assert klient.fronta() is None


def test_fronta_pri_vypadku_site_vrati_none_a_nespadne(nastaveni, monkeypatch):
    """Výpadek spojení (RequestException) je taky "None", ne prázdný seznam."""
    def falesne_get(url, **kwargs):
        raise requests.RequestException("vypadek site")

    klient = Klient(nastaveni)
    monkeypatch.setattr(klient.relace, "get", falesne_get)

    assert klient.fronta() is None


def test_hotovo_potvrdi_jen_pri_dvoustovce(nastaveni, monkeypatch):
    """hotovo() vrací dvojici (potvrzeno, duvod) — viz D2 v zaver-fix.md.
    Server u 409 bez `duvod` (neočekávaný tvar odpovědi) se bere jako
    obyčejné selhání, ne jako neškodná duplicita."""
    klient = Klient(nastaveni)

    monkeypatch.setattr(klient.relace, "post", lambda url, **k: FalesnaOdpoved(200, '{"ok":true}'))
    assert klient.hotovo(7) == (True, "")

    monkeypatch.setattr(klient.relace, "post", lambda url, **k: FalesnaOdpoved(409, '{"ok":false}'))
    assert klient.hotovo(7) == (False, "")


def test_hotovo_409_jiz_vytisteno_je_neskodna_duplicita(nastaveni, monkeypatch):
    """409 s duvod=jiz_vytisteno (viz class-shop-tisk.php) znamená, že
    úlohu potvrdil někdo/něco už dřív — potvrzeno se má brát jako True, aby
    to nezpůsobilo planý poplach (NÁLEZ D2 ze závěrečné recenze)."""
    klient = Klient(nastaveni)

    monkeypatch.setattr(
        klient.relace, "post", lambda url, **k: FalesnaOdpoved(409, '{"ok":false,"duvod":"jiz_vytisteno"}')
    )
    assert klient.hotovo(7) == (True, "jiz_vytisteno")


def test_hotovo_409_nevyzvednuto_je_selhani(nastaveni, monkeypatch):
    """409 s duvod=nevyzvednuto znamená, že server úlohu mezitím sám uvolnil
    (>10 minut) — to JE selhání, úloha se musí zkusit znovu."""
    klient = Klient(nastaveni)

    monkeypatch.setattr(
        klient.relace, "post", lambda url, **k: FalesnaOdpoved(409, '{"ok":false,"duvod":"nevyzvednuto"}')
    )
    assert klient.hotovo(7) == (False, "nevyzvednuto")


def test_hotovo_404_je_selhani(nastaveni, monkeypatch):
    """404 (úloha vůbec neexistuje) je taky selhání, ne tichá duplicita."""
    klient = Klient(nastaveni)

    monkeypatch.setattr(klient.relace, "post", lambda url, **k: FalesnaOdpoved(404, "Not Found"))
    assert klient.hotovo(7) == (False, "http_404")


def test_nastaveni_se_nacte_ze_souboru(tmp_path: Path):
    soubor = tmp_path / "config.ini"
    soubor.write_text(
        "[web]\nurl = https://obchod.example.cz\nuzivatel = tisk-agent\nheslo = abcd efgh\n",
        encoding="utf-8",
    )

    n = Nastaveni.nacti(soubor)

    assert n.url == "https://obchod.example.cz"
    assert n.uzivatel == "tisk-agent"
    assert n.heslo == "abcd efgh"


def test_repr_neobsahuje_heslo(nastaveni):
    """Heslo nesmí být vidět v repr() — mohlo by skončit v logu."""
    r = repr(nastaveni)
    assert "tajne" not in r
    assert "heslo" not in r  # pole je skryto s repr=False


def test_klient_nastavuje_autentizaci(nastaveni):
    """Klient musí nastavit HTTP Basic Auth s username a heslem."""
    klient = Klient(nastaveni)

    assert klient.relace.auth == ("tisk-agent", "tajne")


def test_html_vrati_odpoved_pri_200(nastaveni, monkeypatch):
    """html() vrátí tělo odpovědi, když server vrátí 200."""
    def falesne_get(url, **kwargs):
        assert url == "https://obchod.example.cz/wp-json/shop/v1/tisk/uloha/7"
        return FalesnaOdpoved(200, "<html>obsah úlohy</html>")

    klient = Klient(nastaveni)
    monkeypatch.setattr(klient.relace, "get", falesne_get)

    vysledek = klient.html(7)
    assert vysledek == "<html>obsah úlohy</html>"


def test_html_vyhodí_chybu_pri_404(nastaveni, monkeypatch):
    """html() vyhodí RuntimeError, když server vrátí jinou než 200."""
    def falesne_get(url, **kwargs):
        assert url == "https://obchod.example.cz/wp-json/shop/v1/tisk/uloha/7"
        return FalesnaOdpoved(404, "Not Found")

    klient = Klient(nastaveni)
    monkeypatch.setattr(klient.relace, "get", falesne_get)

    with pytest.raises(RuntimeError, match="Úloha 7 vrátila 404"):
        klient.html(7)


def test_hotovo_vola_spravnou_adresu(nastaveni, monkeypatch):
    """hotovo() musí volat POST na správnou adresu."""
    volane_adresy = []

    def falesne_post(url, **kwargs):
        volane_adresy.append(url)
        return FalesnaOdpoved(200, "{}")

    klient = Klient(nastaveni)
    monkeypatch.setattr(klient.relace, "post", falesne_post)

    klient.hotovo(7)

    assert volane_adresy == ["https://obchod.example.cz/wp-json/shop/v1/tisk/uloha/7/hotovo"]
