<?php

namespace Omnitrade\Web\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Request\AffiliateLink;
use Omnitrade\Request\Request;

/**
 * The page's address with the site's affiliate parameters, by host: the
 * `affiliate` option maps a host - or a suffix of it, "amazon.fr" for
 * www.amazon.fr - to the query parameters a partner programme reads
 * ({tag: 'mysite-21'}). The request's tag, when given, replaces the value
 * of a rule's only parameter. No rule for the host, or a rule left empty:
 * null (no affiliate link;
 * the plain address stays the one to use).
 *
 * Tracking parameters other shops add (utm_*, ref, psc...) are dropped, so
 * the link stays the product's.
 */
final class AffiliateLinkAction implements ActionInterface
{
    private const TRACKING = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'ref', 'ref_', 'psc', 'pd_rd_w', 'pd_rd_r', 'pf_rd_p', 'pf_rd_r', 'pd_rd_wg', 'smid', 'th', 'linkCode', 'linkId', 'camp', 'creative', 'creativeASIN'];

    /** @param array<string, array<string, string>> $rules */
    public function __construct(private readonly array $rules = [])
    {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof AffiliateLink;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof AffiliateLink);
        $url = $request->reference->url;
        $host = strtolower((string) parse_url((string) $url, \PHP_URL_HOST));
        $rule = null;
        foreach ($this->rules as $suffix => $parameters) {
            $suffix = strtolower(ltrim((string) $suffix, '.'));
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                // A rule whose values are empty (a tag not configured yet) is no rule.
                $rule = array_filter((array) $parameters, static fn ($v) => null !== $v && '' !== (string) $v) ?: null;
                break;
            }
        }
        if (null === $url || null === $rule) {
            $request->setResult(null);

            return;
        }
        if (null !== $request->tag && 1 === \count($rule)) {
            $rule = [array_key_first($rule) => $request->tag];
        }

        $request->setResult(self::withQuery($url, $rule));
    }

    /** @param array<string, string> $parameters */
    public static function withQuery(string $url, array $parameters): string
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query = array_diff_key($query, array_flip(self::TRACKING));
        $query = array_merge($query, array_map('strval', $parameters));

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/')
            .($query ? '?'.http_build_query($query) : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
