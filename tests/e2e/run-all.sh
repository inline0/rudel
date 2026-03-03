#!/usr/bin/env bash
#
# Run all E2E tests
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
EXIT_CODE=0
BOLD='\033[1m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${BOLD}Running all Rudel E2E tests${NC}"
echo ""

for test in "$SCRIPT_DIR"/test-*.sh; do
    name=$(basename "$test")
    echo -e "${BOLD}━━━ $name ━━━${NC}"
    if bash "$test"; then
        echo ""
    else
        EXIT_CODE=1
        echo ""
    fi
done

echo "==========================================="
if [[ $EXIT_CODE -eq 0 ]]; then
    echo -e "${GREEN}${BOLD}All E2E test suites passed!${NC}"
else
    echo -e "${RED}${BOLD}Some E2E test suites failed${NC}"
fi
exit $EXIT_CODE
