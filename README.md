# moovarle

<p>
	<a href="https://github.com/moonia33/moovarle/releases/latest/download/moovarle.zip">
		<img alt="Download moovarle.zip" src="https://img.shields.io/badge/Download-MOOVARLE.ZIP-2ea44f?style=for-the-badge"/>
	</a>
</p>

Or use the direct link: [Download moovarle.zip (latest release)](https://github.com/moonia33/moovarle/releases/latest/download/moovarle.zip)

Varle.lt marketplace XML export module for PrestaShop 1.7–8/9.

- Generates XML exactly as per varle_xml_mp_(1a_2)_LT.xml:
	- Root: <root><products><product>…</product></products></root>
	- Required: id, categories/category, title, description (HTML), price (incl. tax, no currency), delivery_text, images/image
	- Stock & barcode: quantity, barcode_format (EAN/UPC), barcode (when no variants)
	- Variants: <variants><variant group_title="…"><title>…</title><quantity>…</quantity><barcode>…</barcode><price>delta</price></variant>…</variants>
	- Recommended: model, weight, manufacturer, attributes (features)
	- Optional: price_old, url
- Includes all product features as <attributes><attribute title="…"><![CDATA[value]]></attribute></attributes>
- Static weight 0.3 kg for all items (as requested)
- Pricing pipeline (configurable):
	- Price source: retail (tax incl., no reductions) across combinations (max) OR wholesale+VAT fallback.
	- Margin (%): applied on top of base.
	- Global discount (%): applied after margin. When discount = 0, <price_old> is not emitted.
- Delivery text is a free-form string (e.g., "3-5 d. d."). If you enter only a number, it will be output as "N d. d." automatically.
- Caching with incremental cron generation and lock file
- Manual regenerate button in BO configuration

## Endpoints

- Pretty URLs:
	- Feed: `/feed/varle.xml`
	- Cron: `/feed/varle/cron?token=YOUR_TOKEN`
- Legacy URLs (if pretty is 404):
	- Feed: `index.php?fc=module&module=moovarle&controller=varle`
	- Cron: `index.php?fc=module&module=moovarle&controller=cron&token=YOUR_TOKEN`
	- Optional params: `size` (batch size, default 1000), `max_steps` (default 3), `loop=1` to continue within one request, `time` (seconds budget for loop, default 18, min 5, max 60)

## Configuration

- Global discount %: `MOOVARLE_GLOBAL_DISCOUNT`
- Price source: `MOOVARLE_PRICE_SOURCE` (retail | wholesale)
- Margin %: `MOOVARLE_MARGIN_PERCENT`
- Delivery time (text): `MOOVARLE_DELIVERY_DAYS`
- Cron token: `MOOVARLE_CRON_TOKEN`

### Category mapping (config + YAML)

- Mapping failas: `modules/moovarle/config/category_map.yaml`
- Paskirtis: pagal produkto giliausią „default“ kategoriją (`id_category_default`) parinkti VIENĄ marketplace kategoriją `<category>`.
- Jei `id_category_default` nėra YAML’e – taikomas fallback: `Apatinis trikotažas moterims`.
- Be to, kiekvienas breadcrumb lygis (be Root/Home) įdedamas kaip atskiras atributas: `<attribute title="Tipas">Originalus pavadinimas</attribute>`.

Failo formatas (minimalus):

```
- id_category: 60
	marketplace_category: Pižamos ir naktiniai
```

Pilnas (su informaciniu pavadinimu):

```
- id_category: 60
	source_category: Aksesuarai
	marketplace_category: Pižamos ir naktiniai
```

Pastabos:
- Privalomi laukai: `id_category`, `marketplace_category`; `source_category` – tik informacinis.
- Pakeitimai YAML faile įsigalioja regeneravus feed’ą (mygtukas „Regenerate export now“ arba cron su `reset=1`).

## Notes

- Only active and in-stock products (quantity > 0) are included when a product has no variants; for products with variants, stock is specified per variant.
- Prices include tax and apply the configured margin and (optional) global discount. Variant price is always 0.00 (Varle requirement).
- `<price_old>` is emitted only when a positive global discount (%) is configured.
- Categories: single mapped marketplace category (`<category>`). Deepest default category ID is mapped through `modules/moovarle/config/category_map.yaml`; if ID not present, fallback is `Apatinis trikotažas moterims`.
- Breadcrumb levels (excluding Root/Home) are also emitted as `<attribute title="Tipas">OriginalName</attribute>` before product feature attributes.
 - Variant barcode: if variant EAN is missing, a synthetic 13-digit code is generated from `id_product_attribute` + `reference` (digits only), padding with zeros or trimming from the end to reach 13 digits.
- Generation is incremental: call cron endpoint multiple times until it returns `{status: "done"}` or use `loop=1` with a suitable `time` budget (e.g., `time=25`) to finish in one request if server timeouts allow it.

### Cache and applying new settings

- After changing module settings (price source, margin, discount, delivery text), regenerate the feed (the "Regenerate export now" button triggers cron with `reset=1`).
- Settings take effect only after the module's feed cache is cleared/rebuilt. If you changed settings but still see old values, run cron with `reset=1` or delete cached files in `modules/moovarle/var/cache/`.
- Ensure you supply the correct cron token; otherwise the cron endpoint will return 403.

## Packaging

This repo is packaged as a ZIP with a top-level `moovarle/` directory via GitHub Actions release workflow. The artifact can be installed via PrestaShop Module Manager.

## Changelog

### v1.1.1

- Variants without EAN now get a synthetic 13-digit barcode (id_product_attribute + reference digits; pad/cut to 13). No DB writes; affects export only.

### v1.1.0

- Added category mapping & breadcrumb attributes (`Tipas`).
- Fallback category when unmapped: `Apatinis trikotažas moterims`.
- README updated to reflect new behavior.

### v1.0.0

- Initial public release.
