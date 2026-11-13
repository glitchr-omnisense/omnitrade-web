# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Release
- Requires `omnisense/omnitrade` `^1.0`, a stable version, where it required `^1.0@dev`;
  `extra.branch-alias` `dev-1.x` → `1.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml: the package installed alone,
  PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml` (that CI on the tag,
  then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the package's own tests.
- `phpstan.neon` at level max, as its siblings, and `phpstan-baseline.neon`: what was there when
  PHPStan came in (2026-11-13), which may only shrink.
- README: the install line and a first call: an affiliate link, built locally (no account, no
  request).

### Fixed
- `Product::$description` is HTML, as the family's contract has it: the page's description as
  escaped text, its lines joined by `<br>`, where it came as text with the entities decoded (a
  `&lt;script&gt;` in a page's description came out as `<script>`, which a template trusting the
  contract would have run).
- A redirect to the local network is refused before it is requested (SSRF): redirects are
  followed by `Api::page()`, not by the HTTP client (`max_redirects: 0`), and each `Location` is
  checked before its request. The final address was checked only after the client had followed
  the redirects, the private address already requested.
- The check covers IPv6: a name's AAAA addresses are checked with its A addresses, IPv6's
  loopback, unique local, link-local, site-local, multicast and documentation ranges are refused,
  and so is an IPv4 address carried in IPv6 (`::ffff:`, `64:ff9b::`, `2002::`) when it is refused;
  shared (`100.64/10`), multicast, benchmark and documentation IPv4 ranges are refused too.
- A name is requested at the address it was checked by (the HTTP client's `resolve` option): a
  second DNS answer cannot point it elsewhere. A name that resolves to no address is refused
  (`unresolved`).

### Added
- The README's Responsibility section, as every package of the family has.
- `Api::MAX_REDIRECTS` (5), and the `too_many_redirects` provider code past it.
