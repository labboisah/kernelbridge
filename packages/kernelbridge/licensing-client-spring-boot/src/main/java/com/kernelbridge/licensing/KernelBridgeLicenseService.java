package com.kernelbridge.licensing;

import java.time.Clock;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.HashMap;
import java.util.Map;
import java.util.Optional;

import com.kernelbridge.licensing.client.KernelBridgeLicenseClient;
import com.kernelbridge.licensing.crypto.LicenseCryptoService;
import com.kernelbridge.licensing.dto.Entitlement;
import com.kernelbridge.licensing.dto.EntitlementResponse;
import com.kernelbridge.licensing.dto.LicenseResponse;
import com.kernelbridge.licensing.dto.LicenseStatus;
import com.kernelbridge.licensing.dto.SubscriptionResponse;
import com.kernelbridge.licensing.guard.LicenseAccessDecision;
import com.kernelbridge.licensing.guard.RenewalNotice;
import com.kernelbridge.licensing.state.EntitlementSnapshot;
import com.kernelbridge.licensing.state.LicenseState;
import com.kernelbridge.licensing.state.LicenseStateRepository;

public class KernelBridgeLicenseService {
    private static final String ACTIVATION_LIMIT_MESSAGE = "This license has reached its device activation limit. Deactivate another installation in KernelBridge before trying again.";
    private final KernelBridgeLicensingProperties properties;
    private final KernelBridgeLicenseClient client;
    private final LicenseStateRepository repository;
    private final LicenseCryptoService crypto;
    private final Clock clock;

    public KernelBridgeLicenseService(KernelBridgeLicensingProperties properties, KernelBridgeLicenseClient client, LicenseStateRepository repository, LicenseCryptoService crypto, Clock clock) {
        this.properties = properties;
        this.client = client;
        this.repository = repository;
        this.crypto = crypto;
        this.clock = clock;
    }

    public LicenseState state() {
        return repository.loadOrCreate(properties.normalizedProductCode());
    }

    public LicenseState activate(String licenseKey, String deviceName) {
        LicenseState state = state();
        try {
            LicenseResponse license = client.activate(licenseKey, state.getInstallationIdentifier(), deviceName);
            SubscriptionResponse subscription = client.getSubscription(license.subscriptionUuid());
            EntitlementResponse entitlements = client.getEntitlements(license.subscriptionUuid());
            return storeVerified(state, licenseKey, license, subscription, entitlements);
        } catch (KernelBridgeApiException exception) {
            if ("activation_limit_reached".equals(exception.getCode())) {
                throw new KernelBridgeApiException(ACTIVATION_LIMIT_MESSAGE, exception.getCode(), exception.getStatusCode());
            }
            markRejected(exception.getCode());
            throw exception;
        }
    }

    public LicenseState verify(boolean force) {
        LicenseState state = state();
        if (!force && state.getLastSuccessfulVerificationAt() != null && state.getLastSuccessfulVerificationAt().plus(properties.getVerificationInterval()).isAfter(now())) {
            return state;
        }
        if (state.getEncryptedLicenseKey() == null) {
            return state;
        }
        try {
            String licenseKey = crypto.decryptText(state.getEncryptedLicenseKey());
            LicenseResponse license = client.verify(licenseKey, state.getInstallationIdentifier());
            SubscriptionResponse subscription = client.getSubscription(license.subscriptionUuid());
            EntitlementResponse entitlements = client.getEntitlements(license.subscriptionUuid());
            return storeVerified(state, licenseKey, license, subscription, entitlements);
        } catch (KernelBridgeApiException exception) {
            markRejected(exception.getCode());
            throw exception;
        } catch (KernelBridgeUnavailableException exception) {
            if (hasUsableLicense()) {
                return state();
            }
            throw exception;
        }
    }

    public void deactivate() {
        LicenseState state = state();
        if (state.getEncryptedLicenseKey() != null) {
            client.deactivate(crypto.decryptText(state.getEncryptedLicenseKey()), state.getInstallationIdentifier());
        }
        state.setStatus(LicenseStatus.DEACTIVATED);
        state.setOfflineGraceExpiresAt(now());
        state.setLastErrorCode(null);
        repository.save(state);
    }

