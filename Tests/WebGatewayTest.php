<?php

namespace Omnitrade\Web\Tests;

use Omnitrade\Exception\ProviderException;
use Omnitrade\GatewayInterface;
use Omnitrade\Request\AffiliateLink;
use Omnitrade\Request\FetchInventory;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\FetchProducts;
use Omnitrade\Request\Purchase;
use Omnitrade\Web\Api;
use Omnitrade\Web\PageReader;
use Omnitrade\Web\WebGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebGatewayTest extends TestCase
{
    /** @var list<array{string, string, array}> */
    private array $requests = [];

    private function gateway(array $pages, array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($pages): MockResponse {
            $this->requests[] = [$method, $url, $options];
            $file = $pages[$url] ?? null;

            return null === $file ? new MockResponse('Not found', ['http_code' => 404]) : new MockResponse(file_get_contents(__DIR__.'/Fixtures/'.$file), ['response_headers' => ['content-type' => 'text/html; charset=utf-8']]);
        });

        return (new WebGatewayFactory($http))->create($options + ['allow_private' => true]);
    }

    public function testWhatItAnswers(): void
    {
        $gateway = $this->gateway([]);
        self::assertSame('web', $gateway->getName());
        self::assertTrue($gateway->supports(FetchProduct::class));
        self::assertTrue($gateway->supports(AffiliateLink::class));
        self::assertFalse($gateway->supports(Purchase::class));
        self::assertFalse($gateway->supports(FetchProducts::class));
        self::assertFalse($gateway->supports(FetchInventory::class));
    }

    public function testJsonLdProductInAGraph(): void
    {
        $product = $this->gateway(['https://boutique.example/p/poussette-yoyo?utm_source=x' => 'jsonld-product.html'])
            ->fetchProduct('https://boutique.example/p/poussette-yoyo?utm_source=x');

        self::assertNotNull($product);
        self::assertSame('web', $product->provider);
        self::assertSame('https://boutique.example/p/poussette-yoyo', $product->reference, 'the canonical address');
        self::assertSame('Poussette Yoyo³ & nacelle', $product->title);
        self::assertSame("Légère, pliable d'une main.", $product->description);
        self::assertSame('Stokke', $product->brand);
        self::assertSame(['Puériculture', 'Poussettes'], $product->categories);
        self::assertSame('Boutique SAS', $product->merchant->name);
        self::assertSame('https://boutique.example/img/yoyo-1.jpg', $product->media[0]->url, 'made absolute');
        self::assertCount(2, $product->media);
        self::assertSame(44990, $product->price()->amount);
        self::assertSame('EUR', $product->price()->currency);
        self::assertSame('8716134000123', $product->variants[0]->barcode);
        self::assertSame('json-ld', $product->raw['source']);
        self::assertStringContainsString('Omnitrade/1.0', implode(' ', $this->requests[0][2]['normalized_headers']['user-agent'] ?? []));
    }

    public function testAProductGroupAndItsVariants(): void
    {
        $product = $this->gateway(['https://x.example/gigoteuse' => 'jsonld-group.html'])->fetchProduct('https://x.example/gigoteuse');

        self::assertSame('Gigoteuse en coton bio', $product->title);
        self::assertSame('Petit Bateau', $product->brand);
        self::assertCount(2, $product->variants);
        self::assertSame('GIG-0-6-BL', $product->variants[0]->sku);
        self::assertSame(['size' => '0-6 mois', 'color' => 'Bleu'], $product->variants[0]->options);
        self::assertTrue($product->variants[0]->offer()->available);
        self::assertFalse($product->variants[1]->offer()->available);
        self::assertSame(3900, $product->price()->amount, 'the lowest');
        self::assertSame('size', $product->options[0]->name);
        self::assertSame(['0-6 mois', '6-18 mois'], $product->options[0]->values);
        self::assertSame('https://x.example/bl.jpg', $product->variants[0]->image->url);
    }

    public function testMicrodata(): void
    {
        $product = $this->gateway(['https://gien.example/service' => 'microdata.html'])->fetchProduct('https://gien.example/service');

        self::assertSame('Service à thé Gien', $product->title);
        self::assertSame('Gien', $product->brand);
        self::assertSame(129000, $product->price()->amount, '"1 290,00" read as 1290.00');
        self::assertSame('https://gien.example/media/gien.jpg', $product->media[0]->url);
        self::assertSame('microdata', $product->raw['source']);
        self::assertSame('gien.example', $product->merchant->name);
    }

    public function testOpenGraph(): void
    {
        $product = $this->gateway(['https://shop.example/robot?ref=home' => 'opengraph.html'])->fetchProduct('https://shop.example/robot?ref=home');

        self::assertSame('Robot pâtissier Artisan', $product->title);
        self::assertSame('https://shop.example/robot-artisan', $product->url);
        self::assertSame('KitchenAid', $product->brand);
        self::assertSame('Shop', $product->merchant->name);
        self::assertSame(59900, $product->price()->amount);
        self::assertSame('opengraph', $product->raw['source']);
    }

    public function testNoProductAndNoPage(): void
    {
        $gateway = $this->gateway([]);
        self::assertNull($gateway->fetchProduct('prod_123'), 'an id means nothing to the web');

        $this->expectException(ProviderException::class);
        $gateway->fetchProduct('https://nowhere.example/');
    }

    public function testTheLocalNetworkIsRefused(): void
    {
        $gateway = (new WebGatewayFactory(new MockHttpClient()))->create();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/local network/');
        $gateway->fetchProduct('http://127.0.0.1/admin');
    }

    /**
     * A redirect is followed by hand, each address checked before it is requested: a page
     * redirecting to the local network (the cloud's metadata address, a loopback, an IPv6
     * private or link-local address, an IPv4 address mapped into IPv6) is refused, the
     * private address never requested.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('privateLocations')]
    public function testARedirectToTheLocalNetworkIsRefusedBeforeItIsRequested(string $location): void
    {
        $requested = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($location, &$requested): MockResponse {
            $requested[] = $url;

            return str_starts_with($url, 'https://93.184.215.14/')
                ? new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => $location]])
                : new MockResponse('<html>secret</html>', ['response_headers' => ['content-type' => 'text/html']]);
        });
        $gateway = (new WebGatewayFactory($http))->create();

        try {
            $gateway->fetchProduct('https://93.184.215.14/p/poussette');
            self::fail('The redirect to '.$location.' was followed.');
        } catch (ProviderException $e) {
            self::assertSame('private_address', $e->providerCode);
        }
        self::assertSame(['https://93.184.215.14/p/poussette'], $requested, 'the private address is never requested');
    }

    public static function privateLocations(): iterable
    {
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'loopback' => ['http://127.0.0.1:8080/admin'];
        yield 'private' => ['https://10.1.2.3/'];
        yield 'shared' => ['http://100.64.0.1/'];
        yield 'protocol relative' => ['//192.168.1.1/'];
        yield 'localhost' => ['http://localhost/'];
        yield 'IPv6 loopback' => ['http://[::1]/'];
        yield 'IPv6 unique local' => ['http://[fd12:3456::1]/'];
        yield 'IPv6 link-local' => ['http://[fe80::1]/'];
        yield 'IPv4-mapped' => ['http://[::ffff:127.0.0.1]/'];
        yield 'NAT64' => ['http://[64:ff9b::a00:1]/'];
        yield '6to4' => ['http://[2002:7f00:1::]/'];
    }

    public function testARedirectToAPublicAddressIsFollowed(): void
    {
        $requested = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requested): MockResponse {
            $requested[] = [$url, $options['max_redirects']];

            return match ($url) {
                'https://93.184.215.14/p/old' => new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '../p/poussette-yoyo']]),
                'https://93.184.215.14/p/poussette-yoyo' => new MockResponse(file_get_contents(__DIR__.'/Fixtures/jsonld-product.html'), ['response_headers' => ['content-type' => 'text/html']]),
                default => new MockResponse('Not found', ['http_code' => 404]),
            };
        });

        $product = (new WebGatewayFactory($http))->create()->fetchProduct('https://93.184.215.14/p/old');
        self::assertSame('Poussette Yoyo³ & nacelle', $product->title);
        self::assertSame([['https://93.184.215.14/p/old', 0], ['https://93.184.215.14/p/poussette-yoyo', 0]], $requested, 'the client follows no redirect itself');
    }

    public function testRedirectsStopAfterFive(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => $url.'x']]));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/redirect/');
        (new WebGatewayFactory($http))->create()->fetchProduct('https://93.184.215.14/a');
    }

    /** A name is checked by the addresses it resolves to, and the request is made to the address checked (no second lookup to rebind). */
    public function testANameIsCheckedByItsAddressAndRequestedAtIt(): void
    {
        $requested = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requested): MockResponse {
            $requested[] = [$url, $options['resolve'] ?? []];

            return new MockResponse('<html></html>', ['response_headers' => ['content-type' => 'text/html']]);
        });
        $dns = ['shop.example' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'], 'rebind.example' => ['93.184.215.14', '10.0.0.7'], 'v6.example' => ['fd00::7']];
        $api = new Api($http, resolver: static fn (string $host): array => $dns[$host] ?? []);

        self::assertSame('https://shop.example/p', $api->page('https://shop.example/p')['url']);
        self::assertSame([['https://shop.example/p', ['shop.example' => '93.184.215.14']]], $requested);

        foreach (['https://rebind.example/', 'https://v6.example/', 'https://nowhere.example/'] as $url) {
            try {
                $api->page($url);
                self::fail($url.' was read.');
            } catch (ProviderException $e) {
                self::assertContains($e->providerCode, ['private_address', 'unresolved'], $url);
            }
        }
        self::assertCount(1, $requested, 'none of them requested');
    }

    public function testAffiliateLinks(): void
    {
        $gateway = $this->gateway([], ['affiliate' => ['amazon.fr' => ['tag' => 'fete-21'], 'fnac.com' => ['awc' => '1_2', 'origin' => 'aff']]]);

        self::assertSame('https://www.amazon.fr/dp/B0C1?tag=fete-21', $gateway->execute(new AffiliateLink('https://www.amazon.fr/dp/B0C1?ref_=abc&psc=1'))->getUrl());
        self::assertSame('https://www.amazon.fr/dp/B0C1?tag=other-21', $gateway->execute(new AffiliateLink('https://www.amazon.fr/dp/B0C1', 'other-21'))->getUrl(), 'the request\'s tag');
        self::assertSame('https://www.fnac.com/a1?awc=1_2&origin=aff', $gateway->execute(new AffiliateLink('https://www.fnac.com/a1'))->getUrl());
        self::assertNull($gateway->execute(new AffiliateLink('https://www.ikea.com/fr/p/1'))->getUrl(), 'no programme for the host');
    }

    public function testPrices(): void
    {
        self::assertSame('1299.00', PageReader::decimal('1.299,00 €'));
        self::assertSame('1299.00', PageReader::decimal('$1,299.00'));
        self::assertSame('12.5', PageReader::decimal('12,5'));
    }
}
