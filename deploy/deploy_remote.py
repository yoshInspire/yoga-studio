#!/usr/bin/env python3
"""One-time VPS deploy for yoga-studio. Run locally: python deploy/deploy_remote.py"""

import os
import secrets
import sys

import paramiko

from credentials import ssh_credentials
DOMAIN = "ekoyoga-ik.ru"
REPO = "https://github.com/yoshInspire/yoga-studio.git"
APP_DIR = "/var/www/yoga-studio"
DB_NAME = "yoga_studio"
DB_USER = "yoga"
DB_PASS = "Yg_" + secrets.token_urlsafe(16)


def run(client: paramiko.SSHClient, cmd: str, timeout: int = 600) -> tuple[int, str, str]:
    print(f"\n>>> {cmd[:120]}{'...' if len(cmd) > 120 else ''}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout, get_pty=True)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    if out.strip():
        print(out[-4000:] if len(out) > 4000 else out)
    if err.strip() and code != 0:
        print("STDERR:", err[-2000:])
    if code != 0:
        raise RuntimeError(f"Command failed ({code}): {cmd[:80]}")
    return code, out, err


def main() -> None:
    script = f"""set -e
export DEBIAN_FRONTEND=noninteractive

if ! command -v nginx >/dev/null 2>&1; then
  apt-get update -y
  apt-get upgrade -y
  apt-get install -y nginx mysql-server git unzip curl ca-certificates gnupg2 lsb-release certbot python3-certbot-nginx
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
  apt-get install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  systemctl enable nginx php8.3-fpm mysql
  systemctl start nginx php8.3-fpm mysql
fi

mysql -e "CREATE DATABASE IF NOT EXISTS {DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '{DB_USER}'@'localhost' IDENTIFIED BY '{DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON {DB_NAME}.* TO '{DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

mkdir -p /var/www
if [ ! -d {APP_DIR}/.git ]; then
  git clone {REPO} {APP_DIR}
fi
cd {APP_DIR}
git fetch origin
git reset --hard origin/main

export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
  cp .env.example .env
fi

php artisan key:generate --force

grep -q '^APP_ENV=' .env && sed -i 's/^APP_ENV=.*/APP_ENV=production/' .env || echo 'APP_ENV=production' >> .env
grep -q '^APP_DEBUG=' .env && sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env || echo 'APP_DEBUG=false' >> .env
grep -q '^APP_URL=' .env && sed -i 's|^APP_URL=.*|APP_URL=https://{DOMAIN}|' .env || echo 'APP_URL=https://{DOMAIN}' >> .env
grep -q '^APP_TIMEZONE=' .env && sed -i 's/^APP_TIMEZONE=.*/APP_TIMEZONE=Europe\/Moscow/' .env || echo 'APP_TIMEZONE=Europe/Moscow' >> .env
grep -q '^DB_CONNECTION=' .env && sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' .env || echo 'DB_CONNECTION=mysql' >> .env
grep -q '^DB_HOST=' .env && sed -i 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' .env || echo 'DB_HOST=127.0.0.1' >> .env
grep -q '^DB_DATABASE=' .env && sed -i 's/^DB_DATABASE=.*/DB_DATABASE={DB_NAME}/' .env || echo 'DB_DATABASE={DB_NAME}' >> .env
grep -q '^DB_USERNAME=' .env && sed -i 's/^DB_USERNAME=.*/DB_USERNAME={DB_USER}/' .env || echo 'DB_USERNAME={DB_USER}' >> .env
grep -q '^DB_PASSWORD=' .env && sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD={DB_PASS}/' .env || echo 'DB_PASSWORD={DB_PASS}' >> .env

php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data {APP_DIR}
chmod -R 775 {APP_DIR}/storage {APP_DIR}/bootstrap/cache

cat > /etc/nginx/sites-available/{DOMAIN} <<'NGINX'
server {{
    listen 80;
    listen [::]:80;
    server_name {DOMAIN} www.{DOMAIN};
    root {APP_DIR}/public;

    index index.php;
    charset utf-8;

    location / {{
        try_files $uri $uri/ /index.php?$query_string;
    }}

    location ~ \\.php$ {{
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }}

    location ~ /\\.(?!well-known).* {{
        deny all;
    }}
}}
NGINX

ln -sf /etc/nginx/sites-available/{DOMAIN} /etc/nginx/sites-enabled/{DOMAIN}
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

certbot --nginx -d {DOMAIN} -d www.{DOMAIN} --non-interactive --agree-tos --register-unsafely-without-email --redirect || true

(crontab -l 2>/dev/null | grep -v 'artisan schedule:run'; echo '* * * * * cd {APP_DIR} && php artisan schedule:run >> /dev/null 2>&1') | crontab -

echo DEPLOY_OK
"""

    host, user, password = ssh_credentials()

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting to {host}...")
    client.connect(host, username=user, password=password, timeout=30, banner_timeout=30)

    try:
        run(client, script, timeout=1800)
        _, out, _ = run(client, f"curl -sI https://{DOMAIN} | head -5 || curl -sI http://{DOMAIN} | head -5", timeout=60)
    finally:
        client.close()

    cred_path = os.path.join(os.path.dirname(__file__), "SERVER.local.txt")
    with open(cred_path, "a", encoding="utf-8") as f:
        f.write(f"\nDB_PASSWORD (deploy): {DB_PASS}\n")

    print("\n=== Deploy finished ===")
    print(f"Site: https://{DOMAIN}")
    print(f"DB password saved to deploy/SERVER.local.txt")


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)
