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
- Static weight 0.3 kg for all items
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

## Installation

1. Download the latest release ZIP (contains a top-level `moovarle/` folder).
2. In PrestaShop BO, go to Modules > Module Manager > Upload a module and select the ZIP.
3. Open the module settings:
   - Set Price source (recommended: Retail), Margin %, Global discount % (optional), Delivery text, and Cron token.
   - Click "Save settings".
4. Click "Regenerate export now" to build the cache once; copy the feed URL shown in the settings.

## Try it (examples)

- Build from scratch and continue within one request (if server allows):
  - Pretty: `/feed/varle/cron?token=YOUR_TOKEN&reset=1&loop=1&time=60&size=1000&max_steps=9999`
  - Legacy: `index.php?fc=module&module=moovarle&controller=cron&token=YOUR_TOKEN&reset=1&loop=1&time=60&size=1000&max_steps=9999`

- Read the feed:
  - Pretty: `/feed/varle.xml`
  - Legacy: `index.php?fc=module&module=moovarle&controller=varle`

## Notes

- Only active products are exported; category breadcrumb skips Root/Home.
- Variant group title is forced to "Dydis"; variant price is always `0.00` (Varle requirement).
- Barcodes are pulled from `ps_product_attribute` (EAN preferred, UPC fallback).
- Generation is incremental: call cron multiple times until it returns `{status: "done"}` or use `loop=1` with a suitable `time` budget.

## Security

- The cron endpoint requires the `MOOVARLE_CRON_TOKEN` (`403` if missing or wrong). Keep it secret.
- Consider allowlisting your server IP if you trigger cron from an external scheduler.

## Cache and applying new settings

- After changing module settings (price source, margin, discount, delivery text), regenerate the feed (the "Regenerate export now" button triggers cron with `reset=1`).
- Settings take effect only after the module's feed cache is cleared/rebuilt. If you changed settings but still see old values, run cron with `reset=1` or delete cached files in `modules/moovarle/var/cache/`.

## Troubleshooting

- 403 on cron: token missing/invalid; pass `?token=...`.
- 500 on cron: check webserver/PHP error log; common causes are misconfigured shop context or custom overrides. Retry with `reset=1&size=10&max_steps=1` to isolate.
- Prices look unchanged after editing settings: the feed is cached. Use the button "Regenerate export now" (which calls cron with `reset=1`) or remove files in `modules/moovarle/var/cache/`.
- `<price_old>` appears when discount is 0: update to v1.0.0+ (this release emits `<price_old>` only when global discount > 0).

## Changelog

### v1.0.0

- Initial public release.
- Retail-based pricing across combinations (max), margin %, optional global discount (emits `<price_old>` only when discount > 0).
- Variant group title fixed to "Dydis" with price delta `0.00`.
- Variant barcodes from `ps_product_attribute` (EAN/UPC).
- Export only active products; category path skips Root/Home.
- Delivery text is a free-form string (e.g., `3-5 d. d.`), numeric-only values gain suffix automatically.
- Incremental cron with cache and lock; pretty and legacy endpoints.

## License

MIT
