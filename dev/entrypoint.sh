#!/bin/bash
# Container start: point OJS at the published URL, start MariaDB, install and enable
# the mounted objectsForReview plugin (first start only), then run Apache.
set -euo pipefail

cd /var/www/html

OJS_HOST="${OJS_BASE_URL#*://}"
OJS_HOST="${OJS_HOST%%/*}"
OJS_HOST="${OJS_HOST%%:*}"
sed -i \
	-e "s|^base_url = .*|base_url = \"${OJS_BASE_URL}\"|" \
	-e "s|^allowed_hosts = .*|allowed_hosts = '[\"${OJS_HOST}\"]'|" \
	config.inc.php

service mariadb start
until mysqladmin ping --silent; do sleep 1; done

PLUGIN_DIR=plugins/generic/objectsForReview
MARKER=/var/www/.objectsForReview-installed
if [ ! -f "$PLUGIN_DIR/version.xml" ]; then
	echo "WARNING: objectsForReview is not mounted at /var/www/html/$PLUGIN_DIR" >&2
elif [ ! -f "$MARKER" ]; then
	echo "Installing objectsForReview plugin..."
	runuser -u www-data -- php lib/pkp/tools/installPluginVersion.php "$PLUGIN_DIR/version.xml"
	runuser -u www-data -- php tools/devSetup.php enable-plugin
	touch "$MARKER"
fi

echo "OJS is available at ${OJS_BASE_URL}"
exec apache2-foreground
