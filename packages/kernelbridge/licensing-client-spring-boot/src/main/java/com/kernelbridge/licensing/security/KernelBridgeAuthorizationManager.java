package com.kernelbridge.licensing.security;

import java.util.function.Supplier;

import org.springframework.security.authorization.AuthorizationDecision;
import org.springframework.security.authorization.AuthorizationManager;
import org.springframework.security.core.Authentication;
import org.springframework.security.web.access.intercept.RequestAuthorizationContext;

import com.kernelbridge.licensing.KernelBridgeLicenseService;

public class KernelBridgeAuthorizationManager implements AuthorizationManager<RequestAuthorizationContext> {
    private final KernelBridgeLicenseService service;

    public KernelBridgeAuthorizationManager(KernelBridgeLicenseService service) {
        this.service = service;
    }

    @Override
    public AuthorizationDecision check(Supplier<Authentication> authentication, RequestAuthorizationContext context) {
        return new AuthorizationDecision(service.checkActive().allowed());
    }
}
