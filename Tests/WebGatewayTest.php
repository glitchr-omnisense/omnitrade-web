<?php

namespace Omnitrade\Web\Tests;

use Omnitrade\Exception\ProviderException;
use Omnitrade\GatewayInterface;
use Omnitrade\Request\AffiliateLink;
use Omnitrade\Request\FetchInventory;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\FetchProducts;
use Omnitrade\Request\Purchase;
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
