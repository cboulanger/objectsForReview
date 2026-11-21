#!/bin/bash
# PHP unit tests of the plugin's classes, run with PHPUnit inside the dev container
# (the tests boot OJS there). Arguments are passed to PHPUnit, e.g.:
#   tests/unit/php/run.sh --filter IsbnLookupTest
#   tests/unit/php/run.sh --exclude-group db
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../../lib/env.sh"

require_container
ensure_phpunit
in_container phpunit -c "$PLUGIN_IN_CONTAINER/tests/unit/php/phpunit.xml" "$@"
