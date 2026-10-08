"""Smyčka. Nejdůležitější pravidlo: potvrdit smí AŽ po vytištění."""

import pytest
import requests

from shop_tisk.smycka import Smycka
from shop_tisk.tiskarna import ChybaTisku


class FalesnyKlient:
    def __init__(self, ulohy):
        self._ulohy = ulohy
        self.potvrzene = []

    def fronta(self):
        return list(self._ulohy)

    def html(self, uloha_id):
        return f"<html>{uloha_id}</html>"

    def hotovo(self, uloha_id):
        self.potvrzene.append(uloha_id)
        return True, ""


class FalesnaTiskarna:
    def __init__(self, selze=False):
        self.selze = selze
        self.vytistene = []

    def vytiskni(self, html):
        if self.selze:
            raise ChybaTisku("tiskarna neodpovida")

        self.vytistene.append(html)


def test_vytiskne_a_potvrdi():
    klient = FalesnyKlient([{"id": 7, "objednavka": "1042", "druh": "vydejka"}])
    tiskarna = FalesnaTiskarna()

    assert Smycka(klient, tiskarna).jedno_kolo() == 1
    assert tiskarna.vytistene == ["<html>7</html>"]
    assert klient.potvrzene == [7]


def test_kdyz_tisk_selze_NEPOTVRDI():
    """Kdyby se potvrdilo i po chybě, objednávka zmizí a nikdo se to nedozví."""
    klient = FalesnyKlient([{"id": 7, "objednavka": "1042", "druh": "vydejka"}])
    tiskarna = FalesnaTiskarna(selze=True)

    assert Smycka(klient, tiskarna).jedno_kolo() == 0
    assert klient.potvrzene == []


def test_prazdna_fronta_nic_nedela():
    klient = FalesnyKlient([])
    tiskarna = FalesnaTiskarna()

    assert Smycka(klient, tiskarna).jedno_kolo() == 0
    assert tiskarna.vytistene == []


def test_upozorni_az_po_hodine_neuspechu():
    klient = FalesnyKlient([{"id": 7, "objednavka": "1042", "druh": "vydejka"}])
    tiskarna = FalesnaTiskarna(selze=True)
    upozorneni = []

    cas = [0.0]
    smycka = Smycka(klient, tiskarna, upozorni=upozorneni.append, hodiny=lambda: cas[0])

    smycka.jedno_kolo()
    assert upozorneni == []

    cas[0] = 3601.0
    smycka.jedno_kolo()
    assert len(upozorneni) == 1
    assert "tisk" in upozorneni[0].lower()


class FalesnyKlientSitovaChyba:
    """html() vyhodí requests.RequestException uprostřed kola — např. výpadek
    sítě mezi vyzvednutím fronty a stažením HTML první úlohy. Smyčka to nesmí
    nechat propadnout ven — jinak by se nezpracovaly ani další úlohy ve frontě."""

    def __init__(self, ulohy):
        self._ulohy = ulohy
        self.potvrzene = []
        self.html_volano = []

    def fronta(self):
        return list(self._ulohy)

    def html(self, uloha_id):
        self.html_volano.append(uloha_id)
        if uloha_id == 7:
            raise requests.RequestException("vypadek site")
        return f"<html>{uloha_id}</html>"

    def hotovo(self, uloha_id):
        self.potvrzene.append(uloha_id)
        return True, ""


def test_sitova_chyba_u_jedne_ulohy_nezastavi_ostatni():
    """html() vyhodí síťovou výjimku u úlohy 7 → ta se nepotvrdí, ale úloha 8
    v pořadí za ní se přesto zpracuje a vytiskne."""
    klient = FalesnyKlientSitovaChyba(
        [
            {"id": 7, "objednavka": "1042", "druh": "vydejka"},
            {"id": 8, "objednavka": "1043", "druh": "vydejka"},
        ]
    )
    tiskarna = FalesnaTiskarna()

    assert Smycka(klient, tiskarna).jedno_kolo() == 1
    assert klient.potvrzene == [8]
    assert tiskarna.vytistene == ["<html>8</html>"]


# --- Nález 1: hodinové upozornění se nesmí spolehnout na to, že fronta()
# vrací [] i tehdy, když spojení vůbec nefunguje. Testy níž ověřují, že
# rozdíl mezi "None" (spojení selhalo) a "[]" (opravdu prázdno) na chování
# smyčky poznat je. ---


