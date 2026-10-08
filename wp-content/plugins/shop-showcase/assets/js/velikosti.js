/* Velikosti jako tlačítka místo rozbalovacího seznamu.
 *
 * Zákazník na první pohled vidí, které velikosti jsou skladem, které
 * objednáme u výrobce a které jsou vyprodané. Pod tlačítky zůstává původní
 * <select> WooCommerce (skrytý) — formulář, ceny i obrázek varianty dál
 * řídí jádro, tlačítka jen nastavují jeho hodnotu.
 */
(function () {
	'use strict';

	function init(form) {
		var select = form.querySelector('select[name="attribute_pa_velikost"]');
		if (!select || select.dataset.shopVelikosti) { return; }
		select.dataset.shopVelikosti = '1';

		var varianty = [];
		try { varianty = JSON.parse(form.getAttribute('data-product_variations') || '[]') || []; } catch (e) { varianty = []; }

		// `shop_stav` doplňuje PHP (Shop_Velikosti) ze stavu skladu varianty.
		function stav(slug) {
			var v = varianty.filter(function (x) { return x.attributes && x.attributes.attribute_pa_velikost === slug; })[0];
			return v && v.shop_stav ? v.shop_stav : 'vyprodano';
		}

		var panel = document.createElement('div');
		panel.className = 'shop-velikosti';
		panel.setAttribute('role', 'radiogroup');
		panel.setAttribute('aria-label', 'Velikost');

		Array.prototype.forEach.call(select.options, function (moznost) {
			if (!moznost.value) { return; }
			var s = stav(moznost.value);
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'shop-velikosti__volba shop-velikosti__volba--' + s;
			b.textContent = moznost.textContent;
			b.setAttribute('role', 'radio');
			b.setAttribute('aria-checked', select.value === moznost.value ? 'true' : 'false');
			b.title = s === 'skladem' ? 'Skladem' : (s === 'u-vyrobce' ? 'Objednáme u výrobce' : 'Vyprodáno');
			if (s === 'vyprodano') { b.disabled = true; }
			b.addEventListener('click', function () {
				select.value = moznost.value;
				// Formulář variant WooCommerce poslouchá přes jQuery.
				if (window.jQuery) {
					window.jQuery(select).trigger('change');
				} else {
					select.dispatchEvent(new Event('change', { bubbles: true }));
				}
				panel.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
			});
			panel.appendChild(b);
		});

		var legenda = document.createElement('p');
		legenda.className = 'shop-velikosti__legenda';
		[['skladem', 'skladem'], ['u-vyrobce', 'u výrobce'], ['vyprodano', 'vyprodáno']].forEach(function (x) {
			var span = document.createElement('span');
			span.className = x[0];
			span.textContent = x[1];
			legenda.appendChild(span);
		});

		select.classList.add('shop-velikosti__puvodni');
		select.parentNode.insertBefore(panel, select);
		select.parentNode.insertBefore(legenda, select);
	}

	function start() {
		document.querySelectorAll('form.variations_form').forEach(init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
