# omnitrade/web

Any product page for [omnisense/omnitrade](https://github.com/glitchr-omnisense/omnitrade): the page is
fetched and its own markup read as an Omnitrade `Product` - the JSON-LD `Product` (or
`ProductGroup` and its variants) most shops publish for search engines, else schema.org
microdata, else the OpenGraph tags. No account, no key, no API: one `GET` of the page's address.
And affiliate links, by host, without any call.

| Operation | Call |
|---|---|
| product | `GET <the page's address>` (5 redirects at most, `max_bytes` read at most) |
| affiliate link | none |

## Installation

```sh
composer require omnitrade/web
```

Requires PHP 8.2, `ext-dom`, `ext-libxml`, `omnisense/omnitrade` (1.x) and `symfony/http-client`
(6.4, 7 or 8). With omnisense/omnitrade's Symfony bundle the `web` factory is registered as soon as
the package is installed.

```php
$gateway = (new WebGatewayFactory($http))->create([]);   // $http: the application's HTTP client; none given, the factory makes its own
```

## Credentials

None. The affiliate parameters of each partner programme (an Amazon tag, an Awin id) come from
those programmes' own accounts.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `user_agent` | string | `Mozilla/5.0 (compatible; Omnitrade/1.0; +https://github.com/glitchr-omnisense/omnitrade-web)` | no | what the shop sees; the request also sends `Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5` and `Accept-Language: fr-FR,fr;q=0.9,en;q=0.8` |
| `timeout` | int | `10` | no | seconds |
| `max_bytes` | int | `3000000` | no | a longer page is cut there |
| `allow_private` | bool | `false` | no | `true`: addresses of the local network may be read (an intranet, tests) |
| `currency` | string | `EUR` | no | of a price found without one |
| `affiliate` | map host => map of query parameters | `[]` | no | for `affiliateLink()`: a host, or a suffix of it, and the parameters a partner programme reads |

```yaml
omnitrade:
    gateways:
        web:
            factory: web
            options:
                currency: EUR                    # a price found without a currency
                affiliate:                       # host (or a suffix of it) => query parameters
                    amazon.fr: { tag: 'mysite-21' }
                    fnac.com: { awc: '1234_5678' }
```

## Capabilities

| Request | Supported |
|---|---|
| `FetchProduct` | yes, by a page's address (an id answers `null`) |
| `AffiliateLink` | yes, by the `affiliate` rules |
| `FetchProducts`, `FetchInventory` | no: the web is not a catalogue to page through |
| payments, `Notify`, `FetchOrder`, connected accounts, subscriptions | no: nothing is bought here |

## Behaviour

**Which addresses are read.** `http` and `https` only, with a host (else `Not a web address`,
`invalid_url`). Unless `allow_private`, the host `localhost`, any `*.localhost` or `*.local`, and
any host that is, or resolves (`gethostbynamel()`) to, a private or reserved IP address are refused
(`private_address`), before any request. Addresses are pasted by anyone: this keeps the gateway
off the machines of the local network.

> [!WARNING]
> Redirects are followed by the HTTP client itself (up to 5); the final address is checked once
> the page has been read, and a private one refused then. A public address that redirects to a
> private one has already been requested by that time: the check keeps its content out of the
> answer, not the request off the network.

**The page.** One `GET`, at most 5 redirects, streamed and cut at `max_bytes`. An HTTP status of
400 or more is a `ProviderException` with the status as `providerCode`. The markup is then read,
in order of trust - JSON-LD, microdata, OpenGraph - [as described below](reading-a-page.md#omnitradeweb-reading-a-product-page).
A page with none of them, or with no title at all, answers `null`. The product's `reference` and
`url` are the page's canonical address; its `merchant` is the offer's `seller`, else `og:site_name`,
else the host without `www.`; every offer is the merchant's, with `taxIncluded` true; `status` is
always active; there is no stock and no update date.

**Affiliate links.** `affiliateLink($url, ?$tag)` looks for a rule whose host is the address's, or
a suffix of it (`amazon.fr` for `www.amazon.fr`). Found, the rule's parameters are added to the
address's query (the request's tag, when given, replaces the value of a rule with one parameter
only), and the tracking parameters other shops add are dropped: `utm_source`, `utm_medium`,
`utm_campaign`, `utm_term`, `utm_content`, `gclid`, `fbclid`, `ref`, `ref_`, `psc`, `pd_rd_w`,
`pd_rd_r`, `pf_rd_p`, `pf_rd_r`, `pd_rd_wg`, `smid`, `th`, `linkCode`, `linkId`, `camp`, `creative`,
`creativeASIN`. No rule, a rule whose values are all empty, or a reference that is an id and not
an address: `null` (the plain address stays the one to use).

## Example

```php
<?php // web.php: a product page read from its own markup, on a page kept in the script

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Exception\ProviderException;
use Omnitrade\Web\WebGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$page = <<<'HTML'
<html><head>
<title>Pinot Noir - Cave Example</title>
<link rel="canonical" href="https://cave.example/vins/pinot-noir">
<meta property="og:title" content="Pinot Noir">
<meta property="og:site_name" content="Cave Example">
<meta property="og:image" content="/img/pinot.jpg">
<script type="application/ld+json">
{"@context": "https://schema.org", "@type": "ProductGroup", "name": "Pinot Noir", "brand": {"@type": "Brand", "name": "Domaine Example"},
 "productGroupID": "PN", "variesBy": ["https://schema.org/size"], "category": "Vins > Rouges",
 "hasVariant": [
   {"@type": "Product", "sku": "PN-75", "name": "Pinot Noir 75 cl", "size": "75 cl", "gtin13": "3760000000017",
    "offers": {"@type": "Offer", "price": "16,00", "priceCurrency": "EUR", "availability": "https://schema.org/InStock"}},
   {"@type": "Product", "sku": "PN-150", "name": "Pinot Noir Magnum", "size": "150 cl",
    "offers": {"@type": "Offer", "price": "34.50", "priceCurrency": "EUR", "availability": "https://schema.org/OutOfStock"}}
 ]}
</script>
</head><body><h1>Pinot Noir</h1></body></html>
HTML;
$http = new MockHttpClient(static fn (string $method, string $url) => new MockResponse($page, ['response_headers' => ['content-type' => 'text/html; charset=utf-8']]));
$web = (new WebGatewayFactory($http))->create(['affiliate' => ['cave.example' => ['aff' => 'mysite']]]);

$product = $web->fetchProduct('https://cave.example/vins/pinot-noir?utm_source=newsletter');
printf("%s [%s] by %s, sold by %s, read from its %s\n", $product->title, $product->reference, $product->brand, $product->merchant->name, $product->raw['source']);
echo 'categories: ', implode(', ', $product->categories), '; options: ', $product->options[0]->name, ' = ', implode(', ', $product->options[0]->values), "\n";
foreach ($product->variants as $variant) {
    printf("  %-7s %-18s %s %s %s\n", $variant->sku, $variant->title, $variant->price(), $variant->offer()->available ? 'available' : 'unavailable', $variant->barcode ?? '');
}
echo 'media: ', $product->media[0]->url ?? 'none', "\n";
echo $web->affiliateLink('https://cave.example/vins/pinot-noir?utm_source=newsletter'), "\n";
var_dump($web->fetchProduct('PN'));   // an id means nothing to the web: no call
try {
    $web->fetchProduct('http://192.168.1.10/admin');
} catch (ProviderException $e) {
    echo $e->getMessage(), ' (', $e->providerCode, ")\n";
}
```

```
$ php web.php
Pinot Noir [https://cave.example/vins/pinot-noir] by Domaine Example, sold by Cave Example, read from its json-ld
categories: Vins, Rouges; options: size = 75 cl, 150 cl
  PN-75   Pinot Noir 75 cl   16.00 EUR available 3760000000017
  PN-150  Pinot Noir Magnum  34.50 EUR unavailable 
media: https://cave.example/img/pinot.jpg
https://cave.example/vins/pinot-noir?aff=mysite
NULL
[web] "192.168.1.10" is on the local network. (private_address)
```

(run on 2026-10-25 in omnisense/omnitrade's harness image, PHP 8.4, with no network.)

What it shows: a `ProductGroup`'s variants, their `variesBy` values as options (named by the
schema.org property, `size`), a price written `16,00` read as 16.00 EUR, the out-of-stock variant
not available, the category split on `>`, the image taken from `og:image` (made absolute) since
the JSON-LD has none, `og:site_name` as the merchant, the tracking parameter dropped from the
affiliate link, a bare id answered `null` without a call, a local address refused before any call.

## Errors

Every failure is an `Omnitrade\Exception\ProviderException` whose `provider` is `web`:

- `Not a web address: "..."` (`invalid_url`), `"..." is on the local network.` (`private_address`);
- `The page answered HTTP <status>.`, the status as `providerCode`;
- `The page could not be read: ...` (DNS, connection, timeout), the HTTP client's exception as
  `previous`.

A page that describes no product is `null`, not an exception. Every request but `FetchProduct` and
`AffiliateLink` raises `RequestNotSupportedException` before any call.

## Verified, and not

Tested offline, 21 tests: `Tests/WebGatewayTest.php` (what it answers, JSON-LD in a graph, a
`ProductGroup` and its variants from `Tests/Fixtures/jsonld-group.html`, microdata, OpenGraph, no
product and no page, the local network refused, affiliate links, prices in every writing) and
`Tests/WebContractTest.php`, the family's conformance suite on a recorded product page (nothing to
fix).

Not verified: no shop's page was read live in the tests; pages drawn by JavaScript and shops that
refuse robots are limits, not tested cases ([limits](reading-a-page.md#limits)).

## Responsibility

An independent client, affiliated with no shop it reads and with no partner programme it writes
links for. Whoever runs it complies with the terms of each site it reads (their robots rules and
their conditions of use included) and of each partner programme, and obtains what they require:
see omnisense/omnitrade's README, Responsibility section.

