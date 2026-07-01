#!/usr/bin/env python3
"""Import clients and subscriptions from legacy spreadsheet."""

from __future__ import annotations

import json
import re
from datetime import date, timedelta

import paramiko

from credentials import ssh_credentials

APP_DIR = "/var/www/yoga-studio"
VALIDITY_DAYS = 30
IMPORT_NOTE = "Импорт из старой системы (14.06.2026)"

# Unique clients keyed by normalized phone (11 digits starting with 7).
CLIENTS = [
    {"phone": "89164397663", "last_name": "Фадеева", "first_name": "Анастасия"},
    {"phone": "89630967750", "last_name": "Бабенко", "first_name": "Ольга"},
    {"phone": "89104394161", "last_name": "Клиент", "first_name": "Алексей"},
    {"phone": "89636842780", "last_name": "Чудинова", "first_name": "Анна"},
    {"phone": "79513158483", "last_name": "Жигулина", "first_name": "Анна"},
    {"phone": "79253551379", "last_name": "Самохина", "first_name": "Екатерина"},
    {"phone": "79166809781", "last_name": "Лебедева", "first_name": "Марина"},
    {"phone": "79085020242", "last_name": "Шишка", "first_name": "Никита"},
    {"phone": "79652118487", "last_name": "Шинкарюк", "first_name": "Наталья"},
    {"phone": "79035680504", "last_name": "Авагимова", "first_name": "Ольга"},
    {"phone": "79685285189", "last_name": "Зоря", "first_name": "Ольга"},
    {"phone": "79160422011", "last_name": "Вьюгина", "first_name": "Наталья"},
    {"phone": "79168740421", "last_name": "Шатровская", "first_name": "Ирина"},
    {"phone": "79680478794", "last_name": "Кузель", "first_name": "Анастасия"},
    {"phone": "79854473255", "last_name": "Станкевич", "first_name": "Анна"},
    {"phone": "79165399848", "last_name": "Жукова", "first_name": "Дарья"},
    {"phone": "79850576805", "last_name": "Бодрова", "first_name": "Анастасия"},
    {"phone": "79263871488", "last_name": "Уржумова", "first_name": "Ангелина"},
    {"phone": "79104983703", "last_name": "Абаза", "first_name": "Анна"},
    {"phone": "79104212858", "last_name": "Гусарова", "first_name": "Татьяна"},
    {"phone": "79175603520", "last_name": "Титова", "first_name": "Виктория"},
    {"phone": "79151662019", "last_name": "Яковлева", "first_name": "Анастасия"},
    {"phone": "79037314451", "last_name": "П.", "first_name": "Юлия"},
    {"phone": "79241696610", "last_name": "Клиент", "first_name": "Алёна"},
    {"phone": "79031898884", "last_name": "Кроменскова", "first_name": "Ольга"},
    {"phone": "79262712274", "last_name": "Горяйнова", "first_name": "Светлана"},
    {"phone": "79153396350", "last_name": "Пикина", "first_name": "Мария"},
    {"phone": "79169293406", "last_name": "Лобанова", "first_name": "Ирина"},
]

