#!/usr/bin/env bash
#
# Run the Pro Extended smoke tests on a site, from its WordPress root.
#
#   bash run.sh write    <admin user ID> [image_url=<https URL>]
#   bash run.sh readonly <admin user ID> [<component document ID>]
#
# Copy tests/smoke/ somewhere outside the plugin folder first. The write suite
# creates "PE TEST" documents, pages and media and leaves them in place; the
# read-only suite makes only read-only calls and dry runs.
#
# Set WP_PATH to run against a WordPress root other than the current directory.
# Exits non-zero when a check fails or wp pe doctor reports a failure.

set -euo pipefail

mode="${1:-}"
user="${2:-}"

if [[ $# -ge 2 ]]; then
  shift 2
fi

dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

case "$mode" in
  write) suite="$dir/write-suite.php" ;;
  readonly) suite="$dir/readonly-suite.php" ;;
  *)
    echo "usage: bash run.sh write|readonly <admin user ID> [args...]" >&2
    exit 2
    ;;
esac

if [[ ! "$user" =~ ^[0-9]+$ ]]; then
  echo "An administrator's user ID is required." >&2
  exit 2
fi

wp_args=()

if [[ -n "${WP_PATH:-}" ]]; then
  wp_args+=("--path=$WP_PATH")
fi

status=0

echo "== wp pe doctor =="
if ! wp ${wp_args[@]+"${wp_args[@]}"} pe doctor --user="$user"; then
  echo "wp pe doctor reported a failure." >&2
  status=1
fi

log="$(mktemp "${TMPDIR:-/tmp}/pe-smoke-XXXXXX")"
trap 'rm -f "$log"' EXIT

# --use-include: the suites declare strict_types, which eval() rejects.
wp ${wp_args[@]+"${wp_args[@]}"} eval-file --use-include --user="$user" "$suite" "$@" 2>&1 | tee "$log" || true

if ! grep -qx 'PE_SMOKE_RESULT OK' "$log"; then
  status=1
fi

exit "$status"
