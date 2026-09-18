from functools import lru_cache
from typing import Literal

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_prefix="NEXA_MT5_",
        extra="ignore",
    )

    mode: Literal["mock", "real"] = "mock"
    host: str = "127.0.0.1"
    port: int = Field(default=8765, ge=1024, le=65535)
    service_token: str = Field(default="", repr=False)
    terminal_path: str = Field(default="", repr=False)
    login: int | None = Field(default=None, repr=False)
    password: str = Field(default="", repr=False)
    server: str = Field(default="", repr=False)
    stale_after_seconds: float = Field(default=15, gt=0, le=300)
    connect_attempts: int = Field(default=3, ge=1, le=10)
    backoff_initial_seconds: float = Field(default=0.25, ge=0, le=10)
    backoff_max_seconds: float = Field(default=2, ge=0, le=30)
    history_max_records: int = Field(default=500, ge=1, le=5000)


@lru_cache
def get_settings() -> Settings:
    return Settings()
