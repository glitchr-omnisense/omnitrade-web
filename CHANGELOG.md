# Changelog

## Unreleased (1.x)

### Fixed
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
- `Api::MAX_REDIRECTS` (5), and the `too_many_redirects` provider code past it.
