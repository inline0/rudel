#!/usr/bin/env bash
#
# Run the full Rudel test suite: coding standards, PHPUnit, and E2E.
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUDEL_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
EXIT_CODE=0
BOLD='\033[1m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

cd "$RUDEL_DIR"

echo -e "${BOLD}Rudel: Full Test Suite${NC}"
echo "==========================================="
echo ""

# 1. Coding standards
echo -e "${BOLD}━━━ PHPCS ━━━${NC}"
if composer cs; then
    echo -e "${GREEN}PHPCS passed${NC}"
else
    EXIT_CODE=1
    echo -e "${RED}PHPCS failed${NC}"
fi
echo ""

# 2. PHPUnit
echo -e "${BOLD}━━━ PHPUnit ━━━${NC}"
if composer test; then
    echo -e "${GREEN}PHPUnit passed${NC}"
else
    EXIT_CODE=1
    echo -e "${RED}PHPUnit failed${NC}"
fi
echo ""

# 3. E2E tests
echo -e "${BOLD}━━━ E2E ━━━${NC}"
if bash tests/e2e/run-all.sh; then
    echo -e "${GREEN}E2E passed${NC}"
else
    EXIT_CODE=1
    echo -e "${RED}E2E failed${NC}"
fi
echo ""

# Summary
echo "==========================================="
if [[ $EXIT_CODE -eq 0 ]]; then
    echo -e "${GREEN}${BOLD}All checks passed!${NC}"
else
    echo -e "${RED}${BOLD}Some checks failed${NC}"
fi
exit $EXIT_CODE
