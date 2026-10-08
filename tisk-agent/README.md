# Tiskový agent — výdejky na prodejně

Program běží na počítači u tiskárny na prodejně. Každou minutu se zeptá e-shopu,
jestli čeká objednávka k vytištění, a vytiskne výdejku na A4.

Web tiskárnu nevidí (server je v datacentru, tiskárna za routerem prodejny), proto si
práci vyzvedává počítač sám. Když je vypnutý, nic se neztratí — po zapnutí vyjede
i to, co přišlo v noci.

## Jak to drží spolehlivost

- **Nejdřív vytisknout, pak potvrdit.** Úloha se označí jako hotová až poté, co tiskárna
  papír přijala. V nejhorším se výdejka vytiskne dvakrát — nikdy nezmizí.
- **Server drží vyzvednutou úlohu 10 minut.** Když agent spadne uprostřed, úloha se sama
  vrátí do fronty.
- **Výpadek spojení ≠ prázdná fronta.** `fronta()` vrací `None` při chybě a `[]` při
  opravdu prázdné frontě; jen druhé znamená klid.
- **Hodina selhání = okno na obrazovce.** Obsluha se o poruše dozví, i když se nikdo
  nedívá do logu.

Protějšek na webu: `wp-content/plugins/shop-showcase/includes/class-shop-tisk.php`.

## Instalace (Windows)

1. Python 3.12, `python -m pip install -r requirements.txt`
2. SumatraPDF portable jako `C:\shop-tisk\SumatraPDF.exe`, Google Chrome (HTML → PDF)
3. `.\instalace.ps1` — vytvoří `%APPDATA%\shop-tisk\config.ini` ze vzoru
4. Doplň aplikační heslo uživatele `tisk-agent` z administrace WordPressu
5. `.\instalace.ps1` znovu — zaregistruje spuštění po přihlášení

## Testy

```
python -m pip install -r requirements-dev.txt
python -m pytest
```

Testy nesahají na síť ani na tiskárnu: klient dostává falešné odpovědi, tiskárna
falešný `subprocess.run`.
