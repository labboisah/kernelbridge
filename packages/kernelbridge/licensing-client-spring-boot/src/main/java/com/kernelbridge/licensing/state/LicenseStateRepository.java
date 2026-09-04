package com.kernelbridge.licensing.state;

public interface LicenseStateRepository {
    LicenseState loadOrCreate(String productCode);
    void save(LicenseState state);
    void delete(String productCode);
}
