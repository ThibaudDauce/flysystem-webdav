# flysystem/webdav

## Installation

```bash
composer require thibaud-dauce/flysystem-webdav
```

The package is still in 0.x right now because I need to test it inside my application before going 1.0.

## Tests

To run the test, you need a Webdav server with a folder `webdav_tests` at the root. Export env variables:

```
export TESTS_WEBDAV_BASE_URL=https://cloud.example.com
export TESTS_WEBDAV_USERNAME=webdav_tests
export TESTS_WEBDAV_PASSWORD=xxxxxxx
```

Then run PHPUnit:

```bash
composer update
vendor/bin/phpunit tests
```