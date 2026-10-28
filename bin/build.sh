#!/usr/bin/env bash
# Builds the wordpress.org release copy in build/update-airlock and build/update-airlock.zip,
# leaving out the top-level entries listed in .distignore.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build/update-airlock build/update-airlock.zip
mkdir -p build/update-airlock
shopt -s dotglob
for entry in *; do
	if ! grep -qxF "/$entry" .distignore; then
		cp -r "$entry" build/update-airlock/
	fi
done
(cd build && zip -qr update-airlock.zip update-airlock)
echo "Built build/update-airlock ($(find build/update-airlock -type f | wc -l) files) and build/update-airlock.zip"
