.PHONY: install test stan check spec example

install:
	composer install

test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan analyse --memory-limit=512M

check: stan test

## Refresh openapi/pulsenote-api.json from the live API, then show the drift.
## Override the source with: make spec SPEC_URL=http://localhost:3000/api-json
spec:
	php scripts/fetch-spec.php
	vendor/bin/phpunit --filter SpecCoverageTest

example:
	php examples/send-email.php
