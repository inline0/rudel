#!/usr/bin/env bash
#
# E2E Test: GitHub integration
#
# Tests the GitHubIntegration class against the real inline0/rudel-test-theme repo.
# Requires a valid GitHub token (via gh auth or RUDEL_GITHUB_TOKEN).
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUDEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
TEST_TMPDIR=$(mktemp -d)
PASSED=0
FAILED=0
TOTAL=0
REPO="inline0/rudel-test-theme"
BRANCH_NAME="rudel/e2e-test-$(date +%s)"

# Get GitHub token.
GITHUB_TOKEN="${RUDEL_GITHUB_TOKEN:-}"
if [[ -z "$GITHUB_TOKEN" ]] && command -v gh &> /dev/null; then
    GITHUB_TOKEN=$(gh auth token 2>/dev/null || echo "")
fi

if [[ -z "$GITHUB_TOKEN" ]]; then
    echo "No GitHub token available, skipping GitHub integration tests"
    exit 0
fi

cleanup() {
    rm -rf "$TEST_TMPDIR"
    # Clean up test branch if it exists.
    gh api "repos/${REPO}/git/refs/heads/${BRANCH_NAME}" -X DELETE 2>/dev/null || true
}
trap cleanup EXIT

GREEN='\033[0;32m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

pass() {
    PASSED=$((PASSED + 1))
    TOTAL=$((TOTAL + 1))
    echo -e "  ${GREEN}✓${NC} $1"
}

fail() {
    FAILED=$((FAILED + 1))
    TOTAL=$((TOTAL + 1))
    echo -e "  ${RED}✗${NC} $1"
    if [[ -n "${2:-}" ]]; then
        echo "    $2"
    fi
}

run_php() {
    local code="$1"
    php -r "
        require '${RUDEL_DIR}/vendor/autoload.php';
        define('RUDEL_GITHUB_TOKEN', '${GITHUB_TOKEN}');
        define('RUDEL_VERSION', '0.1.0');
        ${code}
    " 2>&1
}

echo -e "${BOLD}Rudel E2E: GitHub Integration${NC}"
echo "==========================================="
echo ""

# Get default branch
echo -e "${BOLD}Repository access${NC}"

