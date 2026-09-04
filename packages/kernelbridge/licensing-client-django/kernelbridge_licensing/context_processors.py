from .services import KernelBridgeLicenseService


def kernelbridge_renewal_notice(request):
    notice = KernelBridgeLicenseService().renewal_notice()
    return {"kernelbridge_renewal_notice": notice}
