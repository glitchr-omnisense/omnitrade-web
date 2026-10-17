---
title: Reading a product page
order: 10
---

# omnitrade/web: reading a product page

## Installation

```sh
composer require omnitrade/web symfony/http-client
```

With omniscient/omnitrade's Symfony bundle the `web` factory is registered as
soon as the package is installed; without Symfony:

```php
$gateway = (new Omnitrade\Web\WebGatewayFactory($httpClient))->create(['currency' => 'EUR']);
```

## Options

| Option | Default | |
|---|---|---|
| `user_agent` | `Mozilla/5.0 (compatible; Omnitrade/1.0; …)` | what the shop sees |
| `timeout` | `10` | seconds |
| `max_bytes` | `3000000` | a longer page is cut there |
| `allow_private` | `false` | `true`: addresses of the local network may be read (an intranet, tests) |
| `currency` | `EUR` | of a price given without one |
| `affiliate` | `[]` | host (or suffix) ⇒ query parameters, for `affiliateLink()` |

## What is read, in order

1. **JSON-LD** (`<script type="application/ld+json">`): the first `Product`
   (also `IndividualProduct`, `ProductModel`, `Book`, `Vehicle`) or
   `ProductGroup`, at the top, in a list, in an `@graph`, a `mainEntity` or an
   `ItemList`. Its `name`, `description`, `brand` (or `manufacturer`), `sku`,
   `gtin13`/`gtin`/`isbn`/`mpn`, `image` (strings or `ImageObject`s),
   `category`, and `offers`: an `Offer`, a list (a variant each) or an
   `AggregateOffer` (its `lowPrice`); `priceSpecification` when the price is
   there; `availability` (`OutOfStock`, `Discontinued`, `SoldOut` are not
   available); `seller` as the merchant. A `ProductGroup`'s `hasVariant` are
   the variants, `variesBy` their options.
2. **Microdata**: the first `itemscope` of type `schema.org/Product`, its
   `itemprop`s (`name`, `image`, `brand`, `sku`, `gtin13`, `offers` → `price`,
   `priceCurrency`, `availability`).
3. **OpenGraph**: `og:title`, `og:description`, `og:image`,
   `product:price:amount` / `og:price:amount`, `product:price:currency`,
   `product:brand`, `product:availability`.

What a richer source leaves empty is taken from the next one, then from the
page (`<title>`). The product's `reference` and `url` are the page's
canonical address (`<link rel="canonical">`, else `og:url`, else the address
read); relative images are made absolute; `raw['source']` says which markup
answered (`json-ld`, `microdata`, `opengraph`). A page with none of them
gives `null`.

Prices are read whatever their writing: `1 299,00`, `1.299,00 €`,
`$1,299.00`.

## Affiliate links

`affiliateLink($url, ?$tag)` adds the parameters configured for the page's
host and drops the tracking ones it came with (`utm_*`, `ref`, `psc`…). The
request's tag replaces the value of a rule's only parameter. No rule, or a
rule left empty: `null`.

## Limits

- A page drawn by JavaScript with no product markup in its HTML gives `null`.
- Shops that refuse robots answer an error page or a captcha: a
  `ProviderException` (HTTP ≥ 400) or `null`. Amazon is one of them: use
  `omnitrade/amazon`.
- A price is the page's at the time it was read: keep the date with it.