SUBSCRIPTIONS = [
    {
        "phone": "89164397663",
        "type": "group",
        "purchased_at": "2026-05-24",
        "starts_at": "2026-05-25",
        "sessions_total": 8,
        "sessions_used": 4,
        "visit_dates": "04.05, 10.05, 10.06, 11.06",
    },
    {
        "phone": "89164397663",
        "type": "individual",
        "purchased_at": "2026-06-10",
        "starts_at": "2026-06-15",
        "sessions_total": 1,
        "sessions_used": 0,
        "visit_dates": "",
    },
    {
        "phone": "89630967750",
        "type": "group",
        "purchased_at": "2026-05-14",
        "starts_at": "2026-05-18",
        "ends_at": "2026-06-17",
        "sessions_total": 6,
        "sessions_used": 5,
        "visit_dates": "22.05, 28.05, 30.05, 02.06, 04.06",
    },
    {
        "phone": "89104394161",
        "type": "group",
        "purchased_at": "2026-05-24",
        "starts_at": "2026-05-25",
        "sessions_total": 6,
        "sessions_used": 5,
        "visit_dates": "26.05, 30.05, 01.06, 05.06, 08.06",
    },
    {
        "phone": "89636842780",
        "type": "group",
        "purchased_at": "2026-05-18",
        "starts_at": "2026-05-22",
        "sessions_total": 4,
        "sessions_used": 3,
        "visit_dates": "23.05, 28.05, 06.06",
    },
    {
        "phone": "79513158483",
        "type": "group",
        "purchased_at": "2026-06-14",
        "starts_at": "2026-06-15",
        "sessions_total": 8,
        "sessions_used": 0,
        "visit_dates": "",
    },
    {
        "phone": "79253551379",
        "type": "group",
        "purchased_at": "2026-05-18",
        "starts_at": "2026-05-20",
        "sessions_total": 4,
        "sessions_used": 1,
        "visit_dates": "10.06",
    },
    {
        "phone": "79166809781",
        "type": "group",
        "purchased_at": "2026-05-23",
        "starts_at": "2026-05-23",
        "sessions_total": 6,
        "sessions_used": 5,
        "visit_dates": "25.05, 29.05, 05.06, 08.06, 10.06",
    },
    {
        "phone": "79085020242",
        "type": "individual",
        "purchased_at": "2026-05-26",
        "starts_at": "2026-05-29",
        "sessions_total": 4,
        "sessions_used": 3,
        "visit_dates": "29.05, 06.06, 12.06",
    },
    {
        "phone": "79652118487",
        "type": "group",
        "purchased_at": "2026-05-24",
        "starts_at": "2026-05-25",
        "sessions_total": 6,
        "sessions_used": 4,
        "visit_dates": "25.05, 01.06, 10.06, 12.06",
    },
    {
        "phone": "79035680504",
        "type": "group",
        "purchased_at": "2026-06-06",
        "starts_at": "2026-06-09",
        "sessions_total": 6,
        "sessions_used": 0,
        "visit_dates": "",
    },
    {
        "phone": "79685285189",
        "type": "group",
        "purchased_at": "2026-05-25",
        "starts_at": "2026-05-27",
        "sessions_total": 6,
        "sessions_used": 4,
        "visit_dates": "27.05, 31.05, 02.06, 06.06",
    },
    {
        "phone": "79160422011",
        "type": "group",
        "purchased_at": "2026-05-25",
        "starts_at": "2026-05-26",
        "sessions_total": 6,
        "sessions_used": 4,
        "visit_dates": "26.05, 28.05, 09.06, 11.06",
    },
    {
        "phone": "79168740421",
        "type": "group",
        "purchased_at": "2026-05-25",
        "starts_at": "2026-05-25",
        "sessions_total": 8,
        "sessions_used": 5,
        "visit_dates": "30.05, 01.06, 04.06, 05.06, 09.06",
    },
    {
        "phone": "79680478794",
        "type": "group",
        "purchased_at": "2026-05-26",
        "starts_at": "2026-05-27",
        "sessions_total": 6,
        "sessions_used": 2,
        "visit_dates": "28.05, 04.06",
    },
    {
        "phone": "79854473255",
        "type": "group",
        "purchased_at": "2026-05-27",
        "starts_at": "2026-05-30",
        "sessions_total": 4,
        "sessions_used": 1,
        "visit_dates": "30.05",
    },
    {
        "phone": "79165399848",
        "type": "group",
        "purchased_at": "2026-05-27",
        "starts_at": "2026-06-01",
        "sessions_total": 6,
        "sessions_used": 5,
        "visit_dates": "01.06, 06.06, 06.06, 08.06, 09.06",
    },
    {
        "phone": "79850576805",
        "type": "group",
        "purchased_at": "2026-05-29",
        "starts_at": "2026-05-31",
        "sessions_total": 6,
        "sessions_used": 2,
        "visit_dates": "05.06, 07.06",
    },
    {
        "phone": "79263871488",
        "type": "group",
        "purchased_at": "2026-05-31",
        "starts_at": "2026-06-01",
        "sessions_total": 8,
        "sessions_used": 4,
        "visit_dates": "01.06, 02.06, 09.06, 11.06",
    },
    {
        "phone": "79104983703",
        "type": "group",
        "purchased_at": "2026-05-31",
        "starts_at": "2026-06-05",
        "sessions_total": 6,
        "sessions_used": 3,
        "visit_dates": "05.06, 09.06, 12.06",
    },
    {
        "phone": "79104212858",
        "type": "group",
        "purchased_at": "2026-05-31",
        "starts_at": "2026-06-03",
        "sessions_total": 8,
        "sessions_used": 2,
        "visit_dates": "04.06, 10.06",
    },
    {
        "phone": "79175603520",
        "type": "group",
        "purchased_at": "2026-06-04",
        "starts_at": "2026-06-05",
        "sessions_total": 4,
        "sessions_used": 3,
        "visit_dates": "05.06, 10.06, 12.06",
    },
    {
        "phone": "79151662019",
        "type": "group",
        "purchased_at": "2026-06-05",
        "starts_at": "2026-06-10",
        "sessions_total": 6,
        "sessions_used": 2,
        "visit_dates": "11.06, 12.06",
    },
    {
        "phone": "79037314451",
        "type": "group",
        "purchased_at": "2026-06-03",
        "starts_at": "2026-06-05",
        "sessions_total": 6,
        "sessions_used": 3,
        "visit_dates": "05.06, 08.06, 09.06",
    },
    {
        "phone": "79241696610",
        "type": "group",
        "purchased_at": "2026-06-07",
        "starts_at": "2026-06-09",
        "sessions_total": 4,
        "sessions_used": 1,
        "visit_dates": "09.06",
    },
    {
        "phone": "79031898884",
        "type": "group",
        "purchased_at": "2026-06-07",
        "starts_at": "2026-06-08",
        "sessions_total": 4,
        "sessions_used": 3,
        "visit_dates": "08.06, 11.06, 12.06",
    },
    {
        "phone": "79262712274",
        "type": "group",
        "purchased_at": "2026-06-04",
        "starts_at": "2026-06-11",
        "sessions_total": 8,
        "sessions_used": 0,
        "visit_dates": "",
    },
    {
        "phone": "79153396350",
        "type": "group",
        "purchased_at": "2026-06-09",
        "starts_at": "2026-06-09",
        "sessions_total": 8,
        "sessions_used": 2,
        "visit_dates": "09.06, 11.06",
    },
    {
        "phone": "79169293406",
        "type": "group",
        "purchased_at": "2026-06-11",
        "starts_at": "2026-06-12",
        "sessions_total": 8,
        "sessions_used": 1,
        "visit_dates": "12.06",
    },
]


