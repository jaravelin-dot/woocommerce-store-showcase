<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Způsob dopravy „Výdejní místo" s pevnou cenou a dopravou zdarma od
 * částky košíku. Načítá se až v háku woocommerce_shipping_init, kdy už
 * existuje rodičovská třída.
 */
class Shop_Doprava_Vydejni_Misto extends WC_Shipping_Method {

	public function __construct($instance_id = 0) {
		$this->id                 = Shop_Vydejni_Mista::METODA;
		$this->instance_id        = absint($instance_id);
		$this->method_title       = 'Výdejní místo';
		$this->method_description = 'Doručení na výdejní místo nebo do boxu (ukázka se statickým seznamem míst).';
		$this->supports           = ['shipping-zones', 'instance-settings'];
		$this->instance_form_fields = [
			'title'     => ['title' => 'Název', 'type' => 'text', 'default' => 'Výdejní místo'],
			'cost'      => ['title' => 'Cena s DPH', 'type' => 'price', 'default' => '89'],
			'zdarma_od' => ['title' => 'Zdarma od (Kč s DPH)', 'type' => 'price', 'default' => '2000'],
		];
		$this->init_settings();
		$this->title = (string) $this->get_option('title', 'Výdejní místo');
	}

	/** @param array<string, mixed> $balik */
	public function calculate_shipping($balik = []): void {
		$obsah = (float) ($balik['contents_cost'] ?? 0) + (float) array_sum(array_map(static fn($p): float => (float) ($p['line_tax'] ?? 0), (array) ($balik['contents'] ?? [])));
		$zdarma = (float) $this->get_option('zdarma_od', 0);

		$this->add_rate([
			'id'    => $this->get_rate_id(),
			'label' => $this->title,
			'cost'  => ($zdarma > 0 && $obsah >= $zdarma) ? 0 : (float) $this->get_option('cost', 0),
			// Zjednodušení ukázky: cena dopravy je konečná (s DPH), daň se k ní nepřičítá.
			'taxes' => false,
		]);
	}
}
