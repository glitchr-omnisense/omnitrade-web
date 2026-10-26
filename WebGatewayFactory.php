<?php

namespace Omnitrade\Web;

use Omnitrade\Config;
use Omnitrade\GatewayFactory;
use Omnitrade\Web\Action\AffiliateLinkAction;
use Omnitrade\Web\Action\FetchProductAction;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Any shop's product page, read from its own markup: the JSON-LD Product
 * (or ProductGroup and its variants) most shops publish for search engines,
 * else the schema.org microdata, else the OpenGraph tags. No account, no
 * key: fetchProduct() takes the page's address. Nothing is bought here.
 *
 *   options:
 *     user_agent: 'Mozilla/5.0 (compatible; Omnitrade/1.0; +https://github.com/glitchr-omnisense/omnitrade-web)'
 *     timeout: 10
 *     max_bytes: 3000000          # a page larger than this is cut there
 *     allow_private: false        # true: addresses of the local network may be read (tests, an intranet)
 *     currency: EUR               # a price found without a currency
 *     affiliate:                  # host (or a suffix of it) => query parameters added by affiliateLink()
 *       amazon.fr: { tag: 'mysite-21' }
 *       fnac.com: { awc: '1234_5678' }
 */
final class WebGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'web',
            'omnitrade.factory_title' => 'Web',
            'omnitrade.required_options' => [],
            'user_agent' => Api::USER_AGENT,
            'timeout' => 10,
            'max_bytes' => 3_000_000,
            'allow_private' => false,
            'currency' => 'EUR',
            'affiliate' => [],
            'omnitrade.api' => function (Config $c) {
                return new Api($this->http ?? HttpClient::create(), (string) $c['user_agent'], (int) $c['timeout'], (int) $c['max_bytes'], (bool) $c['allow_private']);
            },
            'omnitrade.action.product' => static fn (Config $c) => new FetchProductAction(new PageReader((string) $c['currency'])),
            'omnitrade.action.affiliate' => static fn (Config $c) => new AffiliateLinkAction((array) $c['affiliate']),
        ]);
    }
}
