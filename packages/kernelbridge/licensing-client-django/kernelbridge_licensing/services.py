from __future__ import annotations

import uuid
from dataclasses import dataclass
from datetime import timedelta
from typing import Any

from django.db import transaction
from django.utils import timezone
from django.utils.dateparse import parse_datetime

from .client import KernelBridgeLicenseClient
from .crypto import LicenseCrypto
from .exceptions import KernelBridgeAccessError, KernelBridgeApiError, KernelBridgeUnavailableError
from .models import KernelBridgeLicenseState
from .settings import licensing_settings

USABLE_STATUSES = {"active", "trial", "grace"}
ACTIVATION_LIMIT_MESSAGE = "This license has reached its device activation limit. Deactivate another installation in KernelBridge before trying again."


@dataclass(frozen=True)
class LicenseAccessDecision:
    allowed: bool
    reason: str
    message: str


@dataclass(frozen=True)
class RenewalNotice:
    level: str
    message: str
    expires_at: Any


class KernelBridgeLicenseService:
    def __init__(self, client: KernelBridgeLicenseClient | None = None, crypto: LicenseCrypto | None = None) -> None:
        self.settings = licensing_settings()
        self.client = client or KernelBridgeLicenseClient()
        self.crypto = crypto or LicenseCrypto()

    def state(self) -> dict[str, Any]:
        row = self._row()
        if not row.encrypted_payload:
            state = self._blank_state(row.product_code)
            self._save_state(row, state)
            return state
        if not self.crypto.verify(row.encrypted_payload, row.signature):
            raise RuntimeError("KernelBridge license state signature is invalid.")
        state = self.crypto.decrypt_json(row.encrypted_payload)
        state.setdefault("product_code", row.product_code)
        return state

    def activate(self, license_key: str, device_name: str | None = None) -> dict[str, Any]:
        state = self.state()
        try:
            license_data = self.client.activate(license_key, state["installation_identifier"], device_name)
            subscription = self.client.subscription(license_data["subscription_uuid"])
            entitlements = self.client.entitlements(license_data["subscription_uuid"])
            return self._store_verified(license_key, license_data, subscription, entitlements)
        except KernelBridgeApiError as exc:
            if exc.code == "activation_limit_reached":
                raise KernelBridgeApiError(ACTIVATION_LIMIT_MESSAGE, exc.code, exc.status_code) from exc
            self._mark_rejected(exc.code)
            raise

    def verify(self, force: bool = False) -> dict[str, Any]:
        state = self.state()
        last_verified = self._parse_dt(state.get("last_successful_verification_at"))
        if not force and last_verified and last_verified + timedelta(seconds=self.settings.verification_interval_seconds) > timezone.now():
            return state
        if not state.get("encrypted_license_key"):
            return state
        try:
            license_key = self.crypto.decrypt_text(state["encrypted_license_key"])
            license_data = self.client.verify(license_key, state["installation_identifier"])
            subscription = self.client.subscription(license_data["subscription_uuid"])
            entitlements = self.client.entitlements(license_data["subscription_uuid"])
            return self._store_verified(license_key, license_data, subscription, entitlements)
        except KernelBridgeApiError as exc:
            self._mark_rejected(exc.code)
            raise
        except KernelBridgeUnavailableError:
            if self.has_usable_license():
                return self.state()
            raise

    def deactivate(self) -> None:
        state = self.state()
        if state.get("encrypted_license_key"):
            license_key = self.crypto.decrypt_text(state["encrypted_license_key"])
            self.client.deactivate(license_key, state["installation_identifier"])
        state["status"] = "deactivated"
        state["offline_grace_expires_at"] = timezone.now().isoformat()
        state["last_error_code"] = None
        self._save_state(self._row(), state)

    def check_active(self) -> LicenseAccessDecision:
        if self.has_usable_license():
            return LicenseAccessDecision(True, "license_active", "KernelBridge license is active.")
        reason = self.activation_requirement_reason()
        return LicenseAccessDecision(False, reason, self.activation_requirement_message(reason))

    def assert_active(self) -> None:
        decision = self.check_active()
        if not decision.allowed:
            raise KernelBridgeAccessError(decision.message, decision.reason, 402)

    def assert_feature(self, key: str) -> None:
        self.assert_active()
        if not self.has_feature(key):
            raise KernelBridgeAccessError(f"The '{key}' feature is not included in this plan.", "feature_unavailable", 402)

    def assert_limit(self, key: str, required: int | float) -> None:
        self.assert_active()
        if not self.within_limit(key, required):
            raise KernelBridgeAccessError(f"The '{key}' plan limit is insufficient.", "limit_exceeded", 402)

    def has_usable_license(self) -> bool:
        try:
            state = self.state()
            expires_at = self._parse_dt(state.get("expires_at"))
            offline_grace = self._parse_dt(state.get("offline_grace_expires_at"))
            return (
                state.get("status") in USABLE_STATUSES
                and bool(state.get("last_successful_verification_at"))
                and (expires_at is None or expires_at > timezone.now())
                and offline_grace is not None
                and offline_grace > timezone.now()
                and self._config_fingerprint_is_valid(state)
                and self._entitlement_signature_is_valid(state)
            )
        except Exception:
            return False

    def has_feature(self, key: str) -> bool:
        if not self.has_usable_license():
            return False
        entitlement = self.state().get("entitlements", {}).get(key) or {}
        return bool(entitlement.get("enabled"))

    def within_limit(self, key: str, required: int | float) -> bool:
        if not self.has_feature(key):
            return False
        limit = (self.state().get("entitlements", {}).get(key) or {}).get("limit")
        if limit is None or limit == "unlimited":
            return True
        return float(limit) >= float(required)

    def renewal_notice(self) -> RenewalNotice | None:
        if not self.settings.renewal_notice_enabled or not self.has_usable_license():
            return None
        expires_at = self._parse_dt(self.state().get("expires_at"))
        if expires_at is None or expires_at <= timezone.now():
            return None
        today = timezone.localdate()
        expiry = expires_at.date()
        for months in self.settings.renewal_notice_months:
            if self._add_months(today, int(months)) >= expiry:
                level = "1_month" if int(months) == 1 else f"{int(months)}_months"
                month_text = "1 month" if int(months) == 1 else f"{int(months)} months"
                return RenewalNotice(level, f"Your KernelBridge license expires in about {month_text} on {expiry.isoformat()}. Renew to avoid service interruption.", expires_at)
        return None

    def activation_requirement_reason(self) -> str:
        try:
            state = self.state()
            expires_at = self._parse_dt(state.get("expires_at"))
            offline_grace = self._parse_dt(state.get("offline_grace_expires_at"))
            if not state.get("encrypted_license_key"):
                return "license_not_activated"
            if not self._config_fingerprint_is_valid(state):
                return "configuration_tampered"
            if not self._entitlement_signature_is_valid(state):
                return "local_license_tampered"
            if state.get("status") == "deactivated":
                return "license_deactivated"
            if state.get("status") == "invalid":
                return state.get("last_error_code") or "license_invalid"
            if expires_at and expires_at <= timezone.now():
                return "license_expired"
            if offline_grace is None or offline_grace <= timezone.now():
                return "offline_grace_expired"
            if state.get("status") not in USABLE_STATUSES:
                return "license_inactive"
            return "license_required"
        except Exception:
            return "local_license_tampered"

    def activation_requirement_message(self, reason: str | None = None) -> str:
        return {
            "activation_limit_reached": ACTIVATION_LIMIT_MESSAGE,
            "configuration_tampered": "KernelBridge licensing configuration changed. Re-activation is required.",
            "license_deactivated": "This installation has been deactivated. Re-activation is required.",
            "license_expired": "This license has expired. Renew or activate a valid license.",
            "license_not_activated": "Activate this application before continuing.",
            "license_revoked": "This license was revoked by KernelBridge. Activate a valid license.",
            "local_license_tampered": "The local license cache was changed or cannot be trusted. Re-activation is required.",
            "offline_grace_expired": "Offline grace has expired. Reconnect to KernelBridge and verify the license.",
        }.get(reason or self.activation_requirement_reason(), "An active KernelBridge subscription is required.")

    @transaction.atomic
    def _store_verified(self, license_key: str, license_data: dict[str, Any], subscription: dict[str, Any], entitlements: dict[str, Any]) -> dict[str, Any]:
        state = self.state()
        verified_at = timezone.now()
        expires_at = self._first_dt(license_data.get("expires_at"), subscription.get("grace_ends_at"), subscription.get("ends_at"))
        offline_grace = verified_at + timedelta(seconds=self.settings.offline_grace_seconds)
        if expires_at and offline_grace > expires_at:
            offline_grace = expires_at
        entitlement_map = {
            str(item.get("key")): {"enabled": bool(item.get("enabled")), "limit": item.get("limit")}
            for item in entitlements.get("entitlements", [])
        }
        state.update(
            {
                "license_identifier": license_data.get("license_uuid"),
                "subscription_identifier": license_data.get("subscription_uuid"),
                "activation_identifier": license_data.get("activation_uuid") or state.get("activation_identifier"),
                "encrypted_license_key": self.crypto.encrypt_text(license_key),
                "status": subscription.get("status") or license_data.get("status") or "inactive",
                "last_successful_verification_at": verified_at.isoformat(),
                "expires_at": expires_at.isoformat() if expires_at else None,
                "offline_grace_expires_at": offline_grace.isoformat(),
                "entitlements": entitlement_map,
                "signed_entitlement_payload": entitlements.get("signed_payload"),
                "entitlement_signature": self.crypto.sign(entitlement_map),
                "signature_key_id": entitlements.get("key_id"),
                "config_fingerprint": self.crypto.config_fingerprint(),
                "config_fingerprinted_at": verified_at.isoformat(),
                "last_error_code": None,
            }
        )
        self._save_state(self._row(), state)
        return state

    def _mark_rejected(self, code: str) -> None:
        state = self.state()
        state["status"] = "invalid"
        state["last_error_code"] = code
        self._save_state(self._row(), state)

    def _row(self) -> KernelBridgeLicenseState:
        row, _ = KernelBridgeLicenseState.objects.get_or_create(product_code=self.settings.normalized_product_code)
        return row

    def _save_state(self, row: KernelBridgeLicenseState, state: dict[str, Any]) -> None:
        row.encrypted_payload = self.crypto.encrypt_json(state)
        row.signature = self.crypto.sign(row.encrypted_payload)
        row.save(update_fields=["encrypted_payload", "signature", "updated_at"])

    def _blank_state(self, product_code: str) -> dict[str, Any]:
        return {
            "version": 1,
            "product_code": product_code,
            "installation_identifier": str(uuid.uuid4()),
            "license_identifier": None,
            "subscription_identifier": None,
            "activation_identifier": None,
            "status": "unlicensed",
            "last_successful_verification_at": None,
            "expires_at": None,
            "offline_grace_expires_at": None,
            "entitlements": {},
            "signed_entitlement_payload": None,
            "entitlement_signature": None,
            "signature_key_id": None,
            "encrypted_license_key": None,
            "config_fingerprint": None,
            "config_fingerprinted_at": None,
            "last_error_code": None,
        }

    def _config_fingerprint_is_valid(self, state: dict[str, Any]) -> bool:
        return not state.get("encrypted_license_key") or state.get("config_fingerprint") == self.crypto.config_fingerprint()

    def _entitlement_signature_is_valid(self, state: dict[str, Any]) -> bool:
        return bool(state.get("entitlement_signature")) and self.crypto.verify(state.get("entitlements") or {}, state.get("entitlement_signature"))

    @staticmethod
    def _parse_dt(value: str | None):
        if not value:
            return None
        parsed = parse_datetime(value)
        if parsed and timezone.is_naive(parsed):
            parsed = timezone.make_aware(parsed, timezone.utc)
        return parsed

    def _first_dt(self, *values: str | None):
        for value in values:
            parsed = self._parse_dt(value)
            if parsed:
                return parsed
        return None

    @staticmethod
    def _add_months(date, months: int):
        month = date.month - 1 + months
        year = date.year + month // 12
        month = month % 12 + 1
        days = [31, 29 if year % 4 == 0 and (year % 100 != 0 or year % 400 == 0) else 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
        day = min(date.day, days[month - 1])
        return date.replace(year=year, month=month, day=day)
