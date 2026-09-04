package com.kernelbridge.licensing.state;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.kernelbridge.licensing.KernelBridgeLicensingProperties;
import com.kernelbridge.licensing.crypto.LicenseCryptoService;

public class FileLicenseStateRepository implements LicenseStateRepository {
    private final KernelBridgeLicensingProperties properties;
    private final LicenseCryptoService crypto;
    private final ObjectMapper objectMapper;

    public FileLicenseStateRepository(KernelBridgeLicensingProperties properties, LicenseCryptoService crypto, ObjectMapper objectMapper) {
        this.properties = properties;
        this.crypto = crypto;
        this.objectMapper = objectMapper;
    }

    @Override
    public synchronized LicenseState loadOrCreate(String productCode) {
        Path path = properties.getStatePath();
        if (!Files.exists(path)) {
            LicenseState state = new LicenseState();
            state.setProductCode(productCode);
            save(state);
            return state;
        }
        try {
            EncryptedStateEnvelope envelope = objectMapper.readValue(Files.readString(path), EncryptedStateEnvelope.class);
            if (!productCode.equals(envelope.productCode()) || !crypto.verify(envelope.payload(), envelope.signature())) {
                throw new IllegalStateException("KernelBridge license state signature is invalid.");
            }
            LicenseState state = crypto.decrypt(envelope.nonce(), envelope.payload(), LicenseState.class);
            if (state.getProductCode() == null) {
                state.setProductCode(productCode);
            }
            return state;
        } catch (IOException exception) {
            throw new IllegalStateException("Could not read KernelBridge license state.", exception);
        }
    }

    @Override
    public synchronized void save(LicenseState state) {
        try {
            Path path = properties.getStatePath();
            if (path.getParent() != null) {
                Files.createDirectories(path.getParent());
            }
            LicenseCryptoService.EncryptedPayload encrypted = crypto.encrypt(state);
            EncryptedStateEnvelope envelope = new EncryptedStateEnvelope(1, state.getProductCode(), encrypted.nonce(), encrypted.payload(), crypto.sign(encrypted.payload()));
            Files.writeString(path, objectMapper.writerWithDefaultPrettyPrinter().writeValueAsString(envelope));
        } catch (IOException exception) {
            throw new IllegalStateException("Could not write KernelBridge license state.", exception);
        }
    }

    @Override
    public synchronized void delete(String productCode) {
        try {
            Files.deleteIfExists(properties.getStatePath());
        } catch (IOException exception) {
            throw new IllegalStateException("Could not remove KernelBridge license state.", exception);
        }
    }
}
