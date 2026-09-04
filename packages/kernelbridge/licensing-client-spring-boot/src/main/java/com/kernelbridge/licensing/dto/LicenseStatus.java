package com.kernelbridge.licensing.dto;

public enum LicenseStatus {
    UNLICENSED,
    PENDING,
    TRIAL,
    ACTIVE,
    GRACE,
    EXPIRED,
    SUSPENDED,
    CANCELLED,
    INVALID,
    DEACTIVATED;

    public boolean usable() {
        return this == ACTIVE || this == TRIAL || this == GRACE;
    }

    public static LicenseStatus fromWire(String value) {
        if (value == null || value.isBlank()) {
            return UNLICENSED;
        }
        return LicenseStatus.valueOf(value.trim().toUpperCase());
    }

    public String toWire() {
        return name().toLowerCase();
    }
}
