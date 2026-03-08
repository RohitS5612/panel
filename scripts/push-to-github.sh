#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   ./scripts/push-to-github.sh https://github.com/<user>/<repo>.git [branch]

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: $0 <github-repo-url> [branch]" >&2
  exit 1
fi

REPO_URL="$1"
BRANCH="${2:-$(git branch --show-current)}"

if [[ -z "$BRANCH" ]]; then
  echo "Could not determine current branch. Pass it as the second argument." >&2
  exit 1
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "This script must be run from inside a git repository." >&2
  exit 1
fi

if git remote get-url origin >/dev/null 2>&1; then
  CURRENT_URL="$(git remote get-url origin)"
  if [[ "$CURRENT_URL" != "$REPO_URL" ]]; then
    echo "Remote 'origin' already exists and points to: $CURRENT_URL" >&2
    echo "Requested URL: $REPO_URL" >&2
    echo "Either remove/update origin manually or rerun with the existing URL." >&2
    exit 1
  fi
else
  git remote add origin "$REPO_URL"
fi

git push -u origin "$BRANCH"

echo
echo "Pushed successfully. Next step: open GitHub and create a PR from branch '$BRANCH'."