def normalize_phone(phone: str) -> str:
    digits = "".join(ch for ch in phone if ch.isdigit())
    if len(digits) == 11 and digits.startswith("8"):
        digits = "7" + digits[1:]
    if len(digits) == 10:
        digits = "7" + digits
    return digits


def ends_at(starts_at: str) -> str:
    start = date.fromisoformat(starts_at)
    return (start + timedelta(days=VALIDITY_DAYS - 1)).isoformat()


def normalize_visit_dates(visit_dates: str, starts_at: str) -> str:
    if not visit_dates.strip():
        return ""

    year = starts_at[:4]
    parts = [part.strip() for part in visit_dates.split(",") if part.strip()]
    normalized = []

    for part in parts:
        if re.fullmatch(r"\d{1,2}\.\d{1,2}", part):
            normalized.append(f"{part}.{year}")
        else:
            normalized.append(part)

    return ", ".join(normalized)


def build_import_php() -> str:
    clients = []
    for client in CLIENTS:
        clients.append(
            {
                **client,
                "phone": normalize_phone(client["phone"]),
            }
        )

    subscriptions = []
    for sub in SUBSCRIPTIONS:
        visit_dates = normalize_visit_dates(sub.get("visit_dates", ""), sub["starts_at"])
        note = IMPORT_NOTE
        if visit_dates:
            note += f". Посещения: {visit_dates}"
        subscriptions.append(
            {
                "phone": normalize_phone(sub["phone"]),
                "type": sub["type"],
                "purchased_at": sub["purchased_at"],
                "starts_at": sub["starts_at"],
                "ends_at": sub.get("ends_at") or ends_at(sub["starts_at"]),
                "sessions_total": sub["sessions_total"],
                "sessions_used": sub["sessions_used"],
                "admin_note": note,
            }
        )

    payload = json.dumps(
        {"clients": clients, "subscriptions": subscriptions},
        ensure_ascii=False,
    )

    return f"""<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use App\\Enums\\SubscriptionType;
use App\\Enums\\UserRole;
use App\\Models\\Subscription;
use App\\Models\\User;
use App\\Support\\PhoneNormalizer;
use Illuminate\\Support\\Carbon;
use Illuminate\\Support\\Str;

$data = json_decode('{payload}', true, 512, JSON_THROW_ON_ERROR);

$clientsCreated = 0;
$clientsSkipped = 0;
$subsCreated = 0;
$subsSkipped = 0;

foreach ($data['clients'] as $clientData) {{
    $phone = PhoneNormalizer::normalize($clientData['phone']);
    if (! $phone) {{
        echo "BAD PHONE: {{$clientData['phone']}}\\n";
        continue;
    }}

    $existing = User::query()->where('phone', $phone)->first();
    if ($existing) {{
        echo "CLIENT EXISTS {{$existing->id}}: {{$existing->fullName()}} ({{$phone}})\\n";
        $clientsSkipped++;
        continue;
    }}

    $user = User::query()->create([
        'first_name' => $clientData['first_name'],
        'last_name' => $clientData['last_name'],
        'phone' => $phone,
        'email' => null,
        'role' => UserRole::Client,
        'password' => Str::password(12, letters: true, numbers: true, symbols: false),
        'offer_accepted_at' => now(),
    ]);

    echo "CLIENT CREATE {{$user->id}}: {{$user->fullName()}} ({{$phone}})\\n";
    $clientsCreated++;
}}

foreach ($data['subscriptions'] as $subData) {{
    $phone = PhoneNormalizer::normalize($subData['phone']);
    $user = User::query()->where('phone', $phone)->first();

    if (! $user) {{
        echo "NO USER FOR SUB: {{$phone}}\\n";
        continue;
    }}

    $type = SubscriptionType::from($subData['type']);
    $startsAt = Carbon::parse($subData['starts_at'])->startOfDay();
    $purchasedAt = Carbon::parse($subData['purchased_at'])->startOfDay();

    $existing = Subscription::query()
        ->where('user_id', $user->id)
        ->where('type', $type->value)
        ->whereDate('purchased_at', $purchasedAt)
        ->whereDate('starts_at', $startsAt)
        ->where('sessions_total', $subData['sessions_total'])
        ->first();

    if ($existing) {{
        echo "SUB EXISTS {{$existing->id}}: {{$user->fullName()}} {{$type->value}} {{$startsAt->toDateString()}}\\n";
        $subsSkipped++;
        continue;
    }}

    $subscription = Subscription::query()->create([
        'user_id' => $user->id,
        'type' => $type,
        'sessions_total' => $subData['sessions_total'],
        'sessions_used' => $subData['sessions_used'],
        'sessions_per_day' => 1,
        'purchased_at' => $subData['purchased_at'],
        'starts_at' => $subData['starts_at'],
        'ends_at' => $subData['ends_at'],
        'admin_note' => $subData['admin_note'],
    ]);

    $remaining = $subscription->sessionsRemaining();
    echo "SUB CREATE {{$subscription->id}}: {{$user->fullName()}} | {{$type->value}} | {{$subData['starts_at']}}..{{$subData['ends_at']}} | used={{$subData['sessions_used']}}/{{$subData['sessions_total']}} (ост. {{$remaining}})\\n";
    $subsCreated++;
}}

echo "Done. clients_created={{$clientsCreated}} clients_skipped={{$clientsSkipped}} subs_created={{$subsCreated}} subs_skipped={{$subsSkipped}}\\n";
"""


