package com.kernelbridge.licensing.dto;

import com.fasterxml.jackson.annotation.JsonProperty;

public record SubscriptionResponse(
    String uuid,
    String status,
    @JsonProperty("starts_at") String startsAt,
    @JsonProperty("ends_at") String endsAt,
    @JsonProperty("grace_ends_at") String graceEndsAt
) {}
