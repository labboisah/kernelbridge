from __future__ import annotations

import base64
import hashlib
import hmac
import json
from typing import Any

from cryptography.fernet import Fernet, InvalidToken

from .settings import licensing_settings


def canonical_json(value: Any) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), default=str).encode("utf-8")


class LicenseCrypto:
    def __init__(self) -> None:
        self.settings = licensing_settings()

    def encrypt_json(self, value: Any) -> str:
        return self._fernet().encrypt(canonical_json(value)).decode("utf-8")

    def decrypt_json(self, value: str) -> Any:
        try:
            return json.loads(self._fernet().decrypt(value.encode("utf-8")).decode("utf-8"))
        except (InvalidToken, ValueError, TypeError) as exc:
            raise RuntimeError("KernelBridge license state could not be decrypted.") from exc

    def encrypt_text(self, value: str) -> str:
        return self._fernet().encrypt(value.encode("utf-8")).decode("utf-8")

    def decrypt_text(self, value: str) -> str:
        try:
            return self._fernet().decrypt(value.encode("utf-8")).decode("utf-8")
        except InvalidToken as exc:
            raise RuntimeError("KernelBridge encrypted text could not be decrypted.") from exc

    def sign(self, value: Any) -> str:
        digest = hmac.new(self.settings.signing_key.encode("utf-8"), canonical_json(value), hashlib.sha256).hexdigest()
        return digest

    def verify(self, value: Any, signature: str) -> bool:
        return hmac.compare_digest(self.sign(value), signature or "")

    def config_fingerprint(self) -> str:
        payload = {
            "api_url": self.settings.api_url,
            "product_code": self.settings.normalized_product_code,
            "api_token_hash": self._sha256(self.settings.api_token),
            "encryption_key_hash": self._sha256(self.settings.state_encryption_key),
            "signature_key_hash": self._sha256(self.settings.signing_key),
            "extra": self.settings.extra_fingerprint_values,
        }
        return self.sign(payload)

    def _fernet(self) -> Fernet:
        digest = hashlib.sha256(self.settings.state_encryption_key.encode("utf-8")).digest()
        return Fernet(base64.urlsafe_b64encode(digest))

    @staticmethod
    def _sha256(value: str) -> str:
        return hashlib.sha256((value or "").encode("utf-8")).hexdigest()
