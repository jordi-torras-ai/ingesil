from __future__ import annotations

from dataclasses import dataclass
from enum import Enum
from typing import Callable, Generic, TypeVar

S = TypeVar("S", bound=Enum)
C = TypeVar("C")


@dataclass
class FSMConfig:
    max_steps: int = 50


class FSMRunner(Generic[S, C]):
    def __init__(
        self,
        *,
        initial_state: S,
        terminal_state: S,
        handlers: dict[S, Callable[[C], S]],
        on_transition: Callable[[C, S, S], None] | None = None,
        config: FSMConfig | None = None,
    ) -> None:
        self.initial_state = initial_state
        self.terminal_state = terminal_state
        self.handlers = handlers
        self.on_transition = on_transition
        self.config = config or FSMConfig()

    def run(self, context: C) -> S:
        state = self.initial_state
        steps = 0

        while state != self.terminal_state:
            if steps >= self.config.max_steps:
                raise RuntimeError(f"FSM max steps reached: {self.config.max_steps}")

            handler = self.handlers.get(state)
            if handler is None:
                raise KeyError(f"Missing FSM handler for state: {state}")

            next_state = handler(context)
            if self.on_transition is not None:
                self.on_transition(context, state, next_state)
            state = next_state
            steps += 1

        return state
