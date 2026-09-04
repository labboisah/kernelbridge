package com.kernelbridge.licensing;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import java.nio.file.Files;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.List;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.springframework.web.client.RestClient;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.SerializationFeature;
import com.fasterxml.jackson.datatype.jsr310.JavaTimeModule;
import com.kernelbridge.licensing.client.KernelBridgeLicenseClient;
import com.kernelbridge.licensing.crypto.LicenseCryptoService;
import com.kernelbridge.licensing.dto.Entitlement;
import com.kernelbridge.licensing.dto.EntitlementResponse;
import com.kernelbridge.licensing.dto.LicenseResponse;
import com.kernelbridge.licensing.dto.SubscriptionResponse;
import com.kernelbridge.licensing.state.FileLicenseStateRepository;

class KernelBridgeLicenseServiceTest {
    @TempDir
    java.nio.file.Path tempDir;

    @Test
    void activationStoresEncryptedStateAndUsesCachedEntitlements() throws Exception {
        KernelBridgeLicenseService service = service(Instant.parse("2026-01-01T00:00:00Z"));

        service.activate("KB-123", "Test Device");

        String persisted = Files.readString(tempDir.resolve("license.json"));
        assertThat(persisted).doesNotContain("KB-123");
        assertThat(service.checkActive().allowed()).isTrue();
        assertThat(service.hasFeature("reports")).isTrue();
        assertThat(service.withinLimit("users", 5)).isTrue();
        assertThat(service.withinLimit("users", 15)).isFalse();
    }

    @Test
    void configurationTamperRequiresReactivation() {
        KernelBridgeLicenseService service = service(Instant.parse("2026-01-01T00:00:00Z"));
        service.activate("KB-123", null);

        KernelBridgeLicensingProperties changed = properties();
        changed.setApiUrl("https://evil.example/api/v1");
        KernelBridgeLicenseService changedService = service(changed, Instant.parse("2026-01-01T00:01:00Z"));

        assertThat(changedService.checkActive().allowed()).isFalse();
        assertThat(changedService.checkActive().reason()).isEqualTo("configuration_tampered");
    }

    @Test
    void renewalNoticeWarnsAtConfiguredMonthWindow() {
        KernelBridgeLicenseService service = service(Instant.parse("2026-01-01T00:00:00Z"));
        service.activate("KB-123", null);

        assertThat(service.renewalNotice()).isPresent();
        assertThat(service.renewalNotice().orElseThrow().level()).isEqualTo("3_months");
    }

    @Test
    void activationLimitErrorUsesKernelBridgeDeviceLimitMessage() {
        KernelBridgeLicenseService service = service(properties(), Instant.parse("2026-01-01T00:00:00Z"), true);

        assertThatThrownBy(() -> service.activate("KB-123", null))
            .isInstanceOf(KernelBridgeApiException.class)
            .hasMessageContaining("device activation limit");
    }

    private KernelBridgeLicenseService service(Instant now) {
        return service(properties(), now);
    }

    private KernelBridgeLicenseService service(KernelBridgeLicensingProperties properties, Instant now) {
        return service(properties, now, false);
    }

    private KernelBridgeLicenseService service(KernelBridgeLicensingProperties properties, Instant now, boolean failActivationLimit) {
        ObjectMapper mapper = new ObjectMapper().registerModule(new JavaTimeModule()).disable(SerializationFeature.WRITE_DATES_AS_TIMESTAMPS).configure(SerializationFeature.ORDER_MAP_ENTRIES_BY_KEYS, true);
        LicenseCryptoService crypto = new LicenseCryptoService(properties, mapper);
        FileLicenseStateRepository repository = new FileLicenseStateRepository(properties, crypto, mapper);
        return new KernelBridgeLicenseService(properties, new FakeClient(properties, failActivationLimit), repository, crypto, Clock.fixed(now, ZoneOffset.UTC));
    }

    private KernelBridgeLicensingProperties properties() {
        KernelBridgeLicensingProperties properties = new KernelBridgeLicensingProperties();
        properties.setApiUrl("https://kernelbridge.test/api/v1");
        properties.setProductCode("ALIGNEX");
        properties.setApiToken("token");
        properties.setCacheSigningKey("0123456789abcdef0123456789abcdef");
        properties.setEncryptionKey("abcdef0123456789abcdef0123456789");
        properties.setOfflineGrace(Duration.ofHours(72));
        properties.setStatePath(tempDir.resolve("license.json"));
        return properties;
    }

    private static class FakeClient extends KernelBridgeLicenseClient {
        private final boolean failActivationLimit;

        FakeClient(KernelBridgeLicensingProperties properties, boolean failActivationLimit) {
            super(properties, RestClient.builder());
            this.failActivationLimit = failActivationLimit;
        }

        @Override
        public LicenseResponse activate(String licenseKey, String installationIdentifier, String deviceName) {
            if (failActivationLimit) {
                throw new KernelBridgeApiException("limit", "activation_limit_reached", 422);
            }
            return new LicenseResponse("lic-1", "act-1", "sub-1", "active", "2026-03-20T00:00:00Z");
        }

        @Override
        public LicenseResponse verify(String licenseKey, String installationIdentifier) {
            return activate(licenseKey, installationIdentifier, null);
        }

        @Override
        public SubscriptionResponse getSubscription(String subscriptionIdentifier) {
            return new SubscriptionResponse(subscriptionIdentifier, "active", "2025-12-01T00:00:00Z", "2026-03-20T00:00:00Z", null);
        }

        @Override
        public EntitlementResponse getEntitlements(String subscriptionIdentifier) {
            return new EntitlementResponse(List.of(
                new Entitlement("reports", true, null),
                new Entitlement("users", true, 10)
            ), null, null, null, "test");
        }
    }
}

