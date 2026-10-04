<?php

namespace Omnitrade\Web;

use Omnitrade\Exception\ProviderException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a page the way a browser asks for it, and no more: http(s) only, at
 * most max_bytes, a few redirects, and - addresses being pasted by anyone -
 * never a machine of the local network unless allow_private says so.
 */
final class Api
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; Omnitrade/1.0; +https://github.com/glitchr-studio/omnitrade-web)';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $userAgent = self::USER_AGENT,
        private readonly int $timeout = 10,
        private readonly int $maxBytes = 3_000_000,
        private readonly bool $allowPrivate = false,
    ) {
    }

    /**
     * The page's HTML and the address it was finally served from.
     *
     * @return array{html: string, url: string, status: int}
     *
     * @throws ProviderException when the address is refused or does not answer a page
     */
    public function page(string $url): array
    {
        $this->assertReadable($url);
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                ],
                'timeout' => $this->timeout,
                'max_redirects' => 5,
            ]);
            $status = $response->getStatusCode();
            $html = '';
            foreach ($this->http->stream($response) as $chunk) {
                $html .= $chunk->getContent();
                if (\strlen($html) >= $this->maxBytes) {
                    $response->cancel();
                    break;
                }
            }
            $final = (string) ($response->getInfo('url') ?: $url);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException('web', 'The page could not be read: '.$e->getMessage(), null, $e);
        }
        if ($status >= 400) {
            throw new ProviderException('web', sprintf('The page answered HTTP %d.', $status), (string) $status);
        }
        if ($final !== $url) {
            $this->assertReadable($final);
        }

        return ['html' => substr($html, 0, $this->maxBytes), 'url' => $final, 'status' => $status];
    }

    /** @throws ProviderException */
    public function assertReadable(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host) {
            throw new ProviderException('web', sprintf('Not a web address: "%s".', $url), 'invalid_url');
        }
        if ($this->allowPrivate) {
            return;
        }
        $host = trim($host, '[]');
        $ips = filter_var($host, \FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ('localhost' === strtolower($host) || str_ends_with(strtolower($host), '.localhost') || str_ends_with(strtolower($host), '.local')) {
            throw new ProviderException('web', sprintf('"%s" is on the local network.', $host), 'private_address');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
                throw new ProviderException('web', sprintf('"%s" is on the local network.', $host), 'private_address');
            }
        }
    }
}