def main() -> int:
    host, user, password = ssh_credentials()
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting to {host}...")
    client.connect(host, username=user, password=password, timeout=30)

    remote_script = f"{APP_DIR}/scripts/import_clients_run.php"

    try:
        sftp = client.open_sftp()
        try:
            with sftp.file(remote_script, "w") as remote_file:
                remote_file.write(build_import_php())
        finally:
            sftp.close()

        _, stdout, stderr = client.exec_command(
            f"cd {APP_DIR} && php scripts/import_clients_run.php",
            timeout=180,
        )
        print(stdout.read().decode("utf-8", errors="replace"))
        err = stderr.read().decode("utf-8", errors="replace")
        if err.strip():
            print("STDERR:", err)
            return 1

        verify_cmd = f"""cd {APP_DIR} && php artisan tinker --execute='echo "clients=".App\\Models\\User::query()->where("role","client")->count().PHP_EOL; echo "subs=".App\\Models\\Subscription::query()->count().PHP_EOL; App\\Models\\User::query()->where("role","client")->orderBy("last_name")->get(["id","last_name","first_name","phone"])->each(fn($u) => print($u->id." | ".$u->last_name." ".$u->first_name." | ".$u->phone.PHP_EOL));'
"""
        print("=== VERIFY CLIENTS ===")
        _, stdout, stderr = client.exec_command(verify_cmd, timeout=120)
        print(stdout.read().decode("utf-8", errors="replace"))

        verify_subs = f"""cd {APP_DIR} && php artisan tinker --execute='App\\Models\\Subscription::query()->with("user:id,first_name,last_name")->orderBy("id")->get()->each(fn($s) => print($s->id." | ".$s->user->last_name." ".$s->user->first_name." | ".$s->type->value." | ".$s->sessions_used."/".$s->sessions_total." | ".$s->starts_at->format("d.m.Y")."-".$s->ends_at->format("d.m.Y").PHP_EOL));'
"""
        print("=== VERIFY SUBSCRIPTIONS ===")
        _, stdout, stderr = client.exec_command(verify_subs, timeout=120)
        print(stdout.read().decode("utf-8", errors="replace"))

        client.exec_command(f"rm -f {remote_script}", timeout=30)
        return 0
    finally:
        client.close()


if __name__ == "__main__":
    raise SystemExit(main())