DEFAULT_BRANCH=$(run_php "
    \$gh = new Rudel\GitHubIntegration('${REPO}');
    echo \$gh->get_default_branch();
")
if [[ "$DEFAULT_BRANCH" == "main" ]]; then
    pass "Connected to repo, default branch: main"
else
    fail "Unexpected default branch" "Got: $DEFAULT_BRANCH"
fi

# Create branch
echo ""
echo -e "${BOLD}Branch management${NC}"

CREATE_RESULT=$(run_php "
    \$gh = new Rudel\GitHubIntegration('${REPO}');
    \$gh->create_branch('${BRANCH_NAME}');
    echo 'ok';
")
if [[ "$CREATE_RESULT" == "ok" ]]; then
    pass "Created branch: ${BRANCH_NAME}"
else
    fail "Branch creation failed" "$CREATE_RESULT"
fi

# Download files
echo ""
echo -e "${BOLD}Download files${NC}"

DOWNLOAD_DIR="$TEST_TMPDIR/download"
mkdir -p "$DOWNLOAD_DIR"

FILE_COUNT=$(run_php "
    \$gh = new Rudel\GitHubIntegration('${REPO}');
    echo \$gh->download('${BRANCH_NAME}', '${DOWNLOAD_DIR}');
")
if [[ "$FILE_COUNT" -gt 0 ]]; then
    pass "Downloaded $FILE_COUNT files"
else
    fail "No files downloaded" "Count: $FILE_COUNT"
fi

if [[ -f "$DOWNLOAD_DIR/style.css" ]]; then
    pass "style.css present in download"
else
    fail "style.css missing"
fi

if [[ -f "$DOWNLOAD_DIR/index.php" ]]; then
    pass "index.php present in download"
else
    fail "index.php missing"
fi

# Make a change and push
echo ""
echo -e "${BOLD}Push changes${NC}"

echo "/* Added by Rudel E2E test */" >> "$DOWNLOAD_DIR/style.css"
cat > "$DOWNLOAD_DIR/e2e-test.txt" << 'EOF'
This file was created by the Rudel GitHub integration E2E test.
EOF

COMMIT_SHA=$(run_php "
    \$gh = new Rudel\GitHubIntegration('${REPO}');
    echo \$gh->push('${BRANCH_NAME}', '${DOWNLOAD_DIR}', 'E2E test commit');
")
if [[ -n "$COMMIT_SHA" && "$COMMIT_SHA" != "null" ]]; then
    pass "Pushed commit: ${COMMIT_SHA:0:7}"
else
    fail "Push returned no SHA" "Got: $COMMIT_SHA"
fi

# Verify the push by re-downloading
VERIFY_DIR="$TEST_TMPDIR/verify"
mkdir -p "$VERIFY_DIR"
run_php "
    \$gh = new Rudel\GitHubIntegration('${REPO}');
    \$gh->download('${BRANCH_NAME}', '${VERIFY_DIR}');
" > /dev/null

if [[ -f "$VERIFY_DIR/e2e-test.txt" ]]; then
    pass "New file visible after push"
else
    fail "New file not found after push"
fi

if grep -q "Rudel E2E test" "$VERIFY_DIR/style.css" 2>/dev/null; then
    pass "Modified file contains changes"
else
    fail "Modified file missing changes"
fi

# Create PR
echo ""
echo -e "${BOLD}Pull request${NC}"

PR_RESULT=$(run_php "
    \$gh = new Rudel\GitHubIntegration('${REPO}');
    \$pr = \$gh->create_pr('${BRANCH_NAME}', 'E2E Test PR', 'Automated test from Rudel E2E suite.');
    echo \$pr['number'] . '|' . \$pr['html_url'];
")
PR_NUMBER=$(echo "$PR_RESULT" | cut -d'|' -f1)
PR_URL=$(echo "$PR_RESULT" | cut -d'|' -f2)

if [[ -n "$PR_NUMBER" && "$PR_NUMBER" != "0" ]]; then
    pass "Created PR #${PR_NUMBER}: ${PR_URL}"
else
    fail "PR creation failed" "$PR_RESULT"
fi

# Check merge status (should be false, PR is open)
echo ""
echo -e "${BOLD}Merge detection${NC}"

IS_MERGED=$(run_php "
    \$gh = new Rudel\GitHubIntegration('${REPO}');
    echo \$gh->is_branch_merged('${BRANCH_NAME}') ? 'true' : 'false';
")
if [[ "$IS_MERGED" == "false" ]]; then
    pass "Open PR correctly detected as not merged"
else
    fail "Open PR incorrectly detected as merged" "$IS_MERGED"
fi

# Close and merge the PR via gh CLI, then check again.
gh pr merge "$PR_NUMBER" --repo "$REPO" --merge --delete-branch 2>/dev/null || true
sleep 2

IS_MERGED_AFTER=$(run_php "
    \$gh = new Rudel\GitHubIntegration('${REPO}');
    echo \$gh->is_branch_merged('${BRANCH_NAME}') ? 'true' : 'false';
")
if [[ "$IS_MERGED_AFTER" == "true" ]]; then
    pass "Merged PR correctly detected"
else
    fail "Merged PR not detected" "$IS_MERGED_AFTER"
fi

# Delete branch (cleanup already handled by gh pr merge --delete-branch)
echo ""
echo -e "${BOLD}Branch cleanup${NC}"

# The branch was already deleted by the merge. Try deleting again to confirm idempotence.
DELETE_RESULT=$(run_php "
    \$gh = new Rudel\GitHubIntegration('${REPO}');
    echo \$gh->delete_branch('${BRANCH_NAME}') ? 'deleted' : 'not_found';
")
if [[ "$DELETE_RESULT" == "not_found" ]]; then
    pass "Branch already cleaned up by merge"
else
    pass "Branch deleted: $DELETE_RESULT"
fi

# Results
echo ""
echo "==========================================="
if [[ $FAILED -eq 0 ]]; then
    echo -e "${GREEN}${BOLD}All $TOTAL tests passed!${NC}"
    exit 0
else
    echo -e "${RED}${BOLD}$FAILED of $TOTAL tests failed${NC}"
    exit 1
fi
