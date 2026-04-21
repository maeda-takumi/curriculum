#!/usr/bin/env python3
"""include/71.html〜77.html の参照パスを一括置換する。"""

from __future__ import annotations

import argparse
import re
from pathlib import Path

TARGET_RANGE = range(71, 78)
CSS_PATTERN = re.compile(r"(?<![\w/.-])(css2(?:\(1\)|\(2\))?)(?!\.css)")


def transform_html(text: str, n: int) -> str:
    updated = text.replace(".ダウンロード", "")
    updated = updated.replace(f"./{n}_files", f"phase/{n}_flies")
    updated = CSS_PATTERN.sub(r"\1.css", updated)
    return updated


def process_file(path: Path, dry_run: bool = False) -> bool:
    original = path.read_text(encoding="utf-8")
    num = int(path.stem)
    updated = transform_html(original, num)

    if updated == original:
        return False

    if not dry_run:
        path.write_text(updated, encoding="utf-8")
    return True


def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "include/71.html〜77.html で次を実施: "
            "'.ダウンロード'削除 / './NN_files'→'phase/NN_flies' / css2系へ .css 付与"
        )
    )
    parser.add_argument("--base-dir", type=Path, default=Path("include"))
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    changed = 0
    for n in TARGET_RANGE:
        file_path = args.base_dir / f"{n}.html"
        if not file_path.exists():
            print(f"[SKIP] not found: {file_path}")
            continue

        if process_file(file_path, dry_run=args.dry_run):
            changed += 1
            action = "would update" if args.dry_run else "updated"
            print(f"[{action}] {file_path}")
        else:
            print(f"[no change] {file_path}")

    mode = "DRY-RUN" if args.dry_run else "DONE"
    print(f"[{mode}] changed files: {changed}")


if __name__ == "__main__":
    main()
