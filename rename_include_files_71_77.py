#!/usr/bin/env python3
"""include/71_files〜77_files内のファイル名を一括リネームするスクリプト。"""

from __future__ import annotations

import argparse
import re
from pathlib import Path

TARGET_NAMES = {"css2", "css2(1)", "css2(2)", "css2(１)"}
DIR_PATTERN = re.compile(r"^(\d{2})_files$")


def is_target_dir(path: Path) -> bool:
    match = DIR_PATTERN.match(path.name)
    if not match:
        return False

    num = int(match.group(1))
    return 71 <= num <= 77


def planned_name(file_path: Path) -> str | None:
    name = file_path.name

    if name.endswith(".ダウンロード"):
        base_name = name[: -len(".ダウンロード")]
        if base_name.endswith(".js"):
            return base_name
        return f"{base_name}.js"

    if name in TARGET_NAMES:
        return f"{name}.css"

    return None


def rename_files(base_dir: Path, dry_run: bool = False) -> tuple[int, int]:
    changed = 0
    skipped = 0

    for child in sorted(base_dir.iterdir()):
        if not child.is_dir() or not is_target_dir(child):
            continue

        for file_path in sorted(child.iterdir()):
            if not file_path.is_file():
                continue

            new_name = planned_name(file_path)
            if not new_name:
                continue

            dest = file_path.with_name(new_name)
            if dest.exists():
                skipped += 1
                print(f"[SKIP] 既に存在: {dest}")
                continue

            print(f"[RENAME] {file_path} -> {dest}")
            if not dry_run:
                file_path.rename(dest)
            changed += 1

    return changed, skipped


def main() -> None:
    parser = argparse.ArgumentParser(
        description="include/71_files〜77_files配下のファイルを仕様に沿ってリネームします。"
    )
    parser.add_argument(
        "--base-dir",
        type=Path,
        default=Path("include"),
        help="対象のincludeディレクトリ (デフォルト: include)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="実際にはリネームせず、実行予定のみ表示",
    )
    args = parser.parse_args()

    if not args.base_dir.exists() or not args.base_dir.is_dir():
        raise SystemExit(f"対象ディレクトリが見つかりません: {args.base_dir}")

    changed, skipped = rename_files(args.base_dir, dry_run=args.dry_run)
    mode = "DRY-RUN" if args.dry_run else "DONE"
    print(f"[{mode}] rename件数: {changed}, skip件数: {skipped}")


if __name__ == "__main__":
    main()
