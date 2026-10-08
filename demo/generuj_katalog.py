"""Vygeneruje ukázkový katalog (demo/katalog.json) a jednoduché obrázky zboží (demo/obrazky/*.png).

Všechno je vymyšlené: modely, značky, ceny, sklad. EAN jsou v interním rozsahu 200
(nikdy nekolidují se skutečným zbožím) a mají platnou kontrolní číslici. Obrázky jsou
siluety kreslené přímo tady, bez fotek. Výstup je deterministický — opakované spuštění
dá stejné soubory.

Spuštění:  python demo/generuj_katalog.py
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

from PIL import Image, ImageDraw

ZDE = Path(__file__).parent
OBRAZKY = ZDE / "obrazky"

KATEGORIE = [
    # slug, název, rodič, popis
    ("obuv", "Obuv", "", "Pracovní a bezpečnostní obuv. Na prodejně si ji vyzkoušíte, na webu vidíte, které velikosti máme skladem."),
    ("bezpecnostni-obuv", "Bezpečnostní obuv", "obuv", "Obuv s ochrannou špičkou ve třídách S1P až S3. Planžeta chrání proti propíchnutí."),
    ("pracovni-obuv", "Pracovní obuv", "obuv", "Obuv bez ochranné špičky (O1, O2) do kuchyně, zdravotnictví a na celý den na nohou."),
    ("holinky", "Holínky", "obuv", "Holínky do vody a bláta, třída S5 s ochrannou špičkou a planžetou."),
    ("odevy", "Oděvy", "", "Pracovní kalhoty, bundy, vesty, trička a mikiny na práci i do zimy."),
    ("kalhoty", "Pracovní kalhoty", "odevy", "Kalhoty do pasu, s laclem, kraťasy a zateplené kalhoty."),
    ("bundy-a-vesty", "Bundy a vesty", "odevy", "Softshellové a zimní bundy, zateplené a reflexní vesty."),
    ("trika-a-mikiny", "Trička a mikiny", "odevy", "Pracovní trička, reflexní trička a mikiny."),
    ("rukavice", "Rukavice", "", "Montážní, zimní a nitrilové rukavice ve velikostech 7 až 11."),
]

OBUV_PANSKA = [str(v) for v in range(39, 48)]
OBUV_DAMSKA = [str(v) for v in range(36, 43)]
OBUV_UNISEX = [str(v) for v in range(37, 48)]
ODEVY = ["S", "M", "L", "XL", "2XL", "3XL"]
ODEVY_DAMSKE = ["XS", "S", "M", "L", "XL"]
RUKAVICE = ["7", "8", "9", "10", "11"]

# nazev, kategorie, cena s DPH, velikosti, obrazek(tvar, barva), atributy
PRODUKTY = [
    ("Bezpečnostní polobotka ARVETO S1P", "bezpecnostni-obuv", 1290, OBUV_UNISEX, ("polobotka", "#6b7280"),
     {"pa_bezpecnostni_trida": ["S1P"], "pa_pohlavi": ["unisex"], "pa_barva": ["šedá"], "pa_prostredi": ["sklad a logistika"], "pa_material": ["textil"], "pa_vlastnosti": ["lehké"]}),
    ("Bezpečnostní polobotka ARVETO S3", "bezpecnostni-obuv", 1390, OBUV_UNISEX, ("polobotka", "#1f2937"),
     {"pa_bezpecnostni_trida": ["S3"], "pa_pohlavi": ["unisex"], "pa_barva": ["černá"], "pa_prostredi": ["sklad a logistika", "dílna"], "pa_material": ["kůže"]}),
    ("Kotníková obuv BREMOK S3", "bezpecnostni-obuv", 1690, OBUV_PANSKA, ("kotnik", "#7c4a1e"),
     {"pa_bezpecnostni_trida": ["S3"], "pa_pohlavi": ["pánské"], "pa_barva": ["hnědá"], "pa_prostredi": ["stavba"], "pa_material": ["kůže"], "pa_vlastnosti": ["nepromokavé"]}),
    ("Kotníková obuv BREMOK S3 zimní", "bezpecnostni-obuv", 1890, OBUV_PANSKA, ("kotnik", "#3f2a17"),
     {"pa_bezpecnostni_trida": ["S3"], "pa_pohlavi": ["pánské"], "pa_barva": ["hnědá"], "pa_prostredi": ["stavba"], "pa_material": ["kůže"], "pa_vlastnosti": ["zateplené", "nepromokavé"]}),
    ("Kotníková obuv TELMARO S3 s membránou", "bezpecnostni-obuv", 2190, OBUV_PANSKA, ("kotnik", "#4b5563"),
     {"pa_bezpecnostni_trida": ["S3"], "pa_pohlavi": ["unisex"], "pa_barva": ["šedá"], "pa_prostredi": ["stavba"], "pa_material": ["kůže a textil"], "pa_vlastnosti": ["nepromokavé", "lehké"]}),
    ("Dámská polobotka LUVENA S1P", "bezpecnostni-obuv", 1190, OBUV_DAMSKA, ("polobotka", "#be185d"),
     {"pa_bezpecnostni_trida": ["S1P"], "pa_pohlavi": ["dámské"], "pa_barva": ["růžová"], "pa_prostredi": ["sklad a logistika"], "pa_material": ["textil"], "pa_vlastnosti": ["lehké"]}),
    ("Bezpečnostní sandál VESMIR S1P", "bezpecnostni-obuv", 990, OBUV_UNISEX, ("sandal", "#1e3a5f"),
     {"pa_bezpecnostni_trida": ["S1P"], "pa_pohlavi": ["unisex"], "pa_barva": ["modrá"], "pa_prostredi": ["sklad a logistika"], "pa_material": ["mikrovlákno"], "pa_vlastnosti": ["lehké"]}),
    ("Bezpečnostní polobotka KADENZO S2", "bezpecnostni-obuv", 1090, OBUV_UNISEX, ("polobotka", "#e5e7eb"),
     {"pa_bezpecnostni_trida": ["S2"], "pa_pohlavi": ["unisex"], "pa_barva": ["bílá"], "pa_prostredi": ["gastro"], "pa_material": ["mikrovlákno"]}),
    ("Dámská pracovní obuv FENTARA O1", "pracovni-obuv", 790, OBUV_DAMSKA, ("polobotka", "#f3f4f6"),
     {"pa_bezpecnostni_trida": ["O1"], "pa_pohlavi": ["dámské"], "pa_barva": ["bílá"], "pa_prostredi": ["gastro"], "pa_material": ["kůže"], "pa_vlastnosti": ["lehké"]}),
    ("Pracovní pantofle PULVERO O1", "pracovni-obuv", 590, OBUV_UNISEX, ("pantofel", "#ffffff"),
     {"pa_bezpecnostni_trida": ["O1"], "pa_pohlavi": ["unisex"], "pa_barva": ["bílá"], "pa_prostredi": ["gastro"], "pa_material": ["kůže"], "pa_vlastnosti": ["lehké"]}),
    ("Pracovní kotníková obuv TRAVELO O2", "pracovni-obuv", 1290, OBUV_PANSKA, ("kotnik", "#57534e"),
     {"pa_bezpecnostni_trida": ["O2"], "pa_pohlavi": ["pánské"], "pa_barva": ["šedá"], "pa_prostredi": ["dílna"], "pa_material": ["kůže"]}),
    ("Holínky KALVORO S5", "holinky", 990, OBUV_PANSKA, ("holinka", "#14532d"),
     {"pa_bezpecnostni_trida": ["S5"], "pa_pohlavi": ["unisex"], "pa_barva": ["zelená"], "pa_prostredi": ["stavba"], "pa_material": ["PVC"], "pa_vlastnosti": ["nepromokavé"]}),
    ("Holínky KALVORO S5 zimní", "holinky", 1190, OBUV_PANSKA, ("holinka", "#111827"),
     {"pa_bezpecnostni_trida": ["S5"], "pa_pohlavi": ["unisex"], "pa_barva": ["černá"], "pa_prostredi": ["stavba"], "pa_material": ["PVC"], "pa_vlastnosti": ["nepromokavé", "zateplené"]}),
    ("Dámské holínky KALVORO S5", "holinky", 990, OBUV_DAMSKA, ("holinka", "#9d174d"),
     {"pa_bezpecnostni_trida": ["S5"], "pa_pohlavi": ["dámské"], "pa_barva": ["vínová"], "pa_prostredi": ["stavba"], "pa_material": ["PVC"], "pa_vlastnosti": ["nepromokavé"]}),
    ("Pracovní kalhoty do pasu RENDALO", "kalhoty", 890, ODEVY, ("kalhoty", "#1e3a8a"),
     {"pa_pohlavi": ["pánské"], "pa_barva": ["modrá"], "pa_prostredi": ["stavba", "dílna"], "pa_material": ["bavlna a polyester"]}),
    ("Pracovní kalhoty s laclem RENDALO", "kalhoty", 990, ODEVY, ("lacl", "#1e3a8a"),
     {"pa_pohlavi": ["pánské"], "pa_barva": ["modrá"], "pa_prostredi": ["stavba", "dílna"], "pa_material": ["bavlna a polyester"]}),
    ("Zateplené pracovní kalhoty RENDALO zimní", "kalhoty", 1290, ODEVY, ("kalhoty", "#374151"),
     {"pa_pohlavi": ["pánské"], "pa_barva": ["šedá"], "pa_prostredi": ["stavba", "sklad a logistika"], "pa_material": ["polyester"], "pa_vlastnosti": ["zateplené"]}),
    ("Dámské pracovní kalhoty RENDALO", "kalhoty", 890, ODEVY_DAMSKE, ("kalhoty", "#334155"),
     {"pa_pohlavi": ["dámské"], "pa_barva": ["šedá"], "pa_prostredi": ["dílna"], "pa_material": ["bavlna a polyester"]}),
    ("Pracovní kraťasy RENDALO", "kalhoty", 590, ODEVY, ("kratasy", "#1e3a8a"),
     {"pa_pohlavi": ["pánské"], "pa_barva": ["modrá"], "pa_prostredi": ["stavba"], "pa_material": ["bavlna"], "pa_vlastnosti": ["lehké"]}),
    ("Softshellová bunda VIKSO", "bundy-a-vesty", 1490, ODEVY, ("bunda", "#0f172a"),
     {"pa_pohlavi": ["pánské"], "pa_barva": ["černá"], "pa_prostredi": ["stavba"], "pa_material": ["softshell"], "pa_vlastnosti": ["nepromokavé"]}),
    ("Dámská softshellová bunda VIKSO", "bundy-a-vesty", 1490, ODEVY_DAMSKE, ("bunda", "#7f1d1d"),
     {"pa_pohlavi": ["dámské"], "pa_barva": ["vínová"], "pa_prostredi": ["sklad a logistika"], "pa_material": ["softshell"], "pa_vlastnosti": ["nepromokavé"]}),
    ("Zimní bunda NORDAVO", "bundy-a-vesty", 2290, ODEVY, ("bunda", "#1f2937"),
     {"pa_pohlavi": ["unisex"], "pa_barva": ["černá"], "pa_prostredi": ["sklad a logistika", "stavba"], "pa_material": ["polyester"], "pa_vlastnosti": ["zateplené", "nepromokavé"]}),
    ("Zateplená vesta NORDAVO", "bundy-a-vesty", 1190, ODEVY, ("vesta", "#374151"),
     {"pa_pohlavi": ["unisex"], "pa_barva": ["šedá"], "pa_prostredi": ["sklad a logistika"], "pa_material": ["polyester"], "pa_vlastnosti": ["zateplené"]}),
    ("Reflexní vesta LUXETO", "bundy-a-vesty", 190, ODEVY, ("vesta", "#facc15"),
     {"pa_pohlavi": ["unisex"], "pa_barva": ["žlutá"], "pa_prostredi": ["stavba", "sklad a logistika"], "pa_material": ["polyester"], "pa_vlastnosti": ["reflexní", "lehké"]}),
    ("Pracovní tričko BASITO", "trika-a-mikiny", 249, ODEVY, ("triko", "#334155"),
     {"pa_pohlavi": ["pánské"], "pa_barva": ["šedá"], "pa_material": ["bavlna"], "pa_vlastnosti": ["lehké"]}),
    ("Dámské pracovní tričko BASITO", "trika-a-mikiny", 249, ODEVY_DAMSKE, ("triko", "#c2410c"),
     {"pa_pohlavi": ["dámské"], "pa_barva": ["oranžová"], "pa_material": ["bavlna"], "pa_vlastnosti": ["lehké"]}),
    ("Reflexní tričko LUXETO", "trika-a-mikiny", 349, ODEVY, ("triko", "#f97316"),
     {"pa_pohlavi": ["unisex"], "pa_barva": ["oranžová"], "pa_prostredi": ["stavba"], "pa_material": ["polyester"], "pa_vlastnosti": ["reflexní"]}),
    ("Mikina s kapucí KORDINO", "trika-a-mikiny", 790, ODEVY, ("mikina", "#1f2937"),
     {"pa_pohlavi": ["unisex"], "pa_barva": ["černá"], "pa_material": ["bavlna a polyester"], "pa_vlastnosti": ["zateplené"]}),
    ("Montážní rukavice GRIPANO", "rukavice", 79, RUKAVICE, ("rukavice", "#0ea5e9"),
     {"pa_pohlavi": ["unisex"], "pa_barva": ["modrá"], "pa_prostredi": ["dílna", "sklad a logistika"], "pa_material": ["nylon a nitril"], "pa_vlastnosti": ["lehké"]}),
    ("Zimní rukavice GRIPANO zimní", "rukavice", 149, RUKAVICE, ("rukavice", "#1f2937"),
     {"pa_pohlavi": ["unisex"], "pa_barva": ["černá"], "pa_prostredi": ["stavba", "sklad a logistika"], "pa_material": ["akryl a latex"], "pa_vlastnosti": ["zateplené"]}),
    ("Nitrilové rukavice OLEXA", "rukavice", 59, RUKAVICE, ("rukavice", "#16a34a"),
     {"pa_pohlavi": ["unisex"], "pa_barva": ["zelená"], "pa_prostredi": ["dílna"], "pa_material": ["nitril"], "pa_vlastnosti": ["nepromokavé"]}),
]

ZNACKY = {"obuv": "Demo Footwear", "odevy": "Demo Wear", "rukavice": "Demo Gloves"}


def kontrolni_cislice(dvanact: str) -> int:
    soucet = sum(int(c) * (3 if i % 2 else 1) for i, c in enumerate(dvanact))
    return (10 - soucet % 10) % 10


def ean(produkt: int, varianta: int) -> str:
    """Interní rozsah 200: 200 + produkt (4) + varianta (5) + kontrolní číslice."""
    zaklad = f"200{produkt:04d}{varianta:05d}"
    return zaklad + str(kontrolni_cislice(zaklad))


def nahodne(*klic) -> int:
    """Deterministické „náhodné" číslo 0–99 z klíče."""
    return int(hashlib.sha256("|".join(map(str, klic)).encode()).hexdigest()[:8], 16) % 100


