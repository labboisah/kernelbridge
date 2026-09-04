package com.kernelbridge.licensing.web;

import java.io.IOException;

import org.springframework.http.MediaType;
import org.springframework.util.AntPathMatcher;
import org.springframework.web.filter.OncePerRequestFilter;

import com.kernelbridge.licensing.KernelBridgeLicensingProperties;
import com.kernelbridge.licensing.KernelBridgeLicenseService;
import com.kernelbridge.licensing.guard.LicenseAccessDecision;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;

public class KernelBridgeLicenseFilter extends OncePerRequestFilter {
    private final KernelBridgeLicensingProperties properties;
    private final KernelBridgeLicenseService service;
    private final AntPathMatcher matcher = new AntPathMatcher();

    public KernelBridgeLicenseFilter(KernelBridgeLicensingProperties properties, KernelBridgeLicenseService service) {
        this.properties = properties;
        this.service = service;
    }

    @Override
    protected boolean shouldNotFilter(HttpServletRequest request) {
        if (!properties.isProtectWebRoutes()) {
            return true;
        }
        String path = request.getRequestURI();
        if (path.startsWith(properties.getActivationPath())) {
            return true;
        }
        return properties.getProtectExcept().stream().anyMatch(pattern -> matcher.match(pattern, path));
    }

    @Override
    protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain filterChain) throws ServletException, IOException {
        LicenseAccessDecision decision = service.checkActive();
        if (decision.allowed()) {
            filterChain.doFilter(request, response);
            return;
        }
        if (expectsJson(request)) {
            response.setStatus(402);
            response.setContentType(MediaType.APPLICATION_JSON_VALUE);
            response.getWriter().write("{\"error\":\"license_required\",\"reason\":\"" + escape(decision.reason()) + "\",\"message\":\"" + escape(decision.message()) + "\"}");
            return;
        }
        response.sendRedirect(properties.getActivationPath());
    }

    private static boolean expectsJson(HttpServletRequest request) {
        String accept = request.getHeader("Accept");
        String requestedWith = request.getHeader("X-Requested-With");
        return (accept != null && accept.contains("application/json")) || "XMLHttpRequest".equalsIgnoreCase(requestedWith) || request.getRequestURI().startsWith("/api/");
    }

    private static String escape(String value) {
        return value.replace("\\", "\\\\").replace("\"", "\\\"");
    }
}
