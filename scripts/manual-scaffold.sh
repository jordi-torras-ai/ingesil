#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 2 ]]; then
  echo "Usage: bash scripts/manual-scaffold.sh <slug> <title>"
  exit 1
fi

slug="$1"
shift
title="$*"

manual_dir="docs/manuals"
template_path="$manual_dir/_template.md"
manual_path="$manual_dir/$slug.md"
screenshots_dir="output/playwright/$slug"

mkdir -p "$manual_dir"
mkdir -p "$screenshots_dir"

if [[ ! -f "$template_path" ]]; then
  echo "Template not found: $template_path"
  exit 1
fi

if [[ -f "$manual_path" ]]; then
  echo "Manual already exists: $manual_path"
  exit 1
fi

sed \
  -e "s/{{TITLE}}/$title/g" \
  -e "s/{{SLUG}}/$slug/g" \
  "$template_path" > "$manual_path"

cat <<EOF
Created:
  - $manual_path
  - $screenshots_dir

Next:
  export CODEX_HOME="\${CODEX_HOME:-\$HOME/.codex}"
  export PWCLI="\$CODEX_HOME/skills/playwright/scripts/playwright_cli.sh"
  "\$PWCLI" open http://127.0.0.1:8000/admin --headed
  "\$PWCLI" snapshot
  "\$PWCLI" screenshot $screenshots_dir/01-step.png
EOF
