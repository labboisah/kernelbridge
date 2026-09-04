package com.kernelbridge.licensing.dto;

import com.fasterxml.jackson.annotation.JsonProperty;

public record LicenseResponse(
    @JsonProperty("license_uuid") String licenseUuid,
    @JsonProperty("activation_uuid") String activationUuid,
    @JsonProperty("subscription_uuid") String subscriptionUuid,
    String status,
    @JsonProperty("expires_at") String expiresAt
) {}
