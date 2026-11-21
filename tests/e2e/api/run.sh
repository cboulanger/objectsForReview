#!/bin/bash
# E2E tests of the REST API against the dev container (dev/ojs-dev.sh up).
# Creates unique test data on every run (hidden works, e2e-*@example.org users);
# dev/ojs-dev.sh reset removes it. Arguments are passed to node --test, e.g.
#   tests/e2e/api/run.sh --test-name-pattern=duplicates
# OFR_E2E_OFFLINE=1 skips the tests that query Crossref and Open Library.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../../lib/env.sh"

require_node
require_container
ensure_api_ready

if ! curl -sf -o /dev/null --max-time 30 "$OFR_E2E_URL"; then
	echo "The journal does not answer at $OFR_E2E_URL" >&2
	exit 3
fi

cd "$TESTS_DIR/e2e/api"
node --test --test-timeout=180000 "$@" ./*.test.mjs
