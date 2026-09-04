package com.kernelbridge.licensing.state;

public record EncryptedStateEnvelope(int version, String productCode, String nonce, String payload, String signature) {}
