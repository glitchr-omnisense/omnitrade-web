<?php

namespace Omnitrade\Web;

use Omnitrade\Exception\ProviderException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a page the way a browser asks for it, and no more: http(s) only, at
 * most max_bytes, a few redirects, and - addresses being pasted by anyone -
 * never a machine of the local network unless allow_private says so.
 *
 * The redirects are followed here, not by the HTTP client: each address, the
 * first and every Location, is checked before it is requested. A name is
 * checked by every address it resolves to (IPv4 and IPv6), and the request is
 * made to the address checked (the client's "resolve" option), so a second
 * lookup cannot answer another one. Refused: loopback, private, shared (CGNAT),
 * link-local, multicast, reserved and documentation ranges, IPv6's unique local,
 * link-local and site-local, and an IPv4 address mapped into IPv6 (::ffff:,
 * NAT64's 64:ff9b::, 6to4's 2002::) when the IPv4 address is refused.
 */
final class Api
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; Omnitrade/1.0; +https://github.com/glitchr-omnisense/omnitrade-web)';
    public const MAX_REDIRECTS = 5;

    /** Not on the public internet (RFC 6890 and IANA's special-purpose registries). */
    private const REFUSED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', 'fc00::/7', 'fe80::/10',
        'fec0::/10', 'ff00::/8',
    ];

    /** IPv6 prefixes carrying an IPv4 address: prefix => the IPv4 address's byte offset. */
    private const EMBEDDING = ['::ffff:0:0/96' => 12, '::/96' => 12, '64:ff9b::/96' => 12, '64:ff9b:1::/48' => 12, '2002::/16' => 2];

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /**
     * @param (\Closure(string): list<string>)|null $resolver a host's addresses, IPv4 and IPv6 (tests); the system's resolver by default
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $userAgent = self::USER_AGENT,
        private readonly int $timeout = 10,
        private readonly int $maxBytes = 3_000_000,
        private readonly bool $allowPrivate = false,
        ?\Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? self::lookup(...);
    }

    /**
     * The page's HTML and the address it was finally served from.
     *
     * @return array{html: string, url: string, status: int}
     *
     * @throws ProviderException when an address is refused or does not answer a page
     */
    public function page(string $url): array
    {
        for ($redirects = 0; ; ++$redirects) {
            $options = [
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                ],
                'timeout' => $this->timeout,
                'max_redirects' => 0,
            ];
            if (null !== $pin = $this->checked($url)) {
                $options['resolve'] = [$pin[0] => $pin[1]];
            }
            try {
                $response = $this->http->request('GET', $url, $options);
                $status = $response->getStatusCode();
                $location = \in_array($status, [301, 302, 303, 307, 308], true) ? ($response->getHeaders(false)['location'][0] ?? null) : null;
                if (null !== $location) {
                    $response->cancel();
                    if ($redirects >= self::MAX_REDIRECTS) {
                        throw new ProviderException('web', sprintf('The page redirected more than %d times.', self::MAX_REDIRECTS), 'too_many_redirects');
                    }
                    $url = self::absolute($url, $location);
                    continue;
                }
                $html = '';
                foreach ($this->http->stream($response) as $chunk) {
                    $html .= $chunk->getContent();
                    if (\strlen($html) >= $this->maxBytes) {
                        $response->cancel();
                        break;
                    }
                }
            } catch (HttpExceptionInterface $e) {
                throw new ProviderException('web', 'The page could not be read: '.$e->getMessage(), null, $e);
            }
            if ($status >= 400) {
                throw new ProviderException('web', sprintf('The page answered HTTP %d.', $status), (string) $status);
            }

            return ['html' => substr($html, 0, $this->maxBytes), 'url' => $url, 'status' => $status];
        }
    }

    /** @throws ProviderException */
    public function assertReadable(string $url): void
    {
        $this->checked($url);
    }

    /**
     * Checks an address; answers the host and the address to request it at,
     * when the host is a name that was resolved here.
     *
     * @return array{string, string}|null
     *
     * @throws ProviderException
     */
    private function checked(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host) {
            throw new ProviderException('web', sprintf('Not a web address: "%s".', $url), 'invalid_url');
        }
        if ($this->allowPrivate) {
            return null;
        }
        $host = trim($host, '[]');
        $name = rtrim(strtolower($host), '.');
        if ('localhost' === $name || str_ends_with($name, '.localhost') || str_ends_with($name, '.local')) {
            throw new ProviderException('web', sprintf('"%s" is on the local network.', $host), 'private_address');
        }
        $literal = false !== filter_var($host, \FILTER_VALIDATE_IP);
        $ips = $literal ? [$host] : ($this->resolver)($host);
        if (!$ips) {
            throw new ProviderException('web', sprintf('"%s" could not be resolved.', $host), 'unresolved');
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                throw new ProviderException('web', sprintf('"%s" is on the local network.', $host), 'private_address');
            }
        }

        return $literal ? null : [$host, $ips[0]];
    }

    private static function isPublic(string $ip): bool
    {
        $bytes = false === filter_var($ip, \FILTER_VALIDATE_IP) ? false : inet_pton($ip);
        if (false === $bytes) {
            return false;
        }
        if (16 === \strlen($bytes)) {
            foreach (self::EMBEDDING as $prefix => $offset) {
                if (self::within($bytes, $prefix)) {
                    $v4 = substr($bytes, $offset, 4);

                    return "\0\0\0\0" !== $v4 && "\0\0\0\1" !== $v4 && self::isPublic((string) inet_ntop($v4));
                }
            }
        }
        foreach (self::REFUSED as $range) {
            if (self::within($bytes, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function within(string $bytes, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $net = (string) inet_pton($network);
        if (\strlen($net) !== \strlen($bytes)) {
            return false;
        }
        $whole = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (substr($bytes, 0, $whole) !== substr($net, 0, $whole)) {
            return false;
        }
        if (0 === $rest) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (\ord($bytes[$whole]) & $mask) === (\ord($net[$whole]) & $mask);
    }

    /** @return list<string> a host's IPv4 and IPv6 addresses, as the system resolves them */
    private static function lookup(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, \DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /** A Location made absolute against the address that answered it (RFC 3986, 5.2). */
    private static function absolute(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) {
            return $location;
        }
        $b = parse_url($base);
        $scheme = $b['scheme'] ?? 'https';
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        $host = $b['host'] ?? '';
        $origin = $scheme.'://'.(str_contains($host, ':') && !str_starts_with($host, '[') ? '['.$host.']' : $host).(isset($b['port']) ? ':'.$b['port'] : '');
        $location = preg_replace('~#.*$~', '', $location);
        if ('' === $location) {
            return $origin.($b['path'] ?? '/').(isset($b['query']) ? '?'.$b['query'] : '');
        }
        if (str_starts_with($location, '?')) {
            return $origin.($b['path'] ?? '/').$location;
        }
        [$path, $query] = array_pad(explode('?', $location, 2), 2, null);
        if (!str_starts_with($path, '/')) {
            $path = preg_replace('~[^/]*$~', '', $b['path'] ?? '/').$path;
        }
        $segments = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ('..' === $segment) {
                \count($segments) > 1 && array_pop($segments);
            } elseif ('.' !== $segment) {
                $segments[] = $segment;
            }
        }
        $path = implode('/', $segments);
        if (str_ends_with($location, '/.') || str_ends_with($location, '/..') || '.' === $location || '..' === $location) {
            $path .= '/';
        }

        return $origin.('' === $path || '/' !== $path[0] ? '/'.$path : $path).(null !== $query ? '?'.$query : '');
    }
}
