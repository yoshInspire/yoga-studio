import paramiko

from credentials import ssh_credentials

host, user, password = ssh_credentials()

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(host, username=user, password=password, timeout=30)
cmds = [
    "cd /var/www/yoga-studio && sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=file/' .env",
    "cd /var/www/yoga-studio && php artisan migrate --force",
    "cd /var/www/yoga-studio && php artisan config:cache",
    "curl -sI -H 'Host: ekoyoga-ik.ru' http://127.0.0.1/ | head -6",
    f"curl -sI http://{host}/ | head -6",
]
for cmd in cmds:
    print(">>>", cmd)
    _, o, e = c.exec_command(cmd, timeout=120, get_pty=True)
    print(o.read().decode())
    code = o.channel.recv_exit_status()
    if code != 0:
        print("FAILED", e.read().decode())
c.close()
