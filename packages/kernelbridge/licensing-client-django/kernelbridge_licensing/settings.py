from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

from django.conf import settings as django_settings


@dataclass(frozen=True)
class KernelBridgeLicensingSettings:
    api_url: str = "https://kernelbridge.com/api/v1"
    product_code: str = ""
    api_token: str = ""
    cache_signing_key: str = ""
    encryption_key: str = ""
    verification_interval_seconds: int = 900
    offline_grace_seconds: int = 259200
    activation_url_name: str = "kernelbridge_licensing:activation"
    activation_path: str = "/license/"
    protect_web_routes: bool = False
    protect_except: list[str] = field(default_factory=lambda: ["/license/", "/admin/login/", "/accounts/login/", "/static/", "/media/"])
    renewal_notice_enabled: bool = True
    renewal_notice_months: list[int] = field(default_factory=lambda: [3, 2, 1])
    extra_fingerprint_values: dict[str, Any] = field(default_factory=dict)

    @property
    def normalized_product_code(self) -> str:
        if not self.product_code:
            raise RuntimeError("KERNELBRIDGE_LICENSING['PRODUCT_CODE'] is required.")
        return self.product_code.upper()

    @property
    def signing_key(self) -> str:
        if not self.cache_signing_key:
            raise RuntimeError("KERNELBRIDGE_LICENSING['CACHE_SIGNING_KEY'] is required.")
        return self.cache_signing_key

    @property
    def state_encryption_key(self) -> str:
        return self.encryption_key or self.signing_key


def licensing_settings() -> KernelBridgeLicensingSettings:
    raw = getattr(django_settings, "KERNELBRIDGE_LICENSING", {}) or {}
    return KernelBridgeLicensingSettings(
        api_url=raw.get("API_URL", KernelBridgeLicensingSettings.api_url).rstrip("/"),
        product_code=raw.get("PRODUCT_CODE", ""),
        api_token=raw.get("API_TOKEN", ""),
        cache_signing_key=raw.get("CACHE_SIGNING_KEY", ""),
        encryption_key=raw.get("ENCRYPTION_KEY", ""),
        verification_interval_seconds=int(raw.get("VERIFICATION_INTERVAL_SECONDS", 900)),
        offline_grace_seconds=int(raw.get("OFFLINE_GRACE_SECONDS", 259200)),
        activation_url_name=raw.get("ACTIVATION_URL_NAME", "kernelbridge_licensing:activation"),
        activation_path=raw.get("ACTIVATION_PATH", "/license/"),
        protect_web_routes=bool(raw.get("PROTECT_WEB_ROUTES", False)),
        protect_except=list(raw.get("PROTECT_EXCEPT", ["/license/", "/admin/login/", "/accounts/login/", "/static/", "/media/"])),
        renewal_notice_enabled=bool(raw.get("RENEWAL_NOTICE_ENABLED", True)),
        renewal_notice_months=list(raw.get("RENEWAL_NOTICE_MONTHS", [3, 2, 1])),
        extra_fingerprint_values=dict(raw.get("EXTRA_FINGERPRINT_VALUES", {})),
    )
