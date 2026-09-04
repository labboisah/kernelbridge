package com.kernelbridge.licensing;

public class KernelBridgeApiException extends RuntimeException {
    private final String code;
    private final int statusCode;

    public KernelBridgeApiException(String message, String code, int statusCode) {
        super(message);
        this.code = code;
        this.statusCode = statusCode;
    }

    public String getCode() { return code; }
    public int getStatusCode() { return statusCode; }
}
