#!/usr/bin/env bash
# Build manzoomeh.zip (installable from WordPress › Plugins › Add New › Upload).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -oP "MZM_VERSION\s*=\s*'\K[^']+" manzoomeh/manzoomeh.php)
OUT="manzoomeh-${VERSION}.zip"
rm -f "$OUT"
zip -rq "$OUT" manzoomeh -x '*.DS_Store' '*/.git*'
echo "Built $OUT"
