package com.kernelbridge.licensing.crypto;

import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.util.Base64;

import javax.crypto.Cipher;
import javax.crypto.Mac;
import javax.crypto.spec.GCMParameterSpec;
import javax.crypto.spec.SecretKeySpec;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.kernelbridge.licensing.KernelBridgeLicensingProperties;

public class LicenseCryptoService {
    private static final int GCM_TAG_BITS = 128;
    private static final int NONCE_BYTES = 12;
    private final KernelBridgeLicensingProperties properties;
    private final ObjectMapper objectMapper;
    private final SecureRandom secureRandom = new SecureRandom();

    public LicenseCryptoService(KernelBridgeLicensingProperties properties, ObjectMapper objectMapper) {
        this.properties = properties;
        this.objectMapper = objectMapper.copy().configure(com.fasterxml.jackson.databind.SerializationFeature.ORDER_MAP_ENTRIES_BY_KEYS, true);
    }

    public EncryptedPayload encrypt(Object value) {
        try {
            byte[] nonce = new byte[NONCE_BYTES];
            secureRandom.nextBytes(nonce);
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.ENCRYPT_MODE, aesKey(), new GCMParameterSpec(GCM_TAG_BITS, nonce));
            byte[] plaintext = objectMapper.writeValueAsBytes(value);
            byte[] encrypted = cipher.doFinal(plaintext);
            return new EncryptedPayload(b64(nonce), b64(encrypted));
        } catch (GeneralSecurityException | JsonProcessingException exception) {
            throw new IllegalStateException("Could not encrypt KernelBridge license state.", exception);
        }
    }

    public <T> T decrypt(String nonce, String payload, Class<T> type) {
        try {
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.DECRYPT_MODE, aesKey(), new GCMParameterSpec(GCM_TAG_BITS, Base64.getDecoder().decode(nonce)));
            byte[] plaintext = cipher.doFinal(Base64.getDecoder().decode(payload));
            return objectMapper.readValue(plaintext, type);
        } catch (Exception exception) {
            throw new IllegalStateException("KernelBridge license state could not be decrypted.", exception);
        }
    }

    public String sign(Object value) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(properties.signingKey().getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            return b64(mac.doFinal(objectMapper.writeValueAsBytes(value)));
        } catch (GeneralSecurityException | JsonProcessingException exception) {
            throw new IllegalStateException("Could not sign KernelBridge license state.", exception);
        }
    }

    public boolean verify(Object value, String signature) {
        return MessageDigest.isEqual(sign(value).getBytes(StandardCharsets.UTF_8), signature.getBytes(StandardCharsets.UTF_8));
    }

    public String configFingerprint() {
        return sign(new ConfigFingerprint(
            properties.getApiUrl(),
            properties.normalizedProductCode(),
            sha256(properties.getApiToken()),
            sha256(properties.encryptionKey()),
            sha256(properties.signingKey())
        ));
    }

    public String encryptText(String value) {
        return encrypt(new PlainText(value)).payloadWithNonce();
    }

    public String decryptText(String encrypted) {
        String[] parts = encrypted.split(":", 2);
        if (parts.length != 2) {
            throw new IllegalStateException("Encrypted KernelBridge text is malformed.");
        }
        return decrypt(parts[0], parts[1], PlainText.class).value();
    }

    private SecretKeySpec aesKey() {
        return new SecretKeySpec(sha256Bytes(properties.encryptionKey()), "AES");
    }

    private static String sha256(String value) {
        if (value == null) {
            return null;
        }
        return b64(sha256Bytes(value));
    }

    private static byte[] sha256Bytes(String value) {
        try {
            return MessageDigest.getInstance("SHA-256").digest(value.getBytes(StandardCharsets.UTF_8));
        } catch (GeneralSecurityException exception) {
            throw new IllegalStateException("SHA-256 is unavailable.", exception);
        }
    }

    private static String b64(byte[] value) {
        return Base64.getEncoder().encodeToString(value);
    }

    public record EncryptedPayload(String nonce, String payload) {
        public String payloadWithNonce() { return nonce + ":" + payload; }
    }

    private record PlainText(String value) {}
    private record ConfigFingerprint(String apiUrl, String productCode, String apiTokenHash, String encryptionKeyHash, String signingKeyHash) {}
}

