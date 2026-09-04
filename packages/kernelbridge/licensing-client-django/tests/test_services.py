from datetime import timedelta

import pytest
from django.test import Client, TestCase, override_settings
from django.utils import timezone

from kernelbridge_licensing.exceptions import KernelBridgeApiError
from kernelbridge_licensing.models import KernelBridgeLicenseState
from kernelbridge_licensing.services import KernelBridgeLicenseService


class FakeClient:
    def __init__(self, fail_activation_limit=False):
        self.fail_activation_limit = fail_activation_limit
        self.verify_calls = 0

    def activate(self, license_key, device_id, device_name=None):
        if self.fail_activation_limit:
            raise KernelBridgeApiError("limit", "activation_limit_reached", 422)
        return {"license_uuid": "lic-1", "activation_uuid": "act-1", "subscription_uuid": "sub-1", "status": "active", "expires_at": (timezone.now() + timedelta(days=75)).isoformat()}

    def verify(self, license_key, device_id):
        self.verify_calls += 1
        return self.activate(license_key, device_id)

    def subscription(self, subscription_uuid):
        return {"uuid": subscription_uuid, "status": "active", "starts_at": timezone.now().isoformat(), "ends_at": (timezone.now() + timedelta(days=75)).isoformat(), "grace_ends_at": None}

    def entitlements(self, subscription_uuid):
        return {"entitlements": [{"key": "reports", "enabled": True, "limit": None}, {"key": "users", "enabled": True, "limit": 10}], "signed_payload": None, "signature": None, "key_id": "test"}


class KernelBridgeDjangoClientTest(TestCase):
    def service(self, client=None):
        return KernelBridgeLicenseService(client=client or FakeClient())

    def test_activation_stores_encrypted_state_and_uses_cached_entitlements(self):
        service = self.service()
        service.activate("KB-123", "Test Device")
        row = KernelBridgeLicenseState.objects.get(product_code="ALIGNEX")

        self.assertNotIn("KB-123", row.encrypted_payload)
        self.assertTrue(service.check_active().allowed)
        self.assertTrue(service.has_feature("reports"))
        self.assertTrue(service.within_limit("users", 5))
        self.assertFalse(service.within_limit("users", 15))

    def test_recent_verification_uses_cache(self):
        fake = FakeClient()
        service = self.service(fake)
        service.activate("KB-123")
        service.verify(force=False)

        self.assertEqual(fake.verify_calls, 0)

    def test_configuration_tamper_requires_reactivation(self):
        service = self.service()
        service.activate("KB-123")

        with override_settings(KERNELBRIDGE_LICENSING={
            "API_URL": "https://changed.test/api/v1",
            "PRODUCT_CODE": "ALIGNEX",
            "API_TOKEN": "token",
            "CACHE_SIGNING_KEY": "0123456789abcdef0123456789abcdef",
            "ENCRYPTION_KEY": "abcdef0123456789abcdef0123456789",
            "PROTECT_WEB_ROUTES": True,
        }):
            changed = KernelBridgeLicenseService(client=FakeClient())
            decision = changed.check_active()
            self.assertFalse(decision.allowed)
            self.assertEqual(decision.reason, "configuration_tampered")

    def test_renewal_notice_warns_at_three_months(self):
        service = self.service()
        service.activate("KB-123")

        notice = service.renewal_notice()
        self.assertIsNotNone(notice)
        self.assertEqual(notice.level, "3_months")

    def test_activation_limit_error_uses_device_limit_message(self):
        with self.assertRaises(KernelBridgeApiError) as raised:
            self.service(FakeClient(fail_activation_limit=True)).activate("KB-123")

        self.assertIn("device activation limit", str(raised.exception))

    def test_middleware_redirects_browser_and_returns_402_json(self):
        browser = Client().get("/protected/")
        api = Client(HTTP_ACCEPT="application/json").get("/protected/")

        self.assertEqual(browser.status_code, 302)
        self.assertEqual(api.status_code, 402)
        self.assertEqual(api.json()["reason"], "license_not_activated")

    def test_protected_decorators_pass_after_activation(self):
        self.service().activate("KB-123")

        self.assertEqual(Client().get("/protected/").status_code, 200)
        self.assertEqual(Client().get("/reports/").status_code, 200)
        self.assertEqual(Client().get("/users/").status_code, 200)
