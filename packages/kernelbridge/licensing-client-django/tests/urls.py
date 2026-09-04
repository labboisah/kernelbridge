from django.http import HttpResponse
from django.urls import include, path

from kernelbridge_licensing.decorators import requires_kernelbridge_feature, requires_kernelbridge_license, requires_kernelbridge_limit


def public(request):
    return HttpResponse("public")


@requires_kernelbridge_license
def protected(request):
    return HttpResponse("protected")


@requires_kernelbridge_feature("reports")
def reports(request):
    return HttpResponse("reports")


@requires_kernelbridge_limit("users", 5)
def users(request):
    return HttpResponse("users")


urlpatterns = [
    path("license/", include("kernelbridge_licensing.urls")),
    path("public/", public),
    path("protected/", protected),
    path("reports/", reports),
    path("users/", users),
]