class FalesnyKlientBezSpojeni:
    """Simuluje trvale nefunkční spojení (špatné heslo, výpadek serveru).
    fronta() musí vracet None — jasně jinou hodnotu než prázdný seznam,
    který znamená "mluvili jsme se serverem, jen není co dělat"."""

    def fronta(self):
        return None

    def html(self, uloha_id):
        raise AssertionError("html() se nemá volat, když fronta() vrátila None")

    def hotovo(self, uloha_id):
        raise AssertionError("hotovo() se nemá volat, když fronta() vrátila None")


def test_upozorni_po_hodine_trvale_nefunkcniho_spojeni():
    """70 kol po sobě (přes hodinu) fronta() vrátí None → přijde právě
    jedno upozornění. Bez rozlišení None/[] se to chová jako pořád prázdná
    fronta, počítadlo se každé kolo resetuje a upozornění nepřijde nikdy —
    přesně bug, který by špatné heslo v configu udělalo navždy tiché."""
    klient = FalesnyKlientBezSpojeni()
    tiskarna = FalesnaTiskarna()
    upozorneni = []

    cas = [0.0]
    smycka = Smycka(klient, tiskarna, upozorni=upozorneni.append, hodiny=lambda: cas[0])

    for i in range(70):
        cas[0] = i * 60.0  # jedno kolo za minutu
        smycka.jedno_kolo()

    assert len(upozorneni) == 1
    assert "tisk" in upozorneni[0].lower()


class FalesnyKlientStridani:
    """Střídá kolo, kdy je co tisknout a tisk selže, s kolem, kdy spojení
    selže úplně (fronta() vrátí None). Ani jedno kolo není opravdu úspěšné
    — tohle je přesně scénář, který recenzent nasimuloval a vyšla z něj
    nula upozornění."""

    def __init__(self):
        self._pocitadlo = 0

    def fronta(self):
        self._pocitadlo += 1

        if self._pocitadlo % 2 == 1:
            return [{"id": 7, "objednavka": "1042", "druh": "vydejka"}]

        return None

    def html(self, uloha_id):
        return f"<html>{uloha_id}</html>"

    def hotovo(self, uloha_id):
        raise AssertionError("hotovo() se nemá volat, když tisk selhal")


def test_upozorni_pri_stridani_selhani_tisku_a_vypadku_spojeni():
    klient = FalesnyKlientStridani()
    tiskarna = FalesnaTiskarna(selze=True)
    upozorneni = []

    cas = [0.0]
    smycka = Smycka(klient, tiskarna, upozorni=upozorneni.append, hodiny=lambda: cas[0])

    for i in range(70):
        cas[0] = i * 60.0
        smycka.jedno_kolo()

    assert len(upozorneni) == 1


def test_opravdu_prazdna_fronta_dlouho_neupozorni():
    """Tichá prodejna bez objednávek přes hodinu není porucha — se serverem
    se mluvilo v pořádku, jen nebylo co dělat."""
    klient = FalesnyKlient([])
    tiskarna = FalesnaTiskarna()
    upozorneni = []

    cas = [0.0]
    smycka = Smycka(klient, tiskarna, upozorni=upozorneni.append, hodiny=lambda: cas[0])

    for i in range(70):
        cas[0] = i * 60.0
        smycka.jedno_kolo()

    assert upozorneni == []


# --- Nález 2: hotovo() musí být uvnitř ošetřeného bloku, jinak výpadek
# zrovna při potvrzování shodí zpracování zbylých úloh v kole. ---


class FalesnyKlientChybaPriHotovo:
    """hotovo() u úlohy 7 vyhodí síťovou výjimku — výpadek přijde zrovna
    při potvrzování, ne při tisku. Úloha 8 v pořadí za ní se musí přesto
    zpracovat, jinak by čekala 10 minut místo minuty (server drží
    vyzvednuté úlohy 10 minut)."""

    def __init__(self, ulohy):
        self._ulohy = ulohy
        self.potvrzene = []

    def fronta(self):
        return list(self._ulohy)

    def html(self, uloha_id):
        return f"<html>{uloha_id}</html>"

    def hotovo(self, uloha_id):
        if uloha_id == 7:
            raise requests.RequestException("vypadek pri potvrzovani")

        self.potvrzene.append(uloha_id)
        return True, ""


def test_selhani_hotovo_u_prvni_ulohy_nezastavi_druhou():
    klient = FalesnyKlientChybaPriHotovo(
        [
            {"id": 7, "objednavka": "1042", "druh": "vydejka"},
            {"id": 8, "objednavka": "1043", "druh": "vydejka"},
        ]
    )
    tiskarna = FalesnaTiskarna()

    assert Smycka(klient, tiskarna).jedno_kolo() == 1
    assert klient.potvrzene == [8]
    assert tiskarna.vytistene == ["<html>7</html>", "<html>8</html>"]


