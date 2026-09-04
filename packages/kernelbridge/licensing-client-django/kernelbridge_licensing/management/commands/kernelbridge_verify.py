from django.core.management.base import BaseCommand

from kernelbridge_licensing.services import KernelBridgeLicenseService


class Command(BaseCommand):
    help = "Verify the current KernelBridge license."

    def add_arguments(self, parser):
        parser.add_argument("--force", action="store_true")

    def handle(self, *args, **options):
        state = KernelBridgeLicenseService().verify(force=options["force"])
        self.stdout.write(self.style.SUCCESS(f"KernelBridge license status: {state['status']}"))
