from django.core.management.base import BaseCommand

from kernelbridge_licensing.services import KernelBridgeLicenseService


class Command(BaseCommand):
    help = "Deactivate this KernelBridge installation."

    def handle(self, *args, **options):
        KernelBridgeLicenseService().deactivate()
        self.stdout.write(self.style.SUCCESS("KernelBridge license deactivated."))
