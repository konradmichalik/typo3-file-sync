#!/usr/bin/env bash
set -eu

# Publishes the fake remote instance's files.
#
# The vhost remote.typo3-file-sync.ddev.site serves /var/www/html/.Build, so
# the fixtures have to sit there rather than under Tests/. Without this the
# remote answers 404 for every file and the placeholder handler takes over,
# which looks like a broken sync rather than a missing fixture.

FIXTURES="/var/www/html/Tests/Acceptance/Fixtures/remote-files"
REMOTE_ROOT="/var/www/html/.Build"

[ -d "$FIXTURES" ] || exit 0

mkdir -p "$REMOTE_ROOT"
cp -r "$FIXTURES"/. "$REMOTE_ROOT"/

echo "Published remote fixtures to $REMOTE_ROOT"
