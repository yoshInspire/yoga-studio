"""
Импорт базы «йога-человечков» (схематичных асан) с in-yoga.ru.

Обходит 8 страниц категорий, забирает каждую позу отдельным PNG вместе с
названием из атрибута title и раскладывает по категориям.

На выходе:
  <output>/images/<категория>/<название>.png
  <output>/manifest.json  — [{name, category, file, source_url}, ...]

Запуск:
    python deploy/import_asanas.py
    python deploy/import_asanas.py --output storage/app/asanas --force
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
from pathlib import Path
from urllib.parse import quote, urljoin

import requests
from bs4 import BeautifulSoup

BASE_URL = "https://in-yoga.ru/"

# Страница категории -> человекочитаемое название
CATEGORIES: dict[str, str] = {
    "stay asana.html": "Асаны стоя",
    "balensy.html": "Балансы на руках",
    "deflections.html": "Прогибы",
    "sitting.html": "Асаны сидя и лежа",
    "force.html": "Силовые асаны",
    "invert.html": "Перевернутые асаны",
    "sun.html": "Сурья Намаскар",
    "relax.html": "Позы расслабления",
}

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; EkoYogaAsanaImport/1.0)",
}

REQUEST_DELAY = 0.4
TIMEOUT = 30

# В имени файла оставляем кириллицу и латиницу, остальное схлопываем в подчёркивание.
UNSAFE_FILENAME = re.compile(r"[^\wЀ-ӿ\-. ]+", re.UNICODE)


def fetch(url: str) -> requests.Response:
    last_error: Exception | None = None

    for attempt in range(3):
        try:
            response = requests.get(url, headers=HEADERS, timeout=TIMEOUT)
            response.raise_for_status()
            return response
        except Exception as error:  # noqa: BLE001 - сеть флапает, просто повторяем
            last_error = error
            time.sleep(1.5 * (attempt + 1))

    raise RuntimeError(f"не удалось загрузить {url}: {last_error}")


def decode_html(response: requests.Response) -> str:
    """Страницы отдаются без внятной кодировки — доверяем автоопределению."""
    if not response.encoding or response.encoding.lower() == "iso-8859-1":
        response.encoding = response.apparent_encoding or "utf-8"

    return response.text


def safe_filename(name: str) -> str:
    cleaned = UNSAFE_FILENAME.sub("", name).strip().strip(".")
    cleaned = re.sub(r"\s+", " ", cleaned)

    return cleaned or "asana"


def collect_poses(page: str, category: str) -> list[dict[str, str]]:
    url = urljoin(BASE_URL, quote(page))
    soup = BeautifulSoup(decode_html(fetch(url)), "html.parser")

    poses: list[dict[str, str]] = []
    seen_titles: set[str] = set()

    for img in soup.find_all("img"):
        title = (img.get("title") or "").strip()
        src = (img.get("src") or "").strip()

        # Позы всегда подписаны title; счётчики и декор — нет.
        if not title or not src or not src.lower().endswith(".png"):
            continue

        if title in seen_titles:
            continue

        seen_titles.add(title)
        poses.append({
            "name": title,
            "category": category,
            "source_url": urljoin(url, quote(src)),
        })

    return poses


def download(pose: dict[str, str], images_root: Path, force: bool) -> bool:
    target_dir = images_root / safe_filename(pose["category"])
    target_dir.mkdir(parents=True, exist_ok=True)

    target = target_dir / f"{safe_filename(pose['name'])}.png"

    # Разные асаны иногда дают одинаковое имя файла — разводим суффиксом.
    counter = 2
    while target.exists() and not force:
        if target.stat().st_size > 0:
            pose["file"] = str(target.relative_to(images_root.parent)).replace("\\", "/")
            return False

        target = target_dir / f"{safe_filename(pose['name'])}_{counter}.png"
        counter += 1

    content = fetch(pose["source_url"]).content
    target.write_bytes(content)

    pose["file"] = str(target.relative_to(images_root.parent)).replace("\\", "/")
    time.sleep(REQUEST_DELAY)

    return True


def main() -> int:
    parser = argparse.ArgumentParser(description="Импорт схематичных асан с in-yoga.ru")
    parser.add_argument(
        "--output",
        default="storage/app/asanas",
        help="куда складывать картинки и manifest.json (по умолчанию storage/app/asanas)",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="перекачать файлы, даже если они уже есть",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="только показать, что будет импортировано, ничего не скачивая",
    )
    args = parser.parse_args()

    output_root = Path(args.output).resolve()
    images_root = output_root / "images"
    images_root.mkdir(parents=True, exist_ok=True)

    all_poses: list[dict[str, str]] = []
    downloaded = 0

    for page, category in CATEGORIES.items():
        try:
            poses = collect_poses(page, category)
        except Exception as error:  # noqa: BLE001 - одна битая категория не должна ронять импорт
            print(f"  ! {category}: {error}", file=sys.stderr)
            continue

        print(f"  {category}: найдено поз — {len(poses)}")

        for pose in poses:
            if args.dry_run:
                print(f"    · {pose['name']}")
                all_poses.append(pose)
                continue

            try:
                if download(pose, images_root, args.force):
                    downloaded += 1
            except Exception as error:  # noqa: BLE001
                print(f"    ! {pose['name']}: {error}", file=sys.stderr)
                continue

            all_poses.append(pose)

        time.sleep(REQUEST_DELAY)

    if args.dry_run:
        print(f"\n[dry-run] Всего поз к импорту: {len(all_poses)}. Ничего не скачано.")

        return 0

    manifest = output_root / "manifest.json"
    manifest.write_text(
        json.dumps(all_poses, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print(f"\nВсего поз: {len(all_poses)}, скачано новых файлов: {downloaded}")
    print(f"Картинки: {images_root}")
    print(f"Манифест: {manifest}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