    public LicenseAccessDecision checkActive() {
        if (hasUsableLicense()) {
            return new LicenseAccessDecision(true, "license_active", "KernelBridge license is active.");
        }
        String reason = activationRequirementReason();
        return new LicenseAccessDecision(false, reason, activationRequirementMessage(reason));
    }

    public boolean hasUsableLicense() {
        try {
            LicenseState state = state();
            return state.getStatus() != null
                && state.getStatus().usable()
                && state.getLastSuccessfulVerificationAt() != null
                && (state.getExpiresAt() == null || state.getExpiresAt().isAfter(now()))
                && state.getOfflineGraceExpiresAt() != null
                && state.getOfflineGraceExpiresAt().isAfter(now())
                && configFingerprintIsValid(state)
                && entitlementSignatureIsValid(state);
        } catch (RuntimeException exception) {
            return false;
        }
    }

    public boolean hasFeature(String key) {
        return hasUsableLicense() && Optional.ofNullable(state().getEntitlements().get(key)).map(EntitlementSnapshot::enabled).orElse(false);
    }

    public boolean withinLimit(String key, Number required) {
        if (!hasFeature(key)) {
            return false;
        }
        Object limit = state().getEntitlements().get(key).limit();
        if (limit == null || "unlimited".equals(String.valueOf(limit))) {
            return true;
        }
        return Double.parseDouble(String.valueOf(limit)) >= required.doubleValue();
    }

    public Optional<RenewalNotice> renewalNotice() {
        if (!properties.getRenewalNotice().isEnabled() || !hasUsableLicense() || state().getExpiresAt() == null || !state().getExpiresAt().isAfter(now())) {
            return Optional.empty();
        }
        Instant expiresAt = state().getExpiresAt();
        LocalDate today = LocalDate.ofInstant(now(), ZoneId.systemDefault());
        LocalDate expiry = LocalDate.ofInstant(expiresAt, ZoneId.systemDefault());
        for (Integer months : properties.getRenewalNotice().getMonths()) {
            if (months != null && today.plusMonths(months).compareTo(expiry) >= 0) {
                String level = months == 1 ? "1_month" : months + "_months";
                String monthText = months == 1 ? "1 month" : months + " months";
                String message = "Your KernelBridge license expires in about " + monthText + " on " + expiry.format(DateTimeFormatter.ISO_DATE) + ". Renew to avoid service interruption.";
                return Optional.of(new RenewalNotice(level, message, expiresAt));
            }
        }
        return Optional.empty();
    }

    public void assertActive() {
        LicenseAccessDecision decision = checkActive();
        if (!decision.allowed()) {
            throw new KernelBridgeApiException(decision.message(), decision.reason(), 402);
        }
    }

    public void assertFeature(String feature) {
        assertActive();
        if (!hasFeature(feature)) {
            throw new KernelBridgeApiException("The '" + feature + "' feature is not included in this plan.", "feature_unavailable", 402);
        }
    }

    public void assertLimit(String feature, Number required) {
        assertActive();
        if (!withinLimit(feature, required)) {
            throw new KernelBridgeApiException("The '" + feature + "' plan limit is insufficient.", "limit_exceeded", 402);
        }
    }

    public String activationRequirementReason() {
        try {
            LicenseState state = state();
            if (state.getEncryptedLicenseKey() == null) return "license_not_activated";
            if (!configFingerprintIsValid(state)) return "configuration_tampered";
            if (!entitlementSignatureIsValid(state)) return "local_license_tampered";
            if (state.getStatus() == LicenseStatus.DEACTIVATED) return "license_deactivated";
            if (state.getStatus() == LicenseStatus.INVALID) return state.getLastErrorCode() == null ? "license_invalid" : state.getLastErrorCode();
            if (state.getExpiresAt() != null && state.getExpiresAt().isBefore(now())) return "license_expired";
            if (state.getOfflineGraceExpiresAt() == null || state.getOfflineGraceExpiresAt().isBefore(now())) return "offline_grace_expired";
            if (state.getStatus() == null || !state.getStatus().usable()) return "license_inactive";
            return "license_required";
        } catch (IllegalStateException exception) {
            return "local_license_tampered";
        }
    }

