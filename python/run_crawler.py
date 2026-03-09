#!/usr/bin/env python3
from __future__ import annotations

import argparse
import subprocess
import sys
from datetime import datetime
from pathlib import Path


PROJECT_ROOT = Path(__file__).resolve().parents[1]
CRAWLERS_DIR = PROJECT_ROOT / "python" / "crawlers"


def main() -> int:
    parser = argparse.ArgumentParser(description="Run crawler by source slug")
    parser.add_argument("slug", help="Source slug, e.g. dogc")
    parser.add_argument("--skip-embeddings", action="store_true", help="Do not dispatch embedding jobs after a successful crawl")
    parser.add_argument("--embedding-queue", default="default", help="Laravel queue name for embedding jobs")
    parser.add_argument("--triggered-by", default="manual", help="Execution origin, e.g. manual or pipeline")
    parser.add_argument("--run-id", help="Explicit crawler run id")
    args, extra_args = parser.parse_known_args()

    script_path = CRAWLERS_DIR / f"crawler_{args.slug}.py"
    if not script_path.exists():
        print(f"Crawler script not found for slug '{args.slug}': {script_path}")
        return 1

    run_id = args.run_id or datetime.now().strftime("%Y%m%d_%H%M%S")
    issue_date = extract_option(extra_args, "--day")
    mode = resolve_mode(extra_args)
    run_directory = Path("storage") / "crawlers" / args.slug / run_id
    log_path = run_directory / "crawler.log"

    extra_args = inject_option(extra_args, "--run-id", run_id)

    record_start(
        slug=args.slug,
        run_id=run_id,
        run_directory=str(run_directory),
        log_path=str(log_path),
        issue_date=issue_date,
        mode=mode,
        triggered_by=args.triggered_by,
    )

    command = [sys.executable, str(script_path), "--slug", args.slug, *extra_args]
    exit_code = subprocess.call(command)

    record_finish(run_id, exit_code)

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

    result = subprocess.run(command, cwd=PROJECT_ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if result.returncode != 0:
        print(f"[warn] embedding dispatch failed for slug '{slug}' (exit {result.returncode})")


def record_start(
    *,
    slug: str,
    run_id: str,
    run_directory: str,
    log_path: str,
    issue_date: str | None,
    mode: str,
    triggered_by: str,
) -> None:
    artisan_path = PROJECT_ROOT / "artisan"
    if not artisan_path.exists():
        return

    command = [
        "php",
        str(artisan_path),
        "crawler-runs:start",
        slug,
        run_id,
        run_directory,
        log_path,
        f"--mode={mode}",
        f"--triggered-by={triggered_by}",
    ]
    if issue_date:
        command.append(f"--issue-date={issue_date}")

    result = subprocess.run(command, cwd=PROJECT_ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if result.returncode != 0:
        print(f"[warn] crawler run start record failed for slug '{slug}' (exit {result.returncode})")


def record_finish(run_id: str, exit_code: int) -> None:
    artisan_path = PROJECT_ROOT / "artisan"
    if not artisan_path.exists():
        return

    command = [
        "php",
        str(artisan_path),
        "crawler-runs:finish",
        run_id,
        str(exit_code),
    ]

    result = subprocess.run(command, cwd=PROJECT_ROOT)
    if result.returncode != 0:
        print(f"[warn] crawler run finish record failed for run '{run_id}' (exit {result.returncode})")


def extract_option(args: list[str], option: str) -> str | None:
    for index, value in enumerate(args):
        if value == option and index + 1 < len(args):
            return args[index + 1]
        if value.startswith(option + "="):
            return value.split("=", 1)[1]

    return None


def inject_option(args: list[str], option: str, value: str) -> list[str]:
    cleaned_args: list[str] = []
    skip_next = False

    for index, current in enumerate(args):
        if skip_next:
            skip_next = False
            continue

        if current == option:
            skip_next = index + 1 < len(args)
            continue

        if current.startswith(option + "="):
            continue

        cleaned_args.append(current)

    return [*cleaned_args, option, value]


def resolve_mode(args: list[str]) -> str:
    if "--headed" in args:
        return "headed"

    return "headless"


if __name__ == "__main__":
    raise SystemExit(main())
