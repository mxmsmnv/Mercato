#!/bin/sh
set -eu

: "${MERCATO_PLAYWRIGHT_FIREFOX_BINARY:?Missing Playwright Firefox binary path}"
: "${MERCATO_PLAYWRIGHT_FIREFOX_APP_INI:?Missing branded Firefox application.ini path}"

exec "$MERCATO_PLAYWRIGHT_FIREFOX_BINARY" -app "$MERCATO_PLAYWRIGHT_FIREFOX_APP_INI" "$@"
