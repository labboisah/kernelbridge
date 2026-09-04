package com.kernelbridge.licensing.web;

import java.lang.reflect.Method;

import org.springframework.core.annotation.AnnotatedElementUtils;
import org.springframework.web.method.HandlerMethod;
import org.springframework.web.servlet.HandlerInterceptor;

import com.kernelbridge.licensing.KernelBridgeLicenseService;
import com.kernelbridge.licensing.annotation.RequiresKernelBridgeFeature;
import com.kernelbridge.licensing.annotation.RequiresKernelBridgeLicense;
import com.kernelbridge.licensing.annotation.RequiresKernelBridgeLimit;

import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;

public class KernelBridgeLicenseInterceptor implements HandlerInterceptor {
    private final KernelBridgeLicenseService service;

    public KernelBridgeLicenseInterceptor(KernelBridgeLicenseService service) {
        this.service = service;
    }

    @Override
    public boolean preHandle(HttpServletRequest request, HttpServletResponse response, Object handler) {
        if (!(handler instanceof HandlerMethod handlerMethod)) {
            return true;
        }
        Method method = handlerMethod.getMethod();
        Class<?> type = handlerMethod.getBeanType();
        if (AnnotatedElementUtils.hasAnnotation(type, RequiresKernelBridgeLicense.class) || AnnotatedElementUtils.hasAnnotation(method, RequiresKernelBridgeLicense.class)) {
            service.assertActive();
        }
        RequiresKernelBridgeFeature feature = findMerged(method, type, RequiresKernelBridgeFeature.class);
        if (feature != null) {
            service.assertFeature(feature.value());
        }
        RequiresKernelBridgeLimit limit = findMerged(method, type, RequiresKernelBridgeLimit.class);
        if (limit != null) {
            service.assertLimit(limit.key(), limit.required());
        }
        return true;
    }

    private static <A extends java.lang.annotation.Annotation> A findMerged(Method method, Class<?> type, Class<A> annotationType) {
        A methodAnnotation = AnnotatedElementUtils.findMergedAnnotation(method, annotationType);
        return methodAnnotation != null ? methodAnnotation : AnnotatedElementUtils.findMergedAnnotation(type, annotationType);
    }
}
