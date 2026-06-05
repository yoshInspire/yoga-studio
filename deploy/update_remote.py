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

if ! dpkg -l php8.3-intl 2>/dev/null | grep -q '^ii'; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y php8.3-intl
fi

export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction

grep -q '^ADMIN_EMAIL=' .env || echo 'ADMIN_EMAIL=admin@ekoyoga-ik.ru' >> .env
grep -q '^ADMIN_PHONE=' .env || echo 'ADMIN_PHONE=+79000000000' >> .env
grep -q '^ADMIN_PASSWORD=' .env || echo 'ADMIN_PASSWORD=StudioAdmin2026!' >> .env
grep -q '^TRAINER_EMAIL=' .env || echo 'TRAINER_EMAIL=trainer@ekoyoga-ik.ru' >> .env
grep -q '^TRAINER_PHONE=' .env || echo 'TRAINER_PHONE=+79000000001' >> .env
grep -q '^TRAINER_PASSWORD=' .env || echo 'TRAINER_PASSWORD=StudioTrainer2026!' >> .env

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
