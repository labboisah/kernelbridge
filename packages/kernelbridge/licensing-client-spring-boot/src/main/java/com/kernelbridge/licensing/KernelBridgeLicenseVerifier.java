package com.kernelbridge.licensing;

import org.springframework.scheduling.annotation.Scheduled;

public class KernelBridgeLicenseVerifier {
    private final KernelBridgeLicenseService service;

    public KernelBridgeLicenseVerifier(KernelBridgeLicenseService service) {
        this.service = service;
    }

    @Scheduled(fixedDelayString = "#{@kernelBridgeLicensingDelay}")
    public void verify() {
        service.verify(false);
    }
}

