#!/bin/bash
# Builds a Dolibarr-installable release ZIP for a given git tag.
#
# GitHub names the extracted folder "dolibarr-einvoice-<version>", but
# Dolibarr's module installer expects the module's runtime name "einvoice".
# This script packs the module under "einvoice/" so the installer accepts it.
#
# Usage:   ./build/build-release.sh v0.1.0
# Upload:  gh release upload <tag> einvoice-<version>.zip -R Gerrett84/dolibarr-einvoice

set -euo pipefail

TAG="${1:?Usage: $0 <git-tag>}"
MODULE=einvoice
VERSION="${TAG#v}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_FILE="$(pwd)/$MODULE-$VERSION.zip"

rm -f "$OUT_FILE"
git -C "$REPO_ROOT" archive --format=zip --prefix="$MODULE/" -o "$OUT_FILE" "$TAG"
echo "Done: $OUT_FILE"
