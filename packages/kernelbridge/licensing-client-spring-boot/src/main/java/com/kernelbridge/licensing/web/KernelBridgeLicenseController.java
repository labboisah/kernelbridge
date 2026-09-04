package com.kernelbridge.licensing.web;

import java.util.Map;

import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.stereotype.Controller;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.ResponseBody;

import com.kernelbridge.licensing.KernelBridgeLicenseService;
import com.kernelbridge.licensing.KernelBridgeLicensingProperties;

@Controller
public class KernelBridgeLicenseController {
    private final KernelBridgeLicenseService service;
    private final KernelBridgeLicensingProperties properties;

    public KernelBridgeLicenseController(KernelBridgeLicenseService service, KernelBridgeLicensingProperties properties) {
        this.service = service;
        this.properties = properties;
    }

    @GetMapping(value = "${kernelbridge.licensing.activation-path:/license}", produces = MediaType.TEXT_HTML_VALUE)
    @ResponseBody
    public String activationPage() {
        String action = properties.getActivationPath().replaceAll("/+$", "") + "/activate";
        return """
            <!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>
            <title>KernelBridge Activation</title><style>body{margin:0;background:#07131f;color:#f8fbff;font-family:Inter,system-ui,sans-serif;display:grid;min-height:100vh;place-items:center}.panel{width:min(92vw,440px);border:1px solid #1f3b57;background:#0d1f31;padding:28px;border-radius:8px;box-shadow:0 24px 70px #0008}label,input,button{display:block;width:100%;box-sizing:border-box}label{font-size:13px;color:#b9c7d6;margin-bottom:8px}input{background:#081622;border:1px solid #31506d;color:#fff;border-radius:6px;padding:12px}button{margin-top:16px;background:#53c7ff;color:#04101a;border:0;border-radius:6px;padding:12px;font-weight:700}h1{font-size:24px;margin:0 0 8px}p{color:#b9c7d6}</style></head>
            <body><main class='panel'><h1>KernelBridge Activation</h1><p>Enter the license key for this installation.</p><form method='post' action='%s'><label>License key</label><input name='licenseKey' required autofocus><button>Activate</button></form></main></body></html>
            """.formatted(action);
    }

    @PostMapping(value = "${kernelbridge.licensing.activation-path:/license}/activate", consumes = MediaType.APPLICATION_JSON_VALUE)
    public ResponseEntity<?> activateJson(@RequestBody Map<String, String> body) {
        return ResponseEntity.ok(service.activate(body.get("licenseKey"), body.get("deviceName")));
    }

    @PostMapping(value = "${kernelbridge.licensing.activation-path:/license}/activate", consumes = MediaType.APPLICATION_FORM_URLENCODED_VALUE)
    public String activateForm(@RequestParam("licenseKey") String licenseKey, @RequestParam(value = "deviceName", required = false) String deviceName) {
        service.activate(licenseKey, deviceName);
        return "redirect:/";
    }

    @PostMapping(value = "${kernelbridge.licensing.activation-path:/license}/verify")
    public ResponseEntity<?> verify() {
        return ResponseEntity.ok(service.verify(true));
    }

    @PostMapping(value = "${kernelbridge.licensing.activation-path:/license}/deactivate")
    public ResponseEntity<?> deactivate() {
        service.deactivate();
        return ResponseEntity.ok(Map.of("status", "deactivated"));
    }
}
