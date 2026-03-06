#!/usr/bin/env python3
from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parents[1]
CRAWLERS_DIR = PROJECT_ROOT / "python" / "crawlers"


def main() -> int:
    parser = argparse.ArgumentParser(description="Run crawler by source slug")
    parser.add_argument("slug", help="Source slug, e.g. dogc")
    parser.add_argument("--skip-embeddings", action="store_true", help="Do not dispatch embedding jobs after a successful crawl")
    parser.add_argument("--embedding-queue", default="default", help="Laravel queue name for embedding jobs")
    args, extra_args = parser.parse_known_args()

    script_path = CRAWLERS_DIR / f"crawler_{args.slug}.py"
    if not script_path.exists():
        print(f"Crawler script not found for slug '{args.slug}': {script_path}")
        return 1

    command = [sys.executable, str(script_path), "--slug", args.slug, *extra_args]
    exit_code = subprocess.call(command)

    if exit_code != 0 or args.skip_embeddings:
        return exit_code

    maybe_dispatch_embeddings(args.slug, extra_args, args.embedding_queue)
    return exit_code


def maybe_dispatch_embeddings(slug: str, extra_args: list[str], queue: str) -> None:
    artisan_path = PROJECT_ROOT / "artisan"
    if not artisan_path.exists():
        print(f"[warn] artisan not found, skipping embedding dispatch: {artisan_path}")
        return

    day = extract_option(extra_args, "--day")
    command = [
        "php",
        str(artisan_path),
        "notices:embed",
        "--stale",
        f"--source-slug={slug}",
        f"--queue={queue}",
    ]
    if day:
        command.append(f"--issue-date={day}")

    result = subprocess.run(command, cwd=PROJECT_ROOT)
    if result.returncode != 0:
        print(f"[warn] embedding dispatch failed for slug '{slug}' (exit {result.returncode})")


def extract_option(args: list[str], option: str) -> str | None:
    for index, value in enumerate(args):
        if value == option and index + 1 < len(args):
            return args[index + 1]
        if value.startswith(option + "="):
            return value.split("=", 1)[1]

    return None


if __name__ == "__main__":
    raise SystemExit(main())
