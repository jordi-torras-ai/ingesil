from __future__ import annotations

import json
from datetime import datetime
from pathlib import Path

from selenium.webdriver.remote.webdriver import WebDriver


class ArtifactWriter:
    def __init__(self, run_dir: Path) -> None:
        self.run_dir = run_dir
        self.steps_dir = self.run_dir / "steps"
        self.steps_dir.mkdir(parents=True, exist_ok=True)
        self._counter = 0

    def capture(self, driver: WebDriver, *, state: str, note: str = "") -> dict[str, str]:
        self._counter += 1
        step_prefix = f"{self._counter:03d}_{state.lower()}"
        if note:
            step_prefix = f"{step_prefix}_{note}"

        png_path = self.steps_dir / f"{step_prefix}.png"
        html_path = self.steps_dir / f"{step_prefix}.html"
        meta_path = self.steps_dir / f"{step_prefix}.json"

        driver.save_screenshot(str(png_path))
        html_path.write_text(driver.page_source, encoding="utf-8")

        metadata = {
            "state": state,
            "note": note,
            "captured_at": datetime.utcnow().isoformat(),
            "url": driver.current_url,
            "title": driver.title,
            "png": str(png_path),
            "html": str(html_path),
        }
        meta_path.write_text(json.dumps(metadata, ensure_ascii=True, indent=2), encoding="utf-8")

        return {"png": str(png_path), "html": str(html_path), "meta": str(meta_path)}

