<?php

namespace Omnitrade\Web;

use Omnitrade\Model\Media;
use Omnitrade\Model\Merchant;
use Omnitrade\Model\Money;
use Omnitrade\Model\Offer;
use Omnitrade\Model\Option;
use Omnitrade\Model\Product;
use Omnitrade\Model\ProductVariant;

/**
 * A product page's HTML, read as an Omnitrade Product. In order of trust:
 *
 *   1. JSON-LD (<script type="application/ld+json">): a Product, a
 *      ProductGroup with its hasVariant, found at the top, in a list or in
 *      an @graph; offers as an Offer, a list of them or an AggregateOffer;
 *   2. schema.org microdata (itemscope itemtype=".../Product", itemprop);
 *   3. OpenGraph and its product: tags (og:title, og:image,
 *      product:price:amount / og:price:amount, product:brand...).
 *
 * What a richer source leaves empty is taken from the next one (an image
 * from og:image, the shop's name from og:site_name), then from the page
 * itself (<title>, the canonical link). Nothing found: null.
 */
final class PageReader
{
    public function __construct(private readonly string $defaultCurrency = 'EUR')
    {
    }

    public function read(string $html, string $url): ?Product
    {
        if ('' === trim($html)) {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        $meta = $this->meta($xpath);
        $canonical = $this->canonical($xpath, $url) ?? ($meta['og:url'] ?? null) ?? $url;
        $canonical = $this->absolute($canonical, $url);

        $ld = $this->jsonLd($xpath);
        $data = $ld ? $this->fromJsonLd($ld, $canonical) : $this->fromMicrodata($xpath, $canonical);
        $og = $this->fromOpenGraph($meta, $canonical);
        if (null === $data && null === $og) {
            return null;
        }
        $data ??= $og;
        $source = $ld ? 'json-ld' : (isset($data['microdata']) ? 'microdata' : 'opengraph');

        // What the richer source left empty, from OpenGraph, then the page.
        foreach (['title', 'description', 'brand', 'image'] as $field) {
            if (empty($data[$field]) && !empty($og[$field])) {
                $data[$field] = $og[$field];
            }
        }
        if (empty($data['variants']) && !empty($og['variants'])) {
            $data['variants'] = $og['variants'];
        }
        $title = $data['title'] ?? $this->text($xpath, '//title');
        if (null === $title || '' === $title) {
            return null;
        }
        $host = (string) parse_url($canonical, \PHP_URL_HOST);
        $merchant = new Merchant(
            $data['seller'] ?? $meta['og:site_name'] ?? preg_replace('~^www\.~', '', $host),
            null,
            (string) (parse_url($canonical, \PHP_URL_SCHEME) ?: 'https').'://'.$host,
        );

        $media = [];
        foreach ((array) ($data['images'] ?? []) as $image) {
            $media[] = new Media($this->absolute($image, $canonical), $title);
        }
        if (!$media && !empty($data['image'])) {
            $media[] = new Media($this->absolute($data['image'], $canonical), $title);
        }

        $variants = [];
        foreach ($data['variants'] ?? [] as $i => $v) {
            $offers = [];
            if (null !== ($v['price'] ?? null)) {
                $offers[] = new Offer(
                    Money::fromDecimal($v['price'], $v['currency'] ?? $this->defaultCurrency),
                    null,
                    $v['available'] ?? true,
                    $v['url'] ?? $canonical,
                    $merchant,
                    null,
                    true,
                );
            }
            $variants[] = new ProductVariant(
                $v['reference'] ?? ($v['sku'] ?? $canonical.'#'.$i),
                $v['title'] ?? null,
                $v['sku'] ?? null,
                $v['gtin'] ?? null,
                $offers,
                $v['options'] ?? [],
                null,
                !empty($v['image']) ? new Media($this->absolute($v['image'], $canonical), $v['title'] ?? $title) : null,
            );
        }
        if (!$variants) {
            $variants[] = new ProductVariant($canonical, null, $data['sku'] ?? null, $data['gtin'] ?? null);
        }

        $options = [];
        foreach ($data['options'] ?? [] as $name => $values) {
            $options[] = new Option($name, array_values(array_unique($values)));
        }

        return new Product(
            provider: 'web',
            reference: $canonical,
            title: html_entity_decode($title, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            description: isset($data['description']) ? self::html((string) $data['description']) : null,
            handle: trim((string) parse_url($canonical, \PHP_URL_PATH), '/') ?: null,
            brand: $data['brand'] ?? null,
            url: $canonical,
            status: Product::ACTIVE,
            categories: array_values(array_filter((array) ($data['category'] ?? []))),
            options: $options,
            variants: $variants,
            media: $media,
            merchant: $merchant,
            raw: ['source' => $source, 'url' => $url],
        );
    }

    // ── JSON-LD ────────────────────────────────────────────────────

    /** The first Product or ProductGroup node of the page's JSON-LD blocks. */
    private function jsonLd(\DOMXPath $xpath): ?array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $json = trim((string) $script->textContent);
            // Some shops wrap it in a CDATA or an HTML comment.
            $json = preg_replace(['~^\s*(<!--|/\*<!\[CDATA\[\*/|//<!\[CDATA\[)~', '~(-->|/\*\]\]>\*/|//\]\]>)\s*$~'], '', $json);
            $data = json_decode((string) $json, true);
            if (!\is_array($data)) {
                // Raw newlines inside strings: forgiven.
                $data = json_decode(preg_replace('/[\x00-\x1F]+/', ' ', (string) $json), true);
            }
            if (\is_array($data) && null !== ($node = $this->findProduct($data))) {
                return $node;
            }
        }

        return null;
    }

