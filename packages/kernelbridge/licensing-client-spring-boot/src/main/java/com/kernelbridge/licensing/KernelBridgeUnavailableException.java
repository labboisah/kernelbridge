package com.kernelbridge.licensing;

public class KernelBridgeUnavailableException extends RuntimeException {
    public KernelBridgeUnavailableException(String message, Throwable cause) { super(message, cause); }
    public KernelBridgeUnavailableException(String message) { super(message); }
}
