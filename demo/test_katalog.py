"""Kontrola vymyšleného katalogu: EAN jen v interním rozsahu 200 s platnou kontrolní číslicí."""

import json
from pathlib import Path

import generuj_katalog as g

KATALOG = json.loads((Path(__file__).parent / "katalog.json").read_text(encoding="utf-8"))


def test_kontrolni_cislice_znameho_kodu():
    assert g.kontrolni_cislice("200000000001") == 5


def test_ean_v_internim_rozsahu_a_platne():
    eany = [v["ean"] for p in KATALOG["produkty"] for v in p["varianty"]]
    assert all(e.startswith("200") and len(e) == 13 for e in eany)
    assert all(g.kontrolni_cislice(e[:12]) == int(e[12]) for e in eany)
    assert len(eany) == len(set(eany))


def test_kody_zbozi_jsou_unikatni():
    kody = [v["sku"] for p in KATALOG["produkty"] for v in p["varianty"]] + [p["sku"] for p in KATALOG["produkty"]]
    assert len(kody) == len(set(kody))


def test_ceny_na_cele_koruny_a_vetsi_velikosti_drazsi():
    for p in KATALOG["produkty"]:
        ceny = {v["velikost"]: v["cena"] for v in p["varianty"]}
        assert all(isinstance(c, int) and c > 0 for c in ceny.values())
        if "3XL" in ceny:
            assert ceny["3XL"] > ceny["M"]


def test_generator_je_deterministicky():
    assert g.ean(3, 5) == g.ean(3, 5)
    assert g.sklad(3, "44") == g.sklad(3, "44")
