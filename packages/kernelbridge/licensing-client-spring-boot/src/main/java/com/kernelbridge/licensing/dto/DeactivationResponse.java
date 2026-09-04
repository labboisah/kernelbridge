package com.kernelbridge.licensing.dto;

import com.fasterxml.jackson.annotation.JsonProperty;

public record DeactivationResponse(
    @JsonProperty("license_uuid") String licenseUuid,
    @JsonProperty("activation_uuid") String activationUuid,
    String status
) {}
