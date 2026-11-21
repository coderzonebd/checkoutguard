#!/usr/bin/env bash
# Usage: svn-tag-status.sh <version>
# Prints the HTTP status of plugins.svn.wordpress.org/checkoutguard/tags/<version>/
set -euo pipefail
curl -s -o /dev/null -w '%{http_code}' --max-time 30 --retry 2 \
  "https://plugins.svn.wordpress.org/checkoutguard/tags/${1}/"
