"""Spustí bash test hooku z pytestu, ať ho hlídá stejný příkaz jako ostatní testy."""

import shutil
import subprocess
from pathlib import Path

import pytest

ZDE = Path(__file__).parent


@pytest.mark.skipif(shutil.which("bash") is None, reason="bash není k dispozici")
def test_hook_blokuje_a_propousti_spravne():
    vysledek = subprocess.run(
        ["bash", str(ZDE / "test-hooku.sh")],
        capture_output=True,
        text=True,
        encoding="utf-8",
        timeout=120,
    )
    assert vysledek.returncode == 0, vysledek.stdout + vysledek.stderr
    assert "VŠECHNO PROŠLO" in vysledek.stdout
