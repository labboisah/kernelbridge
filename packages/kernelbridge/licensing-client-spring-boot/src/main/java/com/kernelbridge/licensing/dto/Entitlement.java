package com.kernelbridge.licensing.dto;

public record Entitlement(String key, boolean enabled, Object limit) {}
