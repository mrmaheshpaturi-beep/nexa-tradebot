from enum import StrEnum


class ErrorCode(StrEnum):
    AUTHENTICATION_REQUIRED = "AUTHENTICATION_REQUIRED"
    NOT_CONFIGURED = "NOT_CONFIGURED"
    PLATFORM_UNSUPPORTED = "PLATFORM_UNSUPPORTED"
    CONNECTION_FAILED = "CONNECTION_FAILED"
    DISCONNECTED = "DISCONNECTED"
    STALE = "STALE"
    INVALID_REQUEST = "INVALID_REQUEST"
    NOT_FOUND = "NOT_FOUND"
    PROVIDER_ERROR = "PROVIDER_ERROR"


class BridgeError(Exception):
    def __init__(self, code: ErrorCode, safe_message: str, status_code: int = 503) -> None:
        super().__init__(safe_message)
        self.code = code
        self.safe_message = safe_message
        self.status_code = status_code