# --- Druhé kolo recenze, nález 1: server si vyzvednutou úlohu drží 10 minut,
# takže MEZI POKUSY vrací opravdovou prázdnou frontu ([]) — i uprostřed
# nepřetržité poruchy. Dosavadní pravidlo "prázdno = resetuj počítadlo" na to
# nebylo připravené: recenzent pustil 400 kol s trvale rozbitou tiskárnou a
# úloha se objevovala jednou za 10 kol (10 minut hold), zbytek kol [] —
# vyšlo 0 upozornění za 6,7 hodiny. ---


class FalesnyKlientPrerusovanaFronta:
    """Server drží vyzvednutou úlohu 10 minut. Smyčka běží jednou za minutu,
    takže mezi pokusy vidí OPRAVDOVOU prázdnou frontu ([]), ne None. Jednou
    za 10 kol se úloha znovu objeví (hold vypršel), tiskárna je ale trvale
    rozbitá, takže se nikdy nevytiskne ani nepotvrdí — porucha běží
    nepřetržitě, přestože fronta() z devíti kol z deseti vrací []."""

    def __init__(self):
        self._pocitadlo = 0

    def fronta(self):
        self._pocitadlo += 1

        if self._pocitadlo % 10 == 1:
            return [{"id": 7, "objednavka": "1042", "druh": "vydejka"}]

        return []

    def html(self, uloha_id):
        return f"<html>{uloha_id}</html>"

    def hotovo(self, uloha_id):
        raise AssertionError("hotovo() se nemá volat, když tisk selhal")


def test_prerusovana_prazdna_fronta_uprostred_trvale_rozbite_tiskarny_musi_upozornit():
    """Test (e) z recenze. 400 kol (jedno za minutu, 6,7 hodiny), tiskárna
    trvale rozbitá, úloha se objeví jednou za 10 kol, mezitím opravdu
    prázdná fronta. Přes hodinu nepřetržité poruchy MUSÍ přijít upozornění —
    to, že fronta() mezi pokusy vrací [], není důkaz, že je vše v pořádku."""
    klient = FalesnyKlientPrerusovanaFronta()
    tiskarna = FalesnaTiskarna(selze=True)
    upozorneni = []

    cas = [0.0]
    smycka = Smycka(klient, tiskarna, upozorni=upozorneni.append, hodiny=lambda: cas[0])

    for i in range(400):
        cas[0] = i * 60.0
        smycka.jedno_kolo()

    assert len(upozorneni) >= 1
    assert "tisk" in upozorneni[0].lower()


class FalesnyKlientSelhaniPakUspechPakTicho:
    """Dvě kola je co tisknout, pak fronta natrvalo zmlkne (opravdu prázdná —
    tichá prodejna po vyřízení). Tiskárna se v testu mezi prvním a druhým
    kolem sama „opraví" (viz test níž), takže druhé kolo je skutečný
    úspěch."""

    def __init__(self):
        self._faze = 0

    def fronta(self):
        self._faze += 1

        if self._faze <= 2:
            return [{"id": 7, "objednavka": "1042", "druh": "vydejka"}]

        return []

    def html(self, uloha_id):
        return f"<html>{uloha_id}</html>"

    def hotovo(self, uloha_id):
        return True, ""


def test_uspech_po_selhani_vynuluje_prizak_a_nasledne_dlouhe_ticho_neupozorni():
    """Test (f). Nejdřív selhání (tiskárna nejde), pak úspěch (opravila se),
    pak dlouhé ticho (opravdu prázdná fronta) přes hodinu. Úspěch musí
    příznak `bylo_selhani` zrušit — jinak by tiché hodiny PO úspěchu spustily
    zbytečné upozornění, přestože je vše v pořádku."""
    klient = FalesnyKlientSelhaniPakUspechPakTicho()
    tiskarna = FalesnaTiskarna(selze=True)
    upozorneni = []

    cas = [0.0]
    smycka = Smycka(klient, tiskarna, upozorni=upozorneni.append, hodiny=lambda: cas[0])

    cas[0] = 0.0
    assert smycka.jedno_kolo() == 0  # tisk selže
    assert upozorneni == []

    tiskarna.selze = False
    cas[0] = 60.0
    assert smycka.jedno_kolo() == 1  # skutečný úspěch, příznak se zruší

    for i in range(2, 130):  # přes hodinu opravdu prázdné fronty
        cas[0] = i * 60.0
        smycka.jedno_kolo()

    assert upozorneni == []


