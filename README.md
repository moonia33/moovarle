# moovarle

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

## Notes

- Only active and in-stock products (quantity > 0) are included when a product has no variants; for products with variants, stock is specified per variant.
- Prices include tax and apply the configured margin and (optional) global discount. Variant price is always 0.00 (Varle requirement).
- `<price_old>` is emitted only when a positive global discount (%) is configured.
- Categories are emitted as a single breadcrumb string via "Parent -> Child" in the first <category> entry.
- Generation is incremental: call cron endpoint multiple times until it returns `{status: "done"}` or use `loop=1` with a suitable `time` budget (e.g., `time=25`) to finish in one request if server timeouts allow it.

### Cache and applying new settings

- After changing module settings (price source, margin, discount, delivery text), regenerate the feed (the "Regenerate export now" button triggers cron with `reset=1`).
- Settings take effect only after the module's feed cache is cleared/rebuilt. If you changed settings but still see old values, run cron with `reset=1` or delete cached files in `modules/moovarle/var/cache/`.
- Ensure you supply the correct cron token; otherwise the cron endpoint will return 403.

## Packaging

This repo is packaged as a ZIP with a top-level `moovarle/` directory via GitHub Actions release workflow. The artifact can be installed via PrestaShop Module Manager.
