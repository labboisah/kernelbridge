package com.kernelbridge.licensing.dto;

import java.util.List;

import com.fasterxml.jackson.annotation.JsonProperty;

public record EntitlementResponse(
    List<Entitlement> entitlements,
    @JsonProperty("signed_payload") String signedPayload,
    String signature,
    @JsonProperty("signature_algorithm") String signatureAlgorithm,
    @JsonProperty("key_id") String keyId
) {}