    public String activationRequirementMessage(String reason) {
        return switch (reason) {
            case "activation_limit_reached" -> ACTIVATION_LIMIT_MESSAGE;
            case "configuration_tampered" -> "KernelBridge licensing configuration changed. Re-activation is required.";
            case "license_deactivated" -> "This installation has been deactivated. Re-activation is required.";
            case "license_expired" -> "This license has expired. Renew or activate a valid license.";
            case "license_not_activated" -> "Activate this application before continuing.";
            case "license_revoked" -> "This license was revoked by KernelBridge. Activate a valid license.";
            case "local_license_tampered" -> "The local license cache was changed or cannot be trusted. Re-activation is required.";
            case "offline_grace_expired" -> "Offline grace has expired. Reconnect to KernelBridge and verify the license.";
            default -> "An active KernelBridge subscription is required.";
        };
    }

    private LicenseState storeVerified(LicenseState state, String licenseKey, LicenseResponse license, SubscriptionResponse subscription, EntitlementResponse entitlements) {
        Instant verifiedAt = now();
        Instant expiresAt = parseFirst(license.expiresAt(), subscription.graceEndsAt(), subscription.endsAt());
        Instant offlineGraceExpiresAt = verifiedAt.plus(properties.getOfflineGrace());
        if (expiresAt != null && offlineGraceExpiresAt.isAfter(expiresAt)) {
            offlineGraceExpiresAt = expiresAt;
        }
        Map<String, EntitlementSnapshot> snapshot = new HashMap<>();
        if (entitlements.entitlements() != null) {
            for (Entitlement entitlement : entitlements.entitlements()) {
                snapshot.put(entitlement.key(), new EntitlementSnapshot(entitlement.enabled(), entitlement.limit()));
            }
        }
        state.setLicenseIdentifier(license.licenseUuid());
        state.setSubscriptionIdentifier(license.subscriptionUuid());
        state.setActivationIdentifier(license.activationUuid() == null ? state.getActivationIdentifier() : license.activationUuid());
        state.setEncryptedLicenseKey(crypto.encryptText(licenseKey));
        state.setStatus(LicenseStatus.fromWire(subscription.status() == null ? license.status() : subscription.status()));
        state.setLastSuccessfulVerificationAt(verifiedAt);
        state.setExpiresAt(expiresAt);
        state.setOfflineGraceExpiresAt(offlineGraceExpiresAt);
        state.setEntitlements(snapshot);
        state.setSignedEntitlementPayload(entitlements.signedPayload());
        state.setEntitlementSignature(crypto.sign(snapshot));
        state.setSignatureKeyId(entitlements.keyId());
        state.setConfigFingerprint(crypto.configFingerprint());
        state.setConfigFingerprintedAt(verifiedAt);
        state.setLastErrorCode(null);
        repository.save(state);
        return state;
    }

    private boolean entitlementSignatureIsValid(LicenseState state) {
        return state.getEntitlementSignature() != null && crypto.verify(state.getEntitlements(), state.getEntitlementSignature());
    }

    private boolean configFingerprintIsValid(LicenseState state) {
        return state.getEncryptedLicenseKey() == null || (state.getConfigFingerprint() != null && crypto.configFingerprint().equals(state.getConfigFingerprint()));
    }

    private void markRejected(String code) {
        LicenseState state = state();
        state.setStatus(LicenseStatus.INVALID);
        state.setLastErrorCode(code);
        repository.save(state);
    }

    private Instant now() {
        return clock.instant();
    }

    private static Instant parseFirst(String... values) {
        for (String value : values) {
            if (value != null && !value.isBlank()) {
                return Instant.parse(value);
            }
        }
        return null;
    }
}

