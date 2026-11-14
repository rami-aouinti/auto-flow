#!/usr/bin/env sh
set -eu

check() {
  name="$1"
  port="$2"
  for path in /health/live /health/ready; do
    printf '%-18s %-14s ' "$name" "$path"
    curl --fail --silent --show-error "http://127.0.0.1:${port}${path}"
    printf '\n'
  done
}

check search 8097
check workflow 8098
check integration 8099
check document 8100
check audit 8101
check analytics 8102
check media 8103
check configuration 8104
