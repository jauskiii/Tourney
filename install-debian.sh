#!/bin/bash
# =====================================================================
#  Tourney – install on a fresh Debian server (no Docker)
#
#  Installs Apache, PHP and MariaDB (Debian's MySQL), creates the
#  database, copies the site to /var/www/tourney and turns it on.
#
#  Usage (from inside the unzipped tourney folder):
#      sudo bash install-debian.sh
#
#  Running it again later UPDATES the site files and keeps your
#  database, settings, logo and accounts.
# =====================================================================
set -euo pipefail

DEST=/var/www/tourney
DB_NAME=tourney
DB_USER=tourney
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Run this with sudo:  sudo bash install-debian.sh"
[ -f "$SRC/_init.php" ] && [ -d "$SRC/app" ] || fail "Run this from inside the unzipped tourney folder (the one with _init.php and app/)."

# Start/restart services on a normal server (systemd) and in containers (no systemd).
svc() {
    local action=$1 name=$2
    if [ -d /run/systemd/system ]; then
        [ "$action" = start ] && systemctl enable --now "$name" || systemctl "$action" "$name"
    else
        service "$name" "$action" || true
    fi
}

# ---------------------------------------------------------------------
say "1/6  Installing Apache, PHP and MariaDB (this takes a minute)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q || echo "Warning: some package sources failed to update – continuing with the ones that work."
apt-get install -y -q apache2 mariadb-server php libapache2-mod-php php-mysql php-mbstring
svc start mariadb
svc start apache2

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || fail "PHP $PHP_VER is too old. Tourney needs PHP 8.1+ (Debian 12 or newer)."
echo "PHP $PHP_VER OK"

# ---------------------------------------------------------------------
say "2/6  Copying the site to $DEST"
mkdir -p "$DEST/public" "$DEST/app"
# Web files go to public/, the code and config to app/ (outside the web root).
tar -C "$SRC" --exclude=./app --exclude=./install-debian.sh --exclude='./INSTALL*' --exclude=./uploads -cf - . | tar -C "$DEST/public" -xf -
tar -C "$SRC/app" --exclude=./config.php -cf - . | tar -C "$DEST/app" -xf -
mkdir -p "$DEST/public/uploads"
cp "$SRC/uploads/.htaccess" "$DEST/public/uploads/.htaccess"

# ---------------------------------------------------------------------
CONFIG="$DEST/app/config.php"
if [ -f "$CONFIG" ]; then
    say "3/6  Database already set up (keeping $CONFIG)"
else
    say "3/6  Creating the database '$DB_NAME' and user '$DB_USER'"
    DB_PASS="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32 || true)"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    cat > "$CONFIG" <<PHP
<?php
// Written by install-debian.sh. Keep this file private.
return [
    'DB_HOST'   => 'localhost',
    'DB_PORT'   => '3306',
    'DB_NAME'   => '$DB_NAME',
    'DB_USER'   => '$DB_USER',
    'DB_PASS'   => '$DB_PASS',
    'MAIL_FROM' => '',
    'TZ'        => 'Europe/Helsinki',
    'BASE_URL'  => '',
];
PHP
fi

# ---------------------------------------------------------------------
say "4/6  Setting file permissions"
chown -R root:root "$DEST"
chown root:www-data "$CONFIG" && chmod 640 "$CONFIG"
# Apache must be able to write uploaded logos and backgrounds.
chown -R www-data:www-data "$DEST/public/uploads"
find "$DEST" -type d -exec chmod 755 {} +
chmod 750 "$DEST/app"; chown root:www-data "$DEST/app"

# ---------------------------------------------------------------------
say "5/6  Configuring Apache"
cat > /etc/apache2/sites-available/tourney.conf <<APACHE
<VirtualHost *:80>
    DocumentRoot $DEST/public
    <Directory $DEST/public>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/tourney-error.log
    CustomLog \${APACHE_LOG_DIR}/tourney-access.log combined
</VirtualHost>
APACHE
a2enmod -q headers >/dev/null
# Quiets Apache's harmless "could not determine the server's name" warning.
echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf
a2enconf -q servername >/dev/null
a2dissite -q 000-default >/dev/null 2>&1 || true
a2ensite -q tourney >/dev/null

# Allow bigger logo/background uploads than PHP's 2 MB default.
for dir in /etc/php/*/apache2/conf.d; do
    [ -d "$dir" ] && printf 'upload_max_filesize = 8M\npost_max_size = 10M\nexpose_php = Off\n' > "$dir/99-tourney.ini"
done
apache2ctl configtest
svc restart apache2

# ---------------------------------------------------------------------
say "6/6  Checking the site answers"
sleep 1
CODE="$(php -r '$c=@file_get_contents("http://127.0.0.1/"); echo isset($http_response_header[0]) ? $http_response_header[0] : "no answer";')"
echo "Site says: $CODE"

IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
cat <<DONE

  ✅  Tourney is installed.

  Open   http://${IP:-SERVER-IP}/   in a browser.
  The first visit asks you to create the admin account.

  Files:     $DEST   (public/ = website, app/ = code + config.php)
  Database:  $DB_NAME  (MariaDB, details in $CONFIG)
  Logs:      /var/log/apache2/tourney-error.log

  Update later: unzip the new version and run this script again.
  Backup:       sudo mysqldump $DB_NAME > tourney-backup.sql

DONE
