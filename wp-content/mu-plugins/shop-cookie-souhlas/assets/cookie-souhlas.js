/**
 * Cookie lišta a spouštěč měřicích nástrojů podle souhlasu.
 *
 * Dokud návštěvník nerozhodne, skript nekontaktuje žádnou externí adresu.
 * Teprve po kliknutí (nebo hned při návratu, pokud souhlas z minula
 * existuje) zavolá nactiNastroje(), která vloží skripty do <head>.
 *
 * window.shopCookieKonfigurace obsahuje jen nástroje, které má správce
 * skutečně vyplněné. Prázdná konfigurace = nikdy se nic nenačte.
 */
(function () {
	'use strict';

	var KLIC_COOKIE = 'shop_cookie_souhlas';
	var DNI_PLATNOSTI = 180;
	var jizNacteno = {};

	function ctiCookie(nazev) {
		var shoda = document.cookie.match(new RegExp('(?:^|; )' + nazev + '=([^;]*)'));
		return shoda ? decodeURIComponent(shoda[1]) : null;
	}

	function zapisCookie(nazev, hodnota, dny) {
		var vyprsi = new Date(Date.now() + dny * 864e5);
		// Bez atributu Domain: cookie patří hostiteli, na kterém web běží
		// (testovací i ostrá doména), nic se neváže natvrdo.
		var zabezpeceno = location.protocol === 'https:' ? ';Secure' : '';
		document.cookie = nazev + '=' + encodeURIComponent(hodnota)
			+ ';expires=' + vyprsi.toUTCString() + ';path=/;SameSite=Lax' + zabezpeceno;
	}

	function nactiSouhlas() {
		var syrovy = ctiCookie(KLIC_COOKIE);
		if (!syrovy) { return null; }
		try {
			var data = JSON.parse(syrovy);
			return { analytika: !!data.analytika, reklama: !!data.reklama };
		} catch (e) {
			return null;
		}
	}

	function ulozSouhlas(souhlas) {
		zapisCookie(KLIC_COOKIE, JSON.stringify({
			analytika: !!souhlas.analytika,
			reklama: !!souhlas.reklama,
			cas: new Date().toISOString()
		}), DNI_PLATNOSTI);
	}

	/**
	 * Vloží úryvek kódu z administrace tak, aby se <script> skutečně spustil
	 * (skripty vložené přes innerHTML prohlížeč nespouští). Obsah NENÍ vstup
	 * od návštěvníka: zapisuje ho jen správce s oprávněním manage_options.
	 */
	function vlozUryvek(html) {
		var sablona = document.createElement('template');
		sablona.innerHTML = html;
		sablona.content.querySelectorAll('script').forEach(function (stary) {
			var novy = document.createElement('script');
			Array.prototype.forEach.call(stary.attributes, function (a) { novy.setAttribute(a.name, a.value); });
			novy.textContent = stary.textContent;
			stary.remove();
			document.head.appendChild(novy);
		});
		document.head.appendChild(sablona.content);
	}

	function nactiGtag(url, idcka, souhlas) {
		if (!url || !idcka.length || jizNacteno.gtag) { return; }
		jizNacteno.gtag = true;

		var skript = document.createElement('script');
		skript.async = true;
		skript.src = url + '?id=' + encodeURIComponent(idcka[0]);
		document.head.appendChild(skript);

		window.dataLayer = window.dataLayer || [];
		window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
		// Signály souhlasu (v EHP je reklamní systém bez nich nepřijme). Gtag
		// se načítá až po rozhodnutí, takže výchozí stav rovnou odpovídá volbě.
		var reklama = souhlas.reklama ? 'granted' : 'denied';
		window.gtag('consent', 'default', {
			analytics_storage: souhlas.analytika ? 'granted' : 'denied',
			ad_storage: reklama,
			ad_user_data: reklama,
			ad_personalization: reklama
		});
		window.gtag('js', new Date());
		idcka.forEach(function (id) { window.gtag('config', id); });

		// Konverze z děkovací stránky čekají na tenhle signál, aby nešly do
		// fronty dřív než 'config'.
		window.shopGtagPripraven = true;
		document.dispatchEvent(new CustomEvent('shop:gtag-pripraven'));
	}

	function nactiNastroje(souhlas) {
		var k = window.shopCookieKonfigurace || {};
		var idcka = [];

		if (souhlas.analytika && k.analytika) { idcka.push(k.analytika.hodnota); }
		if (souhlas.reklama && k.reklama) { idcka.push(k.reklama.hodnota); }
		nactiGtag(k.gtag_url, idcka, souhlas);

		if (souhlas.reklama && k.kod && !jizNacteno.kod) {
			jizNacteno.kod = true;
			vlozUryvek(atob(k.kod.hodnota));
		}

		// Atribuce objednávek ve WooCommerce se řídí hodnotou z PHP, tedy až
		// od dalšího načtení stránky. Veřejná metoda jejího skriptu ji umí
		// přepnout hned.
		if (window.wc_order_attribution && typeof window.wc_order_attribution.setOrderTracking === 'function') {
			window.wc_order_attribution.setOrderTracking(!!souhlas.reklama);
		}
	}

	function pripravListu() {
		var lista = document.getElementById('shop-cookie-lista');
		if (!lista) { return; }

		var analytika = document.getElementById('shop-cookie-analytika');
		var reklama = document.getElementById('shop-cookie-reklama');
		var podrobnosti = document.getElementById('shop-cookie-podrobnosti');
		var znovu = document.getElementById('shop-cookie-znovu');

		// Sbalená lišta na mobilu (viz CSS): rozbalená by zakryla většinu
		// první obrazovky a zákazník z reklamy by neviděl fotku ani cenu.
		function sbal(ano) {
			lista.classList.toggle('shop-cookie-lista--sbalena', ano);
			podrobnosti.setAttribute('aria-expanded', ano ? 'false' : 'true');
		}

		function otevri(predvyplnit, sbalene) {
			analytika.checked = !!(predvyplnit && predvyplnit.analytika);
			reklama.checked = !!(predvyplnit && predvyplnit.reklama);
			sbal(!!sbalene);
			lista.hidden = false;
			znovu.hidden = true;
		}

		function rozhodnuto(souhlas) {
			ulozSouhlas(souhlas);
			lista.hidden = true;
			znovu.hidden = false;
			nactiNastroje(souhlas);
		}

		document.getElementById('shop-cookie-prijmout').addEventListener('click', function () {
			rozhodnuto({ analytika: true, reklama: true });
		});
		// Odmítnutí je tlačítko stejné velikosti ve stejné řadě jako souhlas,
		// ne drobný odkaz stranou.
		document.getElementById('shop-cookie-odmitnout').addEventListener('click', function () {
			rozhodnuto({ analytika: false, reklama: false });
		});
		document.getElementById('shop-cookie-ulozit').addEventListener('click', function () {
			rozhodnuto({ analytika: analytika.checked, reklama: reklama.checked });
		});
		podrobnosti.addEventListener('click', function () {
			sbal(false);
			analytika.focus();
		});
		znovu.addEventListener('click', function (e) {
			e.preventDefault();
			otevri(nactiSouhlas(), false);
		});

		var ulozeny = nactiSouhlas();
		if (!ulozeny) {
			otevri(null, true);
			return;
		}
		nactiNastroje(ulozeny);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', pripravListu);
	} else {
		pripravListu();
	}
})();
