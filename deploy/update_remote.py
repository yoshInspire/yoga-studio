#!/usr/bin/env python3
"""Pull latest code on VPS and run migrations. python deploy/update_remote.py"""

import sys

import paramiko

HOST = "77.91.93.110"
USER = "root"
PASSWORD = "R9%KS6zbau"
APP_DIR = "/var/www/yoga-studio"


def main() -> int:
    script = f"""set -e
cd {APP_DIR}
git config --global --add safe.directory {APP_DIR}
git pull origin main

if ! dpkg -l php8.3-intl 2>/dev/null | grep -q '^ii' || ! dpkg -l php8.3-gd 2>/dev/null | grep -q '^ii'; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y php8.3-intl php8.3-gd php8.3-zip
fi

export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction

set_env() {{
  key="$1"
  val="$2"
  if grep -q "^${{key}}=" .env; then
    sed -i "s|^${{key}}=.*|${{key}}=${{val}}|" .env
  else
    echo "${{key}}=${{val}}" >> .env
  fi
}}

set_env ADMIN_EMAIL admin@ekoyoga-ik.ru
set_env ADMIN_PHONE +79000000000
set_env ADMIN_PASSWORD StudioAdmin2026!
set_env TRAINER_EMAIL trainer@ekoyoga-ik.ru
set_env TRAINER_PHONE +79000000001
set_env TRAINER_PASSWORD StudioTrainer2026!
set_env TELEGRAM_BOT_TOKEN 8607223838:AAETw2YxBHJB5LIBQCjX1Y-cP30QVXsiTSQ
set_env TELEGRAM_BOT_USERNAME ekoyogabot
set_env TELEGRAM_AUTH_MAX_AGE 86400

php artisan migrate --force
php artisan db:seed --class=AdminUserSeeder --force
php artisan db:seed --class=TrainerUserSeeder --force

php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data {APP_DIR}/storage {APP_DIR}/bootstrap/cache
chmod -R 775 {APP_DIR}/storage {APP_DIR}/bootstrap/cache
systemctl restart php8.3-fpm

curl -sI https://ekoyoga-ik.ru/login | head -3
curl -sI https://ekoyoga-ik.ru/admin | head -3
echo DEPLOY_OK
"""

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting to {HOST}...")
    client.connect(HOST, username=USER, password=PASSWORD, timeout=30, banner_timeout=30)

    try:
        print("Running deploy...")
        _, stdout, stderr = client.exec_command(script, timeout=900, get_pty=True)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        code = stdout.channel.recv_exit_status()

        sys.stdout.buffer.write(out.encode("utf-8", errors="replace"))
        if err.strip():
            sys.stdout.buffer.write(b"\nSTDERR:\n")
            sys.stdout.buffer.write(err.encode("utf-8", errors="replace"))

        print(f"\nEXIT CODE: {code}")
        return code
    finally:
        client.close()


if __name__ == "__main__":
    raise SystemExit(main())
