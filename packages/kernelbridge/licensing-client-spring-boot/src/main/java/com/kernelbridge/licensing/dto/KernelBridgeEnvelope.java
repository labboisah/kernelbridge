package com.kernelbridge.licensing.dto;

public record KernelBridgeEnvelope<T>(boolean success, T data, KernelBridgeError error) {}
