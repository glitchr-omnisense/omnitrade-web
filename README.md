# omnitrade/web

Any product page for [omniscient/omnitrade](https://github.com/glitchr-studio/omnitrade): the page is
fetched and its own markup read as an Omnitrade `Product` - the JSON-LD `Product` (or
`ProductGroup` and its variants) most shops publish for search engines, else schema.org
microdata, else the OpenGraph tags. No account, no key. And affiliate links, by host.

```php
$gateway = (new WebGatewayFactory($http))->create([]);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `omniscient/omnitrade` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

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

```php
$product = $registry->get('web')->fetchProduct('https://boutique.example/p/poussette-yoyo');
$product->title; $product->brand; $product->price(); $product->media[0]->url; $product->merchant->name;

$registry->get('web')->affiliateLink('https://www.amazon.fr/dp/B0C1234567?psc=1');   // …/dp/B0C1234567?tag=mysite-21
```

Nothing is bought here: `supports(Purchase::class)` is false, as for `FetchProducts` and
`FetchInventory` (the web is not a catalogue to page through). See
[docs/reading-a-page.md](docs/reading-a-page.md) for what is read where, the options and the
limits (pages drawn by JavaScript, shops that refuse robots).

Addresses are pasted by anyone: only `http(s)` is read, at most `max_bytes`, and never a machine
of the local network unless `allow_private` says so.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
