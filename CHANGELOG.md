# Changelog

## [Unreleased]

### Fixed

- An unreachable Static Maps no longer carries the server key or the signature: the message and
  the previous (now a copy of the client's exception, the same class and code) have them masked
  (`key=***`, `signature=***`), and no trace of the chain keeps its arguments. Needs
  omnisense/omnimap's `Http\Redaction`.
- `capabilities()->staticMap` is off without a key (`server_key`, else `api_key`), and `image()`
  then throws `NotSupportedException` before any request: it was on as soon as an HTTP client was
  given, and `image()` threw `InvalidConfigException`, so omnibridge's `cache.make: auto` tried to
  make pictures it could not.
