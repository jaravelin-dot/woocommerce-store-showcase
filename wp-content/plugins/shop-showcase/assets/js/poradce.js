/* AI poradce — rozhovor nad katalogem.
 *
 * Bez JS je widget obyčejný vyhledávací formulář (/?s=…&post_type=product).
 * S JS posílá dotaz na POST /wp-json/shop/v1/poradce a skládá rozhovor.
 * Jakékoli selhání (síť, 403, 429, `zaloha: true`) = přesměrování do
 * běžného hledání se stejným textem. Zákazník nikdy nezůstane bez výsledku.
 *
 * Text od modelu se vkládá VÝHRADNĚ přes textContent (žádné innerHTML).
 */
(function () {
	'use strict';

	// Server skončí nejpozději za ~60 s; prohlížeč čeká o něco déle.
	var TIMEOUT_MS = 75000;
	var STITKY = { 'skladem': 'Skladem', 'u-vyrobce': 'U výrobce' };

	function el(tag, trida, text) {
		var e = document.createElement(tag);
		if (trida) { e.className = trida; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}

	// Analytika se načítá až po souhlasu s cookies; bez něj gtag neexistuje.
	function meri(udalost, parametry) {
		if (typeof window.gtag === 'function') { window.gtag('event', udalost, parametry); }
	}

	function init(root, cfg) {
		var form = root.querySelector('.shop-poradce__form');
		var pole = root.querySelector('.shop-poradce__pole');
		var tlacitko = root.querySelector('.shop-poradce__poslat');
		var priklady = root.querySelector('[data-shop-poradce-priklady]');
		var rozhovor = root.querySelector('[data-shop-poradce-rozhovor]');
		var pata = root.querySelector('[data-shop-poradce-pata]');
		var znovu = root.querySelector('[data-shop-poradce-znovu]');
		var relace = '';
		var prvniDotaz = '';
		var bezi = false;
		// „Začít znovu" během čekání zvýší generaci; pozdní odpověď starého
		// rozhovoru se zahodí (jinak by oživila starou relaci).
		var generace = 0;
		var ovladac = null;

		function doHledani(text) {
			window.location.href = cfg.hledani + encodeURIComponent(text);
		}

		function kostry() {
			var ul = el('ul', 'shop-poradce__karty');
			ul.setAttribute('aria-hidden', 'true');
			for (var i = 0; i < 3; i++) {
				var li = el('li', 'shop-poradce__karta shop-poradce__karta--kostra');
				li.appendChild(el('span', 'shop-poradce__fotka'));
				li.appendChild(el('span', 'shop-poradce__kost'));
				ul.appendChild(li);
			}
			return ul;
		}

		function karta(k) {
			var li = el('li', 'shop-poradce__karta');
			var a = el('a');
			a.href = k.odkaz;
			a.addEventListener('click', function () { meri('poradce_karta', { product_id: k.id }); });
			var fotka = el('span', 'shop-poradce__fotka');
			if (k.fotka) {
				var img = el('img');
				img.src = k.fotka;
				img.alt = '';
				img.loading = 'lazy';
				fotka.appendChild(img);
			}
			if (STITKY[k.dostupnost]) {
				// S velikostí platí štítek jen pro ni: „Vel. 43 skladem".
				var stitek = k.velikost
					? 'Vel. ' + k.velikost + (k.dostupnost === 'skladem' ? ' skladem' : ' u výrobce')
					: STITKY[k.dostupnost];
				fotka.appendChild(el('span', 'shop-poradce__stitek shop-poradce__stitek--' + k.dostupnost, stitek));
			}
			a.appendChild(fotka);
			a.appendChild(el('span', 'shop-poradce__nazev', k.nazev));
			a.appendChild(el('span', 'shop-poradce__cena', k.cena_text));
			li.appendChild(a);
			return li;
		}

		function vykresliOdpoved(tah, data) {
			tah.querySelectorAll('.shop-poradce__hledam, .shop-poradce__karty').forEach(function (x) { x.remove(); });
			tah.appendChild(el('p', 'shop-poradce__odpoved', data.text || ''));

			if (data.karty && data.karty.length) {
				var ul = el('ul', 'shop-poradce__karty');
				data.karty.forEach(function (k) { ul.appendChild(karta(k)); });
				tah.appendChild(ul);
			}

			if (data.dotaz) {
				tah.appendChild(el('p', 'shop-poradce__otazka', data.dotaz));
				if (data.moznosti && data.moznosti.length) {
					var moznosti = el('div', 'shop-poradce__moznosti');
					data.moznosti.forEach(function (m) {
						var b = el('button', 'shop-poradce__moznost', m);
						b.type = 'button';
						b.addEventListener('click', function () {
							moznosti.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
							posli(m);
						});
						moznosti.appendChild(b);
					});
					tah.appendChild(moznosti);
				}
			}

			if (data.konec) {
				pole.disabled = true;
				tlacitko.disabled = true;
			}
		}

		function selhani(tah, hledam, text) {
			// Uprostřed rozhovoru nepřesměrovávat: hledání „dámské" by nic
			// nenašlo a rozhovor by zmizel. Nabídnout odkaz.
			if (relace || root.getAttribute('data-shop-poradce-rezim') === 'hledani') {
				tah.querySelectorAll('.shop-poradce__hledam, .shop-poradce__karty').forEach(function (x) { x.remove(); });
				var p = el('p', 'shop-poradce__odpoved', 'Poradce teď neodpovídá. Zkuste to prosím za chvíli, nebo ');
				var a = el('a', '', 'se podívejte do běžného hledání');
				a.href = cfg.hledani + encodeURIComponent(prvniDotaz || text);
				p.appendChild(a);
				p.appendChild(document.createTextNode('.'));
				tah.appendChild(p);
				return;
			}
			hledam.textContent = 'Poradce teď neodpovídá, otevíráme běžné hledání…';
			doHledani(text);
		}

		function posli(text) {
			text = (text || '').trim().slice(0, cfg.maxZnaku);
			if (!text || bezi) { return; }
			bezi = true;
			if (!prvniDotaz) { prvniDotaz = text; }
			var moje = generace;
			meri('poradce_dotaz', { tah: rozhovor.children.length + 1 });
			tlacitko.disabled = true;
			rozhovor.setAttribute('aria-busy', 'true');
			if (priklady) { priklady.hidden = true; }

			var tah = el('div', 'shop-poradce__tah');
			var dotaz = el('p', 'shop-poradce__dotaz-zakaznika', 'Váš dotaz: ');
			dotaz.appendChild(el('b', '', text));
			tah.appendChild(dotaz);
			var hledam = el('p', 'shop-poradce__hledam', 'Hledám v katalogu…');
			tah.appendChild(hledam);
			tah.appendChild(kostry());
			rozhovor.appendChild(tah);
			pole.value = '';

			ovladac = typeof AbortController !== 'undefined' ? new AbortController() : null;
			var mujOvladac = ovladac;
			var casovac = mujOvladac ? setTimeout(function () { mujOvladac.abort(); }, TIMEOUT_MS) : null;
			var hlavicky = { 'Content-Type': 'application/json' };
			if (cfg.nonce) { hlavicky['X-WP-Nonce'] = cfg.nonce; }

			fetch(cfg.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: hlavicky,
				body: JSON.stringify({ zprava: text, relace: relace }),
				signal: mujOvladac ? mujOvladac.signal : undefined
			}).then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) { return { ok: r.ok, data: data }; });
			}).then(function (v) {
				if (moje !== generace) { return; }
				if (!v.ok || !v.data || v.data.zaloha) {
					meri('poradce_zaloha', { duvod: (v.data && v.data.duvod) || 'http' });
					if (v.data && v.data.relace) { relace = v.data.relace; }
					selhani(tah, hledam, text);
					return;
				}
				if (v.data.relace) { relace = v.data.relace; }
				vykresliOdpoved(tah, v.data);
				pata.hidden = false;
			}).catch(function () {
				if (moje === generace) { selhani(tah, hledam, text); }
			}).then(function () {
				if (casovac) { clearTimeout(casovac); }
				if (moje !== generace) { return; }
				bezi = false;
				tlacitko.disabled = pole.disabled;
				rozhovor.setAttribute('aria-busy', 'false');
			});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!pole.value.trim()) { pole.focus(); return; }
			posli(pole.value);
		});

		if (priklady) {
			priklady.addEventListener('click', function (e) {
				var b = e.target.closest('[data-shop-poradce-text]');
				if (b) { posli(b.getAttribute('data-shop-poradce-text')); }
			});
		}

		znovu.addEventListener('click', function () {
			generace++;
			if (ovladac) { ovladac.abort(); }
			bezi = false;
			relace = '';
			prvniDotaz = '';
			rozhovor.textContent = '';
			pata.hidden = true;
			pole.disabled = false;
			tlacitko.disabled = false;
			if (priklady) { priklady.hidden = false; }
			pole.focus();
		});
	}

	function start() {
		var root = document.querySelector('[data-shop-poradce]');
		if (root && window.shopPoradce) { init(root, window.shopPoradce); }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
