from django.core.management.base import BaseCommand, CommandError

from kernelbridge_licensing.exceptions import KernelBridgeApiError
from kernelbridge_licensing.services import KernelBridgeLicenseService


class Command(BaseCommand):
    help = "Activate this Django application with a KernelBridge license key."

    def add_arguments(self, parser):
        parser.add_argument("license_key")
        parser.add_argument("--device-name", default=None)

    def handle(self, *args, **options):
        try:
            state = KernelBridgeLicenseService().activate(options["license_key"], options["device_name"])
        except KernelBridgeApiError as exc:
            raise CommandError(str(exc)) from exc
        self.stdout.write(self.style.SUCCESS(f"KernelBridge license activated for {state['product_code']}"))
