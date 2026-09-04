package com.kernelbridge.licensing.guard;

public record LicenseAccessDecision(boolean allowed, String reason, String message) {}
