# Changelog

## [Unreleased]

### Fixed

- `capabilities()->staticMap` is off without a key (`server_key`, else `api_key`), and `image()`
  then throws `NotSupportedException` before any request: it was on as soon as an HTTP client was
  given, and `image()` threw `InvalidConfigException`, so omnibridge's `cache.make: auto` tried to
  make pictures it could not.
