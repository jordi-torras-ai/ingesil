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
    parser.add_argument("extra_args", nargs=argparse.REMAINDER)
    args = parser.parse_args()

    script_path = CRAWLERS_DIR / f"crawler_{args.slug}.py"
    if not script_path.exists():
        print(f"Crawler script not found for slug '{args.slug}': {script_path}")
        return 1

    command = [sys.executable, str(script_path), "--slug", args.slug, *args.extra_args]
    return subprocess.call(command)


if __name__ == "__main__":
    raise SystemExit(main())

