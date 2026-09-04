package com.kernelbridge.licensing.client;

import java.net.URI;
import java.util.Map;

import org.springframework.core.ParameterizedTypeReference;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientResponseException;
import org.springframework.web.util.UriComponentsBuilder;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.kernelbridge.licensing.KernelBridgeApiException;
import com.kernelbridge.licensing.KernelBridgeLicensingProperties;
import com.kernelbridge.licensing.KernelBridgeUnavailableException;
import com.kernelbridge.licensing.dto.DeactivationResponse;
import com.kernelbridge.licensing.dto.EntitlementResponse;
import com.kernelbridge.licensing.dto.KernelBridgeEnvelope;
import com.kernelbridge.licensing.dto.LicenseResponse;
import com.kernelbridge.licensing.dto.SubscriptionResponse;

public class KernelBridgeLicenseClient {
    private final KernelBridgeLicensingProperties properties;
    private final RestClient restClient;
    private final ObjectMapper objectMapper = new ObjectMapper();

    public KernelBridgeLicenseClient(KernelBridgeLicensingProperties properties, RestClient.Builder builder) {
        this.properties = properties;
        this.restClient = builder.build();
    }

    public LicenseResponse activate(String licenseKey, String installationIdentifier, String deviceName) {
        return post("/licenses/activate", Map.of("license_key", licenseKey, "device_id", installationIdentifier, "device_name", deviceName == null ? "" : deviceName), new ParameterizedTypeReference<>() {});
    }

    public LicenseResponse verify(String licenseKey, String installationIdentifier) {
        return post("/licenses/verify", Map.of("license_key", licenseKey, "device_id", installationIdentifier), new ParameterizedTypeReference<>() {});
    }

    public DeactivationResponse deactivate(String licenseKey, String installationIdentifier) {
        return post("/licenses/deactivate", Map.of("license_key", licenseKey, "device_id", installationIdentifier), new ParameterizedTypeReference<>() {});
    }

    public SubscriptionResponse getSubscription(String subscriptionIdentifier) {
        return get("/subscriptions/status", Map.of("subscription_uuid", subscriptionIdentifier), new ParameterizedTypeReference<>() {});
    }

    public EntitlementResponse getEntitlements(String subscriptionIdentifier) {
        return get("/entitlements", Map.of("subscription_uuid", subscriptionIdentifier), new ParameterizedTypeReference<>() {});
    }

    private <T> T post(String path, Map<String, String> body, ParameterizedTypeReference<KernelBridgeEnvelope<T>> type) {
        try {
            KernelBridgeEnvelope<T> envelope = restClient.post().uri(apiUri(path)).headers(this::headers).body(body).retrieve().body(type);
            return unwrap(envelope, 200);
        } catch (RestClientResponseException exception) {
            throw kernelBridgeException(exception);
        } catch (KernelBridgeApiException | KernelBridgeUnavailableException exception) {
            throw exception;
        } catch (RuntimeException exception) {
            throw new KernelBridgeUnavailableException("KernelBridge licensing request failed.", exception);
        }
    }

    private <T> T get(String path, Map<String, String> query, ParameterizedTypeReference<KernelBridgeEnvelope<T>> type) {
        try {
            UriComponentsBuilder builder = UriComponentsBuilder.fromUri(apiUri(path));
            query.forEach(builder::queryParam);
            URI uri = builder.build(true).toUri();
            KernelBridgeEnvelope<T> envelope = restClient.get().uri(uri).headers(this::headers).retrieve().body(type);
            return unwrap(envelope, 200);
        } catch (RestClientResponseException exception) {
            throw kernelBridgeException(exception);
        } catch (KernelBridgeApiException | KernelBridgeUnavailableException exception) {
            throw exception;
        } catch (RuntimeException exception) {
            throw new KernelBridgeUnavailableException("KernelBridge licensing request failed.", exception);
        }
    }

    private RuntimeException kernelBridgeException(RestClientResponseException exception) {
        if (exception.getStatusCode().is5xxServerError()) {
            return new KernelBridgeUnavailableException("KernelBridge is temporarily unavailable.", exception);
        }
        try {
            JsonNode root = objectMapper.readTree(exception.getResponseBodyAsString());
            JsonNode error = root.path("error");
            String code = error.path("code").asText("license_rejected");
            String message = error.path("message").asText("KernelBridge rejected the licensing request.");
            return new KernelBridgeApiException(message, code, exception.getStatusCode().value());
        } catch (Exception parseException) {
            return new KernelBridgeApiException("KernelBridge rejected the licensing request.", "license_rejected", exception.getStatusCode().value());
        }
    }

    private URI apiUri(String path) {
        return URI.create(properties.getApiUrl().replaceAll("/+$", "") + path);
    }

    private void headers(org.springframework.http.HttpHeaders headers) {
        headers.set("Accept", "application/json");
        headers.set("Content-Type", "application/json");
        headers.setBearerAuth(properties.getApiToken());
        headers.set("X-KernelBridge-Product", properties.normalizedProductCode());
    }

    private static <T> T unwrap(KernelBridgeEnvelope<T> envelope, int statusCode) {
        if (envelope == null || !envelope.success() || envelope.data() == null) {
            String code = envelope != null && envelope.error() != null && envelope.error().code() != null ? envelope.error().code() : "license_rejected";
            String message = envelope != null && envelope.error() != null && envelope.error().message() != null ? envelope.error().message() : "KernelBridge rejected the licensing request.";
            throw new KernelBridgeApiException(message, code, statusCode);
        }
        return envelope.data();
    }
}
