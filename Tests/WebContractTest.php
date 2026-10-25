<?php

namespace Omnitrade\Web\Tests;

use Omnitrade\GatewayFactoryInterface;
use Omnitrade\Testing\GatewayContractTests;
use Omnitrade\Web\WebGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnitrade's conformance suite, on a recorded product page. */
final class WebContractTest extends TestCase
{
    use GatewayContractTests;

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new WebGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['allow_private' => true, 'affiliate' => ['shop.example' => ['tag' => 'site-21']]];
    }

    protected function productReference(): string
    {
        return 'https://shop.example/products/margaux';
    }

    protected function recordedAnswers(): array
    {
        return ['GET /products/margaux' => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/jsonld-product.html'), ['response_headers' => ['content-type' => 'text/html; charset=utf-8']])];
    }
}
