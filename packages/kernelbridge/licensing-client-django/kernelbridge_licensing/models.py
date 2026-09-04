from django.db import models


class KernelBridgeLicenseState(models.Model):
    product_code = models.CharField(max_length=80, unique=True)
    encrypted_payload = models.TextField(blank=True)
    signature = models.CharField(max_length=128, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        db_table = "kernelbridge_license_states"

    def __str__(self) -> str:
        return self.product_code
