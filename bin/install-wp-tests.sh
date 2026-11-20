#!/usr/bin/env bash
# Downloads WordPress core and the official SQLite Database Integration drop-in
# into .wp-tests/ so the WordPress PHPUnit suite can run without MySQL.
set -euo pipefail

WP_VERSION="${WP_VERSION:-7.1.3}"
SQLITE_VERSION="${SQLITE_VERSION:-3.1.3}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/.wp-tests"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$DEST"

if [ ! -f "$DEST/wordpress/wp-includes/version.php" ]; then
	echo "Downloading WordPress $WP_VERSION..."
	curl -fsSL "https://wordpress.org/wordpress-$WP_VERSION.zip" -o "$TMP/wp.zip"
	unzip -q "$TMP/wp.zip" -d "$TMP"
	rm -rf "$DEST/wordpress"
	mv "$TMP/wordpress" "$DEST/wordpress"
fi

PLUGIN_DIR="$DEST/wordpress/wp-content/plugins/sqlite-database-integration"
if [ ! -d "$PLUGIN_DIR" ]; then
	echo "Downloading SQLite Database Integration $SQLITE_VERSION..."
	curl -fsSL "https://downloads.wordpress.org/plugin/sqlite-database-integration.$SQLITE_VERSION.zip" -o "$TMP/sqlite.zip"
	unzip -q "$TMP/sqlite.zip" -d "$DEST/wordpress/wp-content/plugins"
fi

# Install the db.php drop-in.
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PLUGIN_DIR#" \
	-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	"$PLUGIN_DIR/db.copy" > "$DEST/wordpress/wp-content/db.php"

mkdir -p "$DEST/database"
echo "WordPress test environment ready in $DEST"
