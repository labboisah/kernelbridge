from __future__ import annotations

from typing import Any

import requests

from .exceptions import KernelBridgeApiError, KernelBridgeUnavailableError
from .settings import licensing_settings


class KernelBridgeLicenseClient:
    def __init__(self) -> None:
        self.settings = licensing_settings()

    def activate(self, license_key: str, device_id: str, device_name: str | None = None) -> dict[str, Any]:
        payload = {"license_key": license_key, "device_id": device_id}
        if device_name:
            payload["device_name"] = device_name
        return self._request("POST", "/licenses/activate", json=payload)

    def verify(self, license_key: str, device_id: str) -> dict[str, Any]:
        return self._request("POST", "/licenses/verify", json={"license_key": license_key, "device_id": device_id})

    def deactivate(self, license_key: str, device_id: str) -> dict[str, Any]:
        return self._request("POST", "/licenses/deactivate", json={"license_key": license_key, "device_id": device_id})

    def subscription(self, subscription_uuid: str) -> dict[str, Any]:
        return self._request("GET", "/subscriptions/status", params={"subscription_uuid": subscription_uuid})

    def entitlements(self, subscription_uuid: str) -> dict[str, Any]:
        return self._request("GET", "/entitlements", params={"subscription_uuid": subscription_uuid})

    def _request(self, method: str, path: str, **kwargs: Any) -> dict[str, Any]:
        url = f"{self.settings.api_url}{path}"
        headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self.settings.api_token}",
            "Content-Type": "application/json",
            "X-KernelBridge-Product": self.settings.normalized_product_code,
        }
        try:
            response = requests.request(method, url, headers=headers, timeout=10, **kwargs)
        except requests.RequestException as exc:
            raise KernelBridgeUnavailableError("KernelBridge licensing request failed.") from exc
        if response.status_code >= 500:
            raise KernelBridgeUnavailableError("KernelBridge is temporarily unavailable.")
        try:
            body = response.json()
        except ValueError as exc:
            raise KernelBridgeUnavailableError("KernelBridge returned an unreadable response.") from exc
        if not response.ok or body.get("success") is not True or "data" not in body:
            error = body.get("error") or {}
            raise KernelBridgeApiError(error.get("message") or "KernelBridge rejected the licensing request.", error.get("code") or "license_rejected", response.status_code)
        return body["data"]
