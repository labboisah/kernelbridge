from __future__ import annotations

from django.http import HttpRequest, HttpResponse, JsonResponse
from django.shortcuts import redirect
from django.urls import reverse

from .services import KernelBridgeLicenseService
from .settings import licensing_settings


class KernelBridgeLicenseMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request: HttpRequest) -> HttpResponse:
        settings = licensing_settings()
        if not settings.protect_web_routes or self._is_excepted(request, settings.protect_except):
            return self.get_response(request)
        decision = KernelBridgeLicenseService().check_active()
        if decision.allowed:
            return self.get_response(request)
        if self._expects_json(request):
            return JsonResponse({"error": "license_required", "reason": decision.reason, "message": decision.message}, status=402)
        try:
            return redirect(reverse(settings.activation_url_name))
        except Exception:
            return redirect(settings.activation_path)

    @staticmethod
    def _is_excepted(request: HttpRequest, patterns: list[str]) -> bool:
        path = request.path
        for pattern in patterns:
            if pattern.endswith("*") and path.startswith(pattern[:-1]):
                return True
            if path.startswith(pattern):
                return True
            if request.resolver_match and request.resolver_match.view_name == pattern:
                return True
        return False

    @staticmethod
    def _expects_json(request: HttpRequest) -> bool:
        accept = request.headers.get("Accept", "")
        return "application/json" in accept or request.path.startswith("/api/") or request.headers.get("X-Requested-With") == "XMLHttpRequest"
