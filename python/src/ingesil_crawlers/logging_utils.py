from __future__ import annotations

import logging
import sys
from pathlib import Path

COLOR_RESET = "\033[0m"
COLOR_WHITE = "\033[37m"
COLOR_BLUE = "\033[34m"
COLOR_CYAN = "\033[36m"
COLOR_GREEN = "\033[32m"
COLOR_YELLOW = "\033[33m"
COLOR_RED = "\033[31m"


class ColoredFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        level_color = {
            logging.DEBUG: COLOR_BLUE,
            logging.INFO: COLOR_GREEN,
            logging.WARNING: COLOR_RED,
            logging.ERROR: COLOR_RED,
            logging.CRITICAL: COLOR_RED,
        }.get(record.levelno, COLOR_WHITE)

        timestamp = f"{COLOR_WHITE}{self.formatTime(record)}{COLOR_RESET}"
        level = f"{level_color}{record.levelname}{COLOR_RESET}"
        func = f"{COLOR_CYAN}{record.funcName}{COLOR_RESET}"
        message = f"{COLOR_YELLOW}{record.getMessage()}{COLOR_RESET}"
        return f"{timestamp} - {level} - {func} - {message}"


def build_logger(name: str, log_file: Path, level: int = logging.INFO) -> logging.Logger:
    logger = logging.getLogger(name)
    logger.setLevel(level)
    logger.handlers.clear()
    logger.propagate = False

    stream_handler = logging.StreamHandler(sys.stdout)
    stream_handler.setFormatter(ColoredFormatter())
    logger.addHandler(stream_handler)

    file_handler = logging.FileHandler(log_file, encoding="utf-8")
    file_handler.setFormatter(
        logging.Formatter("%(asctime)s - %(levelname)s - %(funcName)s - %(message)s")
    )
    logger.addHandler(file_handler)

    logger.info("Logging to file: %s", log_file)
    return logger

