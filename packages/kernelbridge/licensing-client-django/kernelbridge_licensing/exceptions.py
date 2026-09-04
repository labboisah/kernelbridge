class KernelBridgeError(Exception):
    pass


class KernelBridgeApiError(KernelBridgeError):
    def __init__(self, message: str, code: str = "license_rejected", status_code: int = 400) -> None:
        super().__init__(message)
        self.code = code
        self.status_code = status_code


class KernelBridgeUnavailableError(KernelBridgeError):
    pass


class KernelBridgeAccessError(KernelBridgeApiError):
    pass
