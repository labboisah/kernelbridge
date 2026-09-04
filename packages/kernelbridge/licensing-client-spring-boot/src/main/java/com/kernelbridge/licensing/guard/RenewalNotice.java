package com.kernelbridge.licensing.guard;

import java.time.Instant;

public record RenewalNotice(String level, String message, Instant expiresAt) {}