    private function findProduct(array $data): ?array
    {
        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (\is_array($item) && null !== ($found = $this->findProduct($item))) {
                    return $found;
                }
            }

            return null;
        }
        $types = array_map(static fn ($t) => strtolower((string) preg_replace('~^.*[/#]~', '', (string) $t)), (array) ($data['@type'] ?? []));
        if (array_intersect($types, ['productgroup'])) {
            return $data;
        }
        if (array_intersect($types, ['product', 'individualproduct', 'productmodel', 'vehicle', 'book'])) {
            return $data;
        }
        foreach (['@graph', 'mainEntity', 'itemListElement', 'item'] as $key) {
            if (\is_array($data[$key] ?? null) && null !== ($found = $this->findProduct($data[$key]))) {
                return $found;
            }
        }

        return null;
    }

    private function fromJsonLd(array $node, string $url): array
    {
        $types = array_map(static fn ($t) => strtolower((string) preg_replace('~^.*[/#]~', '', (string) $t)), (array) ($node['@type'] ?? []));
        $data = [
            'title' => $this->scalar($node['name'] ?? null),
            'description' => $this->scalar($node['description'] ?? null),
            'brand' => $this->name($node['brand'] ?? $node['manufacturer'] ?? null),
            'sku' => $this->scalar($node['sku'] ?? $node['productGroupID'] ?? null),
            'gtin' => $this->gtin($node),
            'images' => $this->images($node['image'] ?? null),
            'category' => $this->category($node['category'] ?? null),
        ];

        $variants = [];
        $options = [];
        if (\in_array('productgroup', $types, true) && \is_array($node['hasVariant'] ?? null)) {
            $varies = array_map(static fn ($p) => (string) preg_replace('~^.*[/#]~', '', \is_array($p) ? (string) ($p['@id'] ?? $p['name'] ?? '') : (string) $p), (array) ($node['variesBy'] ?? []));
            foreach ((array) $node['hasVariant'] as $variant) {
                if (!\is_array($variant)) {
                    continue;
                }
                $offer = $this->offer($variant['offers'] ?? null);
                $values = [];
                foreach ($varies as $property) {
                    $key = lcfirst($property);
                    if (null !== ($value = $this->name($variant[$key] ?? null))) {
                        $values[$property] = $value;
                        $options[$property][] = $value;
                    }
                }
                $variants[] = [
                    'reference' => $this->scalar($variant['@id'] ?? null) ?? $this->scalar($variant['sku'] ?? null),
                    'title' => $this->scalar($variant['name'] ?? null),
                    'sku' => $this->scalar($variant['sku'] ?? null),
                    'gtin' => $this->gtin($variant),
                    'image' => $this->images($variant['image'] ?? null)[0] ?? null,
                    'options' => $values,
                ] + $offer;
            }
        } else {
            foreach ($this->offers($node['offers'] ?? null) as $i => $offer) {
                $variants[] = ['reference' => $offer['sku'] ?? null, 'sku' => $offer['sku'] ?? $data['sku'], 'gtin' => $data['gtin'], 'title' => $offer['name'] ?? null] + $offer;
            }
        }
        $data['variants'] = array_values(array_filter($variants, static fn ($v) => null !== ($v['price'] ?? null) || null !== ($v['title'] ?? null)));
        $data['options'] = $options;
        $data['seller'] = $this->sellerOf($node['offers'] ?? null);

        return $data;
    }

    /** @return list<array{price: ?string, currency: ?string, available: bool, url: ?string, sku?: ?string, name?: ?string}> */
    private function offers(mixed $offers): array
    {
        if (!\is_array($offers)) {
            return [];
        }
        if (!array_is_list($offers)) {
            $type = strtolower((string) preg_replace('~^.*[/#]~', '', (string) (((array) ($offers['@type'] ?? ''))[0] ?? '')));
            if ('aggregateoffer' === $type) {
                if (\is_array($offers['offers'] ?? null)) {
                    return $this->offers($offers['offers']);
                }
                $offers = ['price' => $offers['lowPrice'] ?? $offers['price'] ?? null] + $offers;
            }
            $offers = [$offers];
        }
        $list = [];
        foreach ($offers as $offer) {
            if (\is_array($offer)) {
                $list[] = $this->offer($offer) + ['sku' => $this->scalar($offer['sku'] ?? null), 'name' => $this->scalar($offer['name'] ?? null)];
            }
        }

        return $list;
    }

    /** @return array{price: ?string, currency: ?string, available: bool, url: ?string} */
    private function offer(mixed $offer): array
    {
        if (\is_array($offer) && array_is_list($offer)) {
            $offer = $offer[0] ?? null;
        }
        if (!\is_array($offer)) {
            return ['price' => null, 'currency' => null, 'available' => true, 'url' => null];
        }
        $spec = \is_array($offer['priceSpecification'] ?? null) ? (array_is_list($offer['priceSpecification']) ? ($offer['priceSpecification'][0] ?? []) : $offer['priceSpecification']) : [];
        $price = $offer['price'] ?? $offer['lowPrice'] ?? $spec['price'] ?? null;
        $availability = strtolower((string) preg_replace('~^.*[/#]~', '', (string) ($offer['availability'] ?? 'InStock')));

        return [
            'price' => null === $price ? null : self::decimal((string) $price),
            'currency' => $this->scalar($offer['priceCurrency'] ?? $spec['priceCurrency'] ?? null),
            'available' => !\in_array($availability, ['outofstock', 'discontinued', 'soldout'], true),
            'url' => $this->scalar($offer['url'] ?? null),
        ];
    }

    private function sellerOf(mixed $offers): ?string
    {
        if (\is_array($offers) && array_is_list($offers)) {
            $offers = $offers[0] ?? null;
        }

        return \is_array($offers) ? $this->name($offers['seller'] ?? null) : null;
    }

    private function gtin(array $node): ?string
    {
        foreach (['gtin13', 'gtin', 'gtin14', 'gtin12', 'gtin8', 'isbn', 'mpn'] as $key) {
            if (null !== ($value = $this->scalar($node[$key] ?? null))) {
                return $value;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function images(mixed $image): array
    {
        $list = [];
        foreach (\is_array($image) && !array_is_list($image) ? [$image] : (array) $image as $item) {
            $url = \is_array($item) ? ($item['contentUrl'] ?? $item['url'] ?? null) : $item;
            if (\is_string($url) && '' !== trim($url)) {
                $list[] = trim($url);
            }
        }

        return $list;
    }

    /** @return list<string> */
    private function category(mixed $category): array
    {
        if (\is_string($category)) {
            return array_values(array_filter(array_map('trim', preg_split('~\s*[>/|]\s*~', $category) ?: [])));
        }

        return array_values(array_filter(array_map(fn ($c) => $this->name($c), (array) $category)));
    }

    // ── Microdata ──────────────────────────────────────────────────

    private function fromMicrodata(\DOMXPath $xpath, string $url): ?array
    {
        $scope = $xpath->query('//*[@itemscope and (contains(@itemtype, "schema.org/Product") or contains(@itemtype, "schema.org/IndividualProduct"))]')->item(0);
        if (!$scope instanceof \DOMElement) {
            return null;
        }
        $prop = function (string $name, ?\DOMNode $in = null) use ($xpath, $scope): ?string {
            $node = $xpath->query('.//*[@itemprop="'.$name.'"]', $in ?? $scope)->item(0);
            if (!$node instanceof \DOMElement) {
                return null;
            }
            foreach (['content', 'href', 'src', 'value', 'datetime'] as $attribute) {
                if ($node->hasAttribute($attribute)) {
                    return trim($node->getAttribute($attribute));
                }
            }

            return trim(preg_replace('~\s+~', ' ', $node->textContent)) ?: null;
        };
        $offer = $xpath->query('.//*[@itemprop="offers"]', $scope)->item(0);
        $brand = $xpath->query('.//*[@itemprop="brand"]', $scope)->item(0);
        $price = $prop('price', $offer) ?? $prop('lowPrice', $offer);
        $availability = strtolower((string) preg_replace('~^.*[/#]~', '', (string) $prop('availability', $offer)));

        return [
            'microdata' => true,
            'title' => $prop('name'),
            'description' => $prop('description'),
            'brand' => $brand instanceof \DOMElement && $brand->hasAttribute('itemscope') ? $prop('name', $brand) : $prop('brand'),
            'sku' => $prop('sku'),
            'gtin' => $prop('gtin13') ?? $prop('gtin'),
            'images' => array_filter([$prop('image')]),
            'variants' => null === $price ? [] : [[
                'price' => self::decimal($price),
                'currency' => $prop('priceCurrency', $offer),
                'available' => !\in_array($availability, ['outofstock', 'discontinued', 'soldout'], true),
                'url' => null,
            ]],
        ];
    }

    // ── OpenGraph ──────────────────────────────────────────────────

    /** @return array<string, string> the page's <meta property|name=... content=...>, first value of each */
    private function meta(\DOMXPath $xpath): array
    {
        $meta = [];
        foreach ($xpath->query('//meta[@content]') as $node) {
            \assert($node instanceof \DOMElement);
            $key = strtolower($node->getAttribute('property') ?: $node->getAttribute('name') ?: $node->getAttribute('itemprop'));
            if ('' !== $key && !isset($meta[$key])) {
                $meta[$key] = trim($node->getAttribute('content'));
            }
        }

        return $meta;
    }

    private function fromOpenGraph(array $meta, string $url): ?array
    {
        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? null;
        if (null === $title) {
            return null;
        }
        $price = $meta['product:price:amount'] ?? $meta['og:price:amount'] ?? $meta['product:sale_price:amount'] ?? null;
        $availability = strtolower($meta['product:availability'] ?? $meta['og:availability'] ?? 'instock');

        return [
            'title' => $title,
            'description' => $meta['og:description'] ?? $meta['description'] ?? null,
            'brand' => $meta['product:brand'] ?? $meta['og:brand'] ?? null,
            'image' => $meta['og:image:secure_url'] ?? $meta['og:image'] ?? $meta['twitter:image'] ?? null,
            'variants' => null === $price ? [] : [[
                'price' => self::decimal($price),
                'currency' => $meta['product:price:currency'] ?? $meta['og:price:currency'] ?? null,
                'available' => !str_contains(str_replace([' ', '_'], '', $availability), 'outofstock') && 'oos' !== $availability,
                'url' => null,
            ]],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function canonical(\DOMXPath $xpath, string $url): ?string
    {
        $node = $xpath->query('//link[@rel="canonical"][@href]')->item(0);

        return $node instanceof \DOMElement ? trim($node->getAttribute('href')) ?: null : null;
    }

    private function text(\DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)->item(0);

        return $node ? (trim(preg_replace('~\s+~', ' ', $node->textContent)) ?: null) : null;
    }

    private function scalar(mixed $value): ?string
    {
        if (\is_array($value)) {
            $value = $value['@value'] ?? $value[0] ?? null;
        }
        if (null === $value || \is_array($value) || '' === trim((string) $value)) {
            return null;
        }

        return trim((string) $value);
    }

    private function name(mixed $value): ?string
    {
        if (\is_array($value)) {
            return array_is_list($value) ? $this->name($value[0] ?? null) : $this->scalar($value['name'] ?? $value['@id'] ?? null);
        }

        return $this->scalar($value);
    }

    /** "1 299,00", "1,299.00", "12.5" → "1299.00", "1299.00", "12.5". */
    public static function decimal(string $price): string
    {
        $price = preg_replace('~[^\d.,]~', '', $price) ?? '';
        if (str_contains($price, ',') && str_contains($price, '.')) {
            // The last separator is the decimal one.
            $price = strrpos($price, ',') > strrpos($price, '.') ? str_replace(['.', ','], ['', '.'], $price) : str_replace(',', '', $price);
        } elseif (str_contains($price, ',')) {
            $price = preg_match('~,\d{3}$~', $price) && substr_count($price, ',') > 1 ? str_replace(',', '', $price) : str_replace(',', '.', $price);
        }

        return '' === $price ? '0' : $price;
    }

    private function absolute(string $href, string $base): string
    {
        $href = trim($href);
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $dir = preg_replace('~/[^/]*$~', '/', (string) ($parts['path'] ?? '/'));

        return $origin.$dir.$href;
    }

    /**
     * A page's description as HTML (Product::$description's contract), made
     * from its text: the page's markup is not passed on (its attributes may
     * run script), its lines kept as <br>, every character escaped.
     */
    private static function html(string $description): ?string
    {
        $text = (string) preg_replace('~<br\s*/?>|</(?:p|div|li|h[1-6]|tr)\s*>~i', "\n", $description);
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => '' !== $line));

        return $lines ? implode("<br>\n", array_map(static fn (string $line): string => htmlspecialchars($line, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8'), $lines)) : null;
    }
}
