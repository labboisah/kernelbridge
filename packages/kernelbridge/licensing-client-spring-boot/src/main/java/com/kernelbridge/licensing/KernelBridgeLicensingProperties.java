package com.kernelbridge.licensing;

import java.nio.file.Path;
import java.time.Duration;
import java.util.ArrayList;
import java.util.List;

import org.springframework.boot.context.properties.ConfigurationProperties;

@ConfigurationProperties(prefix = "kernelbridge.licensing")
public class KernelBridgeLicensingProperties {
    private String apiUrl = "https://kernelbridge.com/api/v1";
    private String productCode;
    private String apiToken;
    private String cacheSigningKey;
    private String encryptionKey;
    private Duration timeout = Duration.ofSeconds(10);
    private Duration verificationInterval = Duration.ofMinutes(15);
    private Duration offlineGrace = Duration.ofHours(72);
    private Path statePath = Path.of(System.getProperty("user.home"), ".kernelbridge", "licensing-state.json");
    private boolean protectWebRoutes = false;
    private String activationPath = "/license";
    private List<String> protectExcept = new ArrayList<>(List.of("/license/**", "/login", "/error", "/actuator/health"));
    private RenewalNotice renewalNotice = new RenewalNotice();

    public String getApiUrl() { return apiUrl; }
    public void setApiUrl(String apiUrl) { this.apiUrl = apiUrl; }
    public String getProductCode() { return productCode; }
    public void setProductCode(String productCode) { this.productCode = productCode; }
    public String getApiToken() { return apiToken; }
    public void setApiToken(String apiToken) { this.apiToken = apiToken; }
    public String getCacheSigningKey() { return cacheSigningKey; }
    public void setCacheSigningKey(String cacheSigningKey) { this.cacheSigningKey = cacheSigningKey; }
    public String getEncryptionKey() { return encryptionKey; }
    public void setEncryptionKey(String encryptionKey) { this.encryptionKey = encryptionKey; }
    public Duration getTimeout() { return timeout; }
    public void setTimeout(Duration timeout) { this.timeout = timeout; }
    public Duration getVerificationInterval() { return verificationInterval; }
    public void setVerificationInterval(Duration verificationInterval) { this.verificationInterval = verificationInterval; }
    public Duration getOfflineGrace() { return offlineGrace; }
    public void setOfflineGrace(Duration offlineGrace) { this.offlineGrace = offlineGrace; }
    public Path getStatePath() { return statePath; }
    public void setStatePath(Path statePath) { this.statePath = statePath; }
    public boolean isProtectWebRoutes() { return protectWebRoutes; }
    public void setProtectWebRoutes(boolean protectWebRoutes) { this.protectWebRoutes = protectWebRoutes; }
    public String getActivationPath() { return activationPath; }
    public void setActivationPath(String activationPath) { this.activationPath = activationPath; }
    public List<String> getProtectExcept() { return protectExcept; }
    public void setProtectExcept(List<String> protectExcept) { this.protectExcept = protectExcept; }
    public RenewalNotice getRenewalNotice() { return renewalNotice; }
    public void setRenewalNotice(RenewalNotice renewalNotice) { this.renewalNotice = renewalNotice; }

    public String normalizedProductCode() {
        if (productCode == null || productCode.isBlank()) {
            throw new IllegalStateException("kernelbridge.licensing.product-code is required.");
        }
        return productCode.trim().toUpperCase();
    }

    public String signingKey() {
        if (cacheSigningKey == null || cacheSigningKey.isBlank()) {
            throw new IllegalStateException("kernelbridge.licensing.cache-signing-key is required.");
        }
        return cacheSigningKey;
    }

    public String encryptionKey() {
        if (encryptionKey != null && !encryptionKey.isBlank()) {
            return encryptionKey;
        }
        return signingKey();
    }

    public static class RenewalNotice {
        private boolean enabled = true;
        private List<Integer> months = new ArrayList<>(List.of(3, 2, 1));

        public boolean isEnabled() { return enabled; }
        public void setEnabled(boolean enabled) { this.enabled = enabled; }
        public List<Integer> getMonths() { return months; }
        public void setMonths(List<Integer> months) { this.months = months; }
    }
}
