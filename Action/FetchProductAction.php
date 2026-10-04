<?php

namespace Omnitrade\Web\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\Request;
use Omnitrade\Web\Api;
use Omnitrade\Web\PageReader;

/**
 * A product by the address of its page: read, then its markup read as a
 * Product. A bare id means nothing to the web (no catalogue to look it up
 * in): null, as for a page that describes no product.
 */
final class FetchProductAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct(private readonly PageReader $reader)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchProduct;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchProduct);
        if (null === $request->reference->url) {
            $request->setResult(null);

            return;
        }
        $page = $this->api->page($request->reference->url);
        $request->setResult($this->reader->read($page['html'], $page['url']));
    }
}
