#!/bin/bash
# Unit tests of the Node client and CLI (api/test, offline: mocked fetch and a fake API server).
# The tests live with the client so that a copy of api/ keeps them.
# Arguments are passed to node --test, e.g. --test-name-pattern=import
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../../lib/env.sh"

require_node
cd "$PLUGIN_DIR/api"
node --test "$@" test/*.test.js