def sklad(produkt: int, velikost: str) -> dict:
    n = nahodne(produkt, velikost)
    if n < 72 or velikost in {"43", "M", "L", "9"}:
        return {"stav": "instock", "kusu": 1 + n % 7}
    if n < 88:
        return {"stav": "onbackorder", "kusu": 0}
    return {"stav": "outofstock", "kusu": 0}


def popis(nazev: str, atributy: dict) -> tuple[str, str]:
    """Krátký popis a tabulka parametrů (popis produktu bývá tabulka — viz SEO zkracování)."""
    trida = (atributy.get("pa_bezpecnostni_trida") or [""])[0].upper()
    vlastnosti = ", ".join(atributy.get("pa_vlastnosti", []))
    kratky = "Vymyšlený model pro ukázku e-shopu" + (f", třída ochrany {trida}" if trida else "") + "." + (f" Vlastnosti: {vlastnosti}." if vlastnosti else "")
    radky = [("Materiál", ", ".join(atributy.get("pa_material", [])))]
    if trida:
        radky.append(("Třída ochrany", trida))
    if vlastnosti:
        radky.append(("Vlastnosti", vlastnosti))
    radky.append(("Prostředí", ", ".join(atributy.get("pa_prostredi", [])) or "univerzální"))
    tabulka = "<table>" + "".join(f"<tr><th>{k}</th><td>{v}</td></tr>" for k, v in radky if v) + "</table>"
    return kratky, tabulka


