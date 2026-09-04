from __future__ import annotations

from django.http import HttpRequest, HttpResponse, JsonResponse
from django.shortcuts import redirect
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_http_methods, require_POST

from .exceptions import KernelBridgeApiError
from .services import KernelBridgeLicenseService

ACTIVATION_HTML = """
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>KernelBridge Activation</title><style>body{margin:0;background:#07131f;color:#f8fbff;font-family:Inter,system-ui,sans-serif;display:grid;min-height:100vh;place-items:center}.panel{width:min(92vw,440px);border:1px solid #1f3b57;background:#0d1f31;padding:28px;border-radius:8px;box-shadow:0 24px 70px #0008}label,input,button{display:block;width:100%;box-sizing:border-box}label{font-size:13px;color:#b9c7d6;margin-bottom:8px}input{background:#081622;border:1px solid #31506d;color:#fff;border-radius:6px;padding:12px}button{margin-top:16px;background:#53c7ff;color:#04101a;border:0;border-radius:6px;padding:12px;font-weight:700}h1{font-size:24px;margin:0 0 8px}p{color:#b9c7d6}</style></head>
<body><main class="panel"><h1>KernelBridge Activation</h1><p>Enter the license key for this installation.</p><form method="post" action="activate/"><label>License key</label><input name="license_key" required autofocus><button>Activate</button></form></main></body></html>
"""


def activation(request: HttpRequest) -> HttpResponse:
    return HttpResponse(ACTIVATION_HTML)


@csrf_exempt
@require_http_methods(["POST"])
def activate(request: HttpRequest) -> HttpResponse:
    try:
        if request.content_type == "application/json":
            import json
            body = json.loads(request.body.decode("utf-8") or "{}")
            license_key = body.get("license_key") or body.get("licenseKey")
            device_name = body.get("device_name") or body.get("deviceName")
            state = KernelBridgeLicenseService().activate(license_key, device_name)
            return JsonResponse({"success": True, "data": state})
        KernelBridgeLicenseService().activate(request.POST.get("license_key") or request.POST.get("licenseKey") or "", request.POST.get("device_name"))
        return redirect("/")
    except KernelBridgeApiError as exc:
        return JsonResponse({"error": "license_required", "reason": exc.code, "message": str(exc)}, status=exc.status_code)


@csrf_exempt
@require_POST
def verify(request: HttpRequest) -> JsonResponse:
    state = KernelBridgeLicenseService().verify(force=True)
    return JsonResponse({"success": True, "data": state})


@csrf_exempt
@require_POST
def deactivate(request: HttpRequest) -> JsonResponse:
    KernelBridgeLicenseService().deactivate()
    return JsonResponse({"success": True, "data": {"status": "deactivated"}})
