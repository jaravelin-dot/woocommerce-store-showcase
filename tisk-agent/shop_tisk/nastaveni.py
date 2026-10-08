"""Nastavení programu. Heslo nikdy nepatří do repozitáře."""

from __future__ import annotations

import configparser
from dataclasses import dataclass, field
from pathlib import Path


@dataclass(frozen=True)
class Nastaveni:
    url: str
    uzivatel: str
    heslo: str = field(repr=False)  # Heslo se nikdy nemá zobrazit v repr() — může skončit v logu nebo výpisu chyb.

    @classmethod
    def nacti(cls, cesta: Path) -> "Nastaveni":
        parser = configparser.ConfigParser()
        precteno = parser.read(cesta, encoding="utf-8")

        if not precteno:
            raise FileNotFoundError(f"Nastavení nenalezeno: {cesta}")

        web = parser["web"]

        return cls(
            url=web["url"].rstrip("/"),
            uzivatel=web["uzivatel"],
            heslo=web["heslo"],
        )