# --- obrázky ------------------------------------------------------------------

def _hex(barva: str) -> tuple[int, int, int]:
    return tuple(int(barva[i:i + 2], 16) for i in (1, 3, 5))


def _tmavsi(barva: tuple[int, int, int], o: int = 40) -> tuple[int, int, int]:
    return tuple(max(0, c - o) for c in barva)


def nakresli(tvar: str, barva_hex: str, cil: Path) -> None:
    """Jednoduchá silueta zboží na světlém podkladu, 800 × 800."""
    vel = 800
    obr = Image.new("RGB", (vel, vel), (244, 245, 247))
    d = ImageDraw.Draw(obr)
    b = _hex(barva_hex)
    obrys = _tmavsi(b, 60) if sum(b) > 300 else (17, 24, 39)
    podrazka = (55, 65, 81)
    akcent = (194, 65, 12)

    if tvar in {"polobotka", "kotnik", "holinka", "sandal", "pantofel"}:
        vyska = {"polobotka": 330, "kotnik": 430, "holinka": 560, "sandal": 300, "pantofel": 290}[tvar]
        spodek = 560
        horni = spodek - vyska
        d.rounded_rectangle((130, spodek, 690, spodek + 55), radius=22, fill=podrazka)
        if tvar == "pantofel":
            # Pracovní pantofel (dřevák): uzavřená špička, otevřená pata s páskem.
            telo = [(180, spodek), (200, spodek - 110), (330, spodek - 150), (520, spodek - 210), (640, spodek - 170), (690, spodek)]
            d.polygon(telo, fill=b, outline=obrys)
            d.line(telo + [telo[0]], fill=obrys, width=6)
            d.arc((150, spodek - 170, 330, spodek + 10), 180, 300, fill=obrys, width=12)
            for x in (470, 530, 590):
                d.ellipse((x, spodek - 140, x + 22, spodek - 118), fill=_tmavsi(b, 40))
        else:
            telo = [(150, spodek), (150, horni + 30), (200, horni), (360, horni), (380, spodek - 170), (560, spodek - 150), (660, spodek - 95), (680, spodek)]
            if tvar in {"polobotka", "sandal"}:
                telo = [(150, spodek), (150, spodek - 170), (210, spodek - 205), (330, spodek - 205), (390, spodek - 150), (560, spodek - 140), (660, spodek - 90), (680, spodek)]
            if tvar == "holinka":
                telo = [(160, spodek), (170, horni), (380, horni), (380, spodek - 160), (580, spodek - 140), (670, spodek - 80), (680, spodek)]
            d.polygon(telo, fill=b, outline=obrys)
            d.line(telo + [telo[0]], fill=obrys, width=6)
            # Ochranná špička a šněrování.
            d.chord((540, spodek - 130, 720, spodek + 40), 180, 360, fill=_tmavsi(b, 25), outline=obrys, width=5)
            if tvar in {"polobotka", "kotnik"}:
                for i in range(3 if tvar == "polobotka" else 5):
                    y = spodek - 120 - (0 if tvar == "polobotka" else 0) - i * 34
                    d.line((350 + i * 12, y, 430 + i * 12, y + 14), fill=obrys, width=7)
            if tvar == "sandal":
                for x in (260, 380, 500):
                    d.rectangle((x, spodek - 160, x + 40, spodek), fill=(244, 245, 247))
            if tvar == "holinka":
                d.rectangle((170, horni + 20, 380, horni + 55), fill=_tmavsi(b, 30))
        d.rectangle((130, spodek + 40, 690, spodek + 55), fill=akcent)
    elif tvar in {"kalhoty", "lacl", "kratasy"}:
        delka = 420 if tvar == "kratasy" else 600
        top = 160
        d.polygon([(250, top), (550, top), (590, top + delka), (430, top + delka), (400, top + 160), (370, top + delka), (210, top + delka)], fill=b, outline=obrys)
        d.rectangle((250, top, 550, top + 40), fill=_tmavsi(b, 30))
        if tvar == "lacl":
            d.rectangle((300, top - 130, 500, top), fill=b, outline=obrys, width=5)
            d.line((300, top - 130, 260, top - 200), fill=obrys, width=14)
            d.line((500, top - 130, 540, top - 200), fill=obrys, width=14)
        if tvar != "kratasy":
            d.rectangle((225, top + 360, 375, top + 400), fill=akcent)
            d.rectangle((425, top + 360, 575, top + 400), fill=akcent)
    elif tvar in {"bunda", "vesta", "triko", "mikina"}:
        top = 170
        trup = [(270, top), (530, top), (580, 640), (220, 640)]
        if tvar == "triko":
            d.polygon([(270, top), (150, top + 130), (215, top + 190), (285, top + 120)], fill=b, outline=obrys)
            d.polygon([(530, top), (650, top + 130), (585, top + 190), (515, top + 120)], fill=b, outline=obrys)
        elif tvar != "vesta":
            d.polygon([(270, top), (130, top + 470), (200, top + 490), (290, top + 140)], fill=b, outline=obrys)
            d.polygon([(530, top), (670, top + 470), (600, top + 490), (510, top + 140)], fill=b, outline=obrys)
        d.polygon(trup, fill=b, outline=obrys)
        d.line(trup + [trup[0]], fill=obrys, width=6)
        d.polygon([(330, top), (400, top + 70), (470, top)], fill=(244, 245, 247))
        if tvar in {"bunda", "vesta", "mikina"}:
            d.line((400, top + 70, 400, 640), fill=obrys, width=6)
        if tvar == "mikina":
            d.ellipse((300, top - 90, 500, top + 40), outline=obrys, width=10)
        if tvar in {"vesta"} or barva_hex in {"#facc15", "#f97316"}:
            for y in (420, 500):
                d.rectangle((222, y, 578, y + 28), fill=(203, 213, 225))
    elif tvar == "rukavice":
        d.rounded_rectangle((280, 330, 520, 640), radius=60, fill=b, outline=obrys, width=6)
        for i, (x, v) in enumerate([(285, 190), (345, 150), (405, 160), (465, 200)]):
            d.rounded_rectangle((x, v, x + 52, 400), radius=26, fill=b, outline=obrys, width=5)
        d.rounded_rectangle((200, 410, 320, 480), radius=30, fill=b, outline=obrys, width=5)
        d.rectangle((280, 600, 520, 640), fill=akcent)

    obr.save(cil, "PNG", optimize=True)