# --- D2 ze závěrečné recenze: klient.hotovo() teď vrací dvojici
# (potvrzeno, duvod). 'jiz_vytisteno' je neškodná duplicita a NESMÍ se
# počítat jako selhání (planý poplach), 'nevyzvednuto' a 404 jsou skutečné
# selhání a mají být vidět v logu s vysvětlením. ---


class FalesnyKlientJizVytisteno:
    """Server u KAŽDÉ úlohy odpoví 409 s duvod=jiz_vytisteno — jako by si
    tenhle běh agenta (např. po restartu) šlápl na paty jinému, který
    stejnou úlohu už potvrdil. Papír se vytiskl (tiskárna neselhává), jen
    potvrzení přišlo pozdě — to je neškodné, ne porucha."""

    def __init__(self, ulohy):
        self._ulohy = ulohy

    def fronta(self):
        return list(self._ulohy)

    def html(self, uloha_id):
        return f"<html>{uloha_id}</html>"

    def hotovo(self, uloha_id):
        return True, "jiz_vytisteno"


def test_jiz_vytisteno_se_pocita_jako_uspech_ne_jako_selhani():
    klient = FalesnyKlientJizVytisteno([{"id": 7, "objednavka": "1042", "druh": "vydejka"}])
    tiskarna = FalesnaTiskarna()

    assert Smycka(klient, tiskarna).jedno_kolo() == 1


def test_jiz_vytisteno_opakovane_hodinu_nezpusobi_planzy_poplach(caplog):
    """Recenze: 'Důsledek: jiz_vytisteno nastaví příznak poruchy a může
    vyrobit planý poplach.' I když se to děje opakovaně přes hodinu, žádné
    upozornění přijít nesmí — z pohledu agenta je to úspěch."""
    klient = FalesnyKlientJizVytisteno([{"id": 7, "objednavka": "1042", "druh": "vydejka"}])
    tiskarna = FalesnaTiskarna()
    upozorneni = []

    cas = [0.0]
    smycka = Smycka(klient, tiskarna, upozorni=upozorneni.append, hodiny=lambda: cas[0])

    for i in range(70):
        cas[0] = i * 60.0
        smycka.jedno_kolo()

    assert upozorneni == []


class FalesnyKlientNevyzvednuto:
    """Server trvale odpovídá 409 duvod=nevyzvednuto — server si úlohu sám
    uvolnil (vypršelo 10minutové okno) dřív, než agent stihl potvrdit."""

    def __init__(self, ulohy):
        self._ulohy = ulohy

    def fronta(self):
        return list(self._ulohy)

    def html(self, uloha_id):
        return f"<html>{uloha_id}</html>"

    def hotovo(self, uloha_id):
        return False, "nevyzvednuto"


def test_nevyzvednuto_se_pocita_jako_selhani():
    klient = FalesnyKlientNevyzvednuto([{"id": 7, "objednavka": "1042", "druh": "vydejka"}])
    tiskarna = FalesnaTiskarna()

    assert Smycka(klient, tiskarna).jedno_kolo() == 0


def test_nevyzvednuto_opakovane_hodinu_upozorni_a_duvod_je_v_logu(caplog):
    klient = FalesnyKlientNevyzvednuto([{"id": 7, "objednavka": "1042", "druh": "vydejka"}])
    tiskarna = FalesnaTiskarna()
    upozorneni = []

    cas = [0.0]
    smycka = Smycka(klient, tiskarna, upozorni=upozorneni.append, hodiny=lambda: cas[0])

    with caplog.at_level("ERROR"):
        for i in range(70):
            cas[0] = i * 60.0
            smycka.jedno_kolo()

    assert len(upozorneni) == 1
    assert any("nevyzvednuto" in zaznam.message for zaznam in caplog.records)


def test_404_se_pocita_jako_selhani_a_duvod_je_v_logu(caplog):
    """404 (úloha vůbec neexistuje) je taky selhání, ne tichá duplicita."""

    class FalesnyKlient404:
        def fronta(self):
            return [{"id": 7, "objednavka": "1042", "druh": "vydejka"}]

        def html(self, uloha_id):
            return f"<html>{uloha_id}</html>"

        def hotovo(self, uloha_id):
            return False, "http_404"

    tiskarna = FalesnaTiskarna()

    with caplog.at_level("ERROR"):
        assert Smycka(FalesnyKlient404(), tiskarna).jedno_kolo() == 0

    assert any("http_404" in zaznam.message for zaznam in caplog.records)
