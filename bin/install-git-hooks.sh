#!/usr/bin/env bash
# Installs JewelFlow developer git hooks.
# Run: bash bin/install-git-hooks.sh

set -euo pipefail

HOOK_DIR="$(git rev-parse --show-toplevel)/.git/hooks"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

cat > "$HOOK_DIR/pre-commit" << 'HOOK'
#!/usr/bin/env bash
# JewelFlow pre-commit hook

# Warn on migration files touching finalized-record tables
STAGED=$(git diff --cached --name-only | grep "^database/migrations/" || true)
if [[ -n "$STAGED" ]]; then
    if grep -l "DISABLE TRIGGER\|session_replication_role" $STAGED 2>/dev/null | grep -q .; then
        echo "ERROR: Staged migration contains DISABLE TRIGGER or session_replication_role."
        echo "This violates CONSTITUTION.md Article IX.B."
        echo "Redesign the migration to work within trigger constraints."
        exit 1
    fi

    echo "NOTE: You have staged migration files:"
    echo "$STAGED"
    echo "Reminder: read CONSTITUTION.md §4 (Migration Discipline) before committing."
fi
HOOK

chmod +x "$HOOK_DIR/pre-commit"

echo "Git hooks installed successfully."
echo "Pre-commit hook: warns on DISABLE TRIGGER in migrations."
