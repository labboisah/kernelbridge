package com.kernelbridge.licensing.state;

import java.time.Instant;
import java.util.HashMap;
import java.util.Map;
import java.util.UUID;

import com.kernelbridge.licensing.dto.LicenseStatus;

public class LicenseState {
    private int version = 1;
    private String productCode;
    private String installationIdentifier = UUID.randomUUID().toString();
    private String licenseIdentifier;
    private String subscriptionIdentifier;
    private String activationIdentifier;
    private LicenseStatus status = LicenseStatus.UNLICENSED;
    private Instant lastSuccessfulVerificationAt;
    private Instant expiresAt;
    private Instant offlineGraceExpiresAt;
    private Map<String, EntitlementSnapshot> entitlements = new HashMap<>();
    private String signedEntitlementPayload;
    private String entitlementSignature;
    private String signatureKeyId;
    private String encryptedLicenseKey;
    private String configFingerprint;
    private Instant configFingerprintedAt;
    private String lastErrorCode;

    public int getVersion() { return version; }
    public void setVersion(int version) { this.version = version; }
    public String getProductCode() { return productCode; }
    public void setProductCode(String productCode) { this.productCode = productCode; }
    public String getInstallationIdentifier() { return installationIdentifier; }
    public void setInstallationIdentifier(String installationIdentifier) { this.installationIdentifier = installationIdentifier; }
    public String getLicenseIdentifier() { return licenseIdentifier; }
    public void setLicenseIdentifier(String licenseIdentifier) { this.licenseIdentifier = licenseIdentifier; }
    public String getSubscriptionIdentifier() { return subscriptionIdentifier; }
    public void setSubscriptionIdentifier(String subscriptionIdentifier) { this.subscriptionIdentifier = subscriptionIdentifier; }
    public String getActivationIdentifier() { return activationIdentifier; }
    public void setActivationIdentifier(String activationIdentifier) { this.activationIdentifier = activationIdentifier; }
    public LicenseStatus getStatus() { return status; }
    public void setStatus(LicenseStatus status) { this.status = status; }
    public Instant getLastSuccessfulVerificationAt() { return lastSuccessfulVerificationAt; }
    public void setLastSuccessfulVerificationAt(Instant lastSuccessfulVerificationAt) { this.lastSuccessfulVerificationAt = lastSuccessfulVerificationAt; }
    public Instant getExpiresAt() { return expiresAt; }
    public void setExpiresAt(Instant expiresAt) { this.expiresAt = expiresAt; }
    public Instant getOfflineGraceExpiresAt() { return offlineGraceExpiresAt; }
    public void setOfflineGraceExpiresAt(Instant offlineGraceExpiresAt) { this.offlineGraceExpiresAt = offlineGraceExpiresAt; }
    public Map<String, EntitlementSnapshot> getEntitlements() { return entitlements; }
    public void setEntitlements(Map<String, EntitlementSnapshot> entitlements) { this.entitlements = entitlements; }
    public String getSignedEntitlementPayload() { return signedEntitlementPayload; }
    public void setSignedEntitlementPayload(String signedEntitlementPayload) { this.signedEntitlementPayload = signedEntitlementPayload; }
    public String getEntitlementSignature() { return entitlementSignature; }
    public void setEntitlementSignature(String entitlementSignature) { this.entitlementSignature = entitlementSignature; }
    public String getSignatureKeyId() { return signatureKeyId; }
    public void setSignatureKeyId(String signatureKeyId) { this.signatureKeyId = signatureKeyId; }
    public String getEncryptedLicenseKey() { return encryptedLicenseKey; }
    public void setEncryptedLicenseKey(String encryptedLicenseKey) { this.encryptedLicenseKey = encryptedLicenseKey; }
    public String getConfigFingerprint() { return configFingerprint; }
    public void setConfigFingerprint(String configFingerprint) { this.configFingerprint = configFingerprint; }
    public Instant getConfigFingerprintedAt() { return configFingerprintedAt; }
    public void setConfigFingerprintedAt(Instant configFingerprintedAt) { this.configFingerprintedAt = configFingerprintedAt; }
    public String getLastErrorCode() { return lastErrorCode; }
    public void setLastErrorCode(String lastErrorCode) { this.lastErrorCode = lastErrorCode; }
}
