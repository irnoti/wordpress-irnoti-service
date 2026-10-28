#!/usr/bin/env bash
# Builds irnoti-service/assets/js/jssip.min.js (browser bundle of JsSIP) for the softphone.
set -euo pipefail
VER=3.10.1
OUT="$(cd "$(dirname "$0")/.." && pwd)/irnoti-service/assets/js"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
cd "$TMP"
npm pack "jssip@$VER" >/dev/null && tar xzf "jssip-$VER.tgz"
cd package
npm install --omit=dev --no-audit --no-fund >/dev/null
npm install --no-save --no-audit --no-fund browserify@17 terser@5 >/dev/null
echo "global.JsSIP=require('./lib-es5/JsSIP');" > entry.js
npx browserify entry.js | npx terser -c -m -o "$OUT/jssip.min.js"
cp LICENSE "$OUT/JSSIP-LICENSE.txt"
echo "OK: $OUT/jssip.min.js"
