from __future__ import annotations

from functools import wraps
from typing import Callable, TypeVar

from .services import KernelBridgeLicenseService

F = TypeVar("F", bound=Callable)


def requires_kernelbridge_license(view_func: F) -> F:
    @wraps(view_func)
    def wrapper(*args, **kwargs):
        KernelBridgeLicenseService().assert_active()
        return view_func(*args, **kwargs)
    return wrapper  # type: ignore[return-value]


def requires_kernelbridge_feature(feature: str):
    def decorator(view_func: F) -> F:
        @wraps(view_func)
        def wrapper(*args, **kwargs):
            KernelBridgeLicenseService().assert_feature(feature)
            return view_func(*args, **kwargs)
        return wrapper  # type: ignore[return-value]
    return decorator


def requires_kernelbridge_limit(key: str, required: int | float):
    def decorator(view_func: F) -> F:
        @wraps(view_func)
        def wrapper(*args, **kwargs):
            KernelBridgeLicenseService().assert_limit(key, required)
            return view_func(*args, **kwargs)
        return wrapper  # type: ignore[return-value]
    return decorator
