#!/usr/bin/env bash
# Build ghotbnama.zip (installable from WordPress › Plugins › Add New › Upload).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -oP "QTB_VERSION\s*=\s*'\K[^']+" ghotbnama/ghotbnama.php)
OUT="ghotbnama-${VERSION}.zip"
rm -f "$OUT"
zip -rq "$OUT" ghotbnama -x '*.DS_Store' '*/.git*'
echo "Built $OUT"