def main() -> None:
    OBRAZKY.mkdir(exist_ok=True)
    produkty = []
    for i, (nazev, kategorie, cena, velikosti, (tvar, barva), atributy) in enumerate(PRODUKTY, start=1):
        koren = next(k[2] or k[0] for k in KATEGORIE if k[0] == kategorie)
        obrazek = f"produkt-{i:02d}.png"
        nakresli(tvar, barva, OBRAZKY / obrazek)
        kratky, dlouhy = popis(nazev, atributy)
        varianty = []
        for j, velikost in enumerate(velikosti, start=1):
            # Větší velikosti bývají dražší (obuv od 47, oděvy 3XL).
            priplatek = 100 if velikost in {"47", "48", "3XL"} else 0
            varianty.append({
                "velikost": velikost,
                "cena": cena + priplatek,
                "sku": f"UK-{i:03d}-{velikost}",
                "ean": ean(i, j),
                **sklad(i, velikost),
            })
        produkty.append({
            "nazev": nazev,
            "kategorie": kategorie,
            "sku": f"UK-{i:03d}",
            "kratky_popis": kratky,
            "popis": dlouhy,
            "obrazek": obrazek,
            "prodeje": nahodne("prodeje", i) * 3,
            "atributy": {"pa_znacka": [ZNACKY[koren]], **atributy},
            "varianty": varianty,
        })

    katalog = {
        "kategorie": [{"slug": s, "nazev": n, "rodic": r, "popis": p} for s, n, r, p in KATEGORIE],
        "atributy": {
            "pa_velikost": "Velikost",
            "pa_bezpecnostni_trida": "Třída ochrany",
            "pa_pohlavi": "Pro koho",
            "pa_barva": "Barva",
            "pa_znacka": "Značka",
            "pa_prostredi": "Prostředí",
            "pa_material": "Materiál",
            "pa_vlastnosti": "Vlastnosti",
        },
        "produkty": produkty,
    }
    (ZDE / "katalog.json").write_text(json.dumps(katalog, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print(f"{len(produkty)} produktů, {sum(len(p['varianty']) for p in produkty)} variant, {len(produkty)} obrázků")


if __name__ == "__main__":
    main()
