package com.kernelbridge.licensing.autoconfigure;

import java.time.Clock;

import org.springframework.boot.autoconfigure.AutoConfiguration;
import org.springframework.boot.autoconfigure.condition.ConditionalOnClass;
import org.springframework.boot.autoconfigure.condition.ConditionalOnMissingBean;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.boot.web.servlet.FilterRegistrationBean;
import org.springframework.context.annotation.Bean;
import org.springframework.http.HttpStatus;
import org.springframework.scheduling.annotation.EnableScheduling;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.RestControllerAdvice;
import org.springframework.web.client.RestClient;
import org.springframework.web.servlet.config.annotation.InterceptorRegistry;
import org.springframework.web.servlet.config.annotation.WebMvcConfigurer;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.SerializationFeature;
import com.fasterxml.jackson.datatype.jsr310.JavaTimeModule;
import com.kernelbridge.licensing.KernelBridgeApiException;
import com.kernelbridge.licensing.KernelBridgeLicenseService;
import com.kernelbridge.licensing.KernelBridgeLicenseVerifier;
import com.kernelbridge.licensing.KernelBridgeLicensingProperties;
import com.kernelbridge.licensing.client.KernelBridgeLicenseClient;
import com.kernelbridge.licensing.crypto.LicenseCryptoService;
import com.kernelbridge.licensing.state.FileLicenseStateRepository;
import com.kernelbridge.licensing.state.LicenseStateRepository;
import com.kernelbridge.licensing.web.KernelBridgeLicenseController;
import com.kernelbridge.licensing.web.KernelBridgeLicenseFilter;
import com.kernelbridge.licensing.web.KernelBridgeLicenseInterceptor;

import jakarta.servlet.Filter;

@AutoConfiguration
@EnableScheduling
@EnableConfigurationProperties(KernelBridgeLicensingProperties.class)
public class KernelBridgeLicensingAutoConfiguration {
    @Bean
    @ConditionalOnMissingBean(name = "kernelBridgeLicensingObjectMapper")
    ObjectMapper kernelBridgeLicensingObjectMapper() {
        return new ObjectMapper().registerModule(new JavaTimeModule()).disable(SerializationFeature.WRITE_DATES_AS_TIMESTAMPS);
    }

    @Bean
    @ConditionalOnMissingBean
    Clock kernelBridgeClock() {
        return Clock.systemUTC();
    }

    @Bean
    @ConditionalOnMissingBean
    RestClient.Builder kernelBridgeRestClientBuilder() {
        return RestClient.builder();
    }

    @Bean
    @ConditionalOnMissingBean
    LicenseCryptoService kernelBridgeLicenseCryptoService(KernelBridgeLicensingProperties properties, ObjectMapper kernelBridgeLicensingObjectMapper) {
        return new LicenseCryptoService(properties, kernelBridgeLicensingObjectMapper);
    }

    @Bean
    @ConditionalOnMissingBean
    LicenseStateRepository kernelBridgeLicenseStateRepository(KernelBridgeLicensingProperties properties, LicenseCryptoService crypto, ObjectMapper kernelBridgeLicensingObjectMapper) {
        return new FileLicenseStateRepository(properties, crypto, kernelBridgeLicensingObjectMapper);
    }

    @Bean
    @ConditionalOnMissingBean
    KernelBridgeLicenseClient kernelBridgeLicenseClient(KernelBridgeLicensingProperties properties, RestClient.Builder builder) {
        return new KernelBridgeLicenseClient(properties, builder);
    }

    @Bean
    @ConditionalOnMissingBean
    KernelBridgeLicenseService kernelBridgeLicenseService(KernelBridgeLicensingProperties properties, KernelBridgeLicenseClient client, LicenseStateRepository repository, LicenseCryptoService crypto, Clock clock) {
        return new KernelBridgeLicenseService(properties, client, repository, crypto, clock);
    }

    @Bean
    Long kernelBridgeLicensingDelay(KernelBridgeLicensingProperties properties) {
        return properties.getVerificationInterval().toMillis();
    }

    @Bean
    @ConditionalOnMissingBean
    KernelBridgeLicenseVerifier kernelBridgeLicenseVerifier(KernelBridgeLicenseService service) {
        return new KernelBridgeLicenseVerifier(service);
    }

    @Bean
    @ConditionalOnMissingBean
    KernelBridgeLicenseController kernelBridgeLicenseController(KernelBridgeLicenseService service, KernelBridgeLicensingProperties properties) {
        return new KernelBridgeLicenseController(service, properties);
    }

    @Bean
    @ConditionalOnMissingBean
    KernelBridgeLicenseExceptionHandler kernelBridgeLicenseExceptionHandler() {
        return new KernelBridgeLicenseExceptionHandler();
    }

    @Bean
    @ConditionalOnClass(Filter.class)
    FilterRegistrationBean<KernelBridgeLicenseFilter> kernelBridgeLicenseFilter(KernelBridgeLicensingProperties properties, KernelBridgeLicenseService service) {
        FilterRegistrationBean<KernelBridgeLicenseFilter> registration = new FilterRegistrationBean<>(new KernelBridgeLicenseFilter(properties, service));
        registration.setOrder(50);
        registration.addUrlPatterns("/*");
        return registration;
    }

    @Bean
    @ConditionalOnMissingBean
    KernelBridgeLicenseInterceptor kernelBridgeLicenseInterceptor(KernelBridgeLicenseService service) {
        return new KernelBridgeLicenseInterceptor(service);
    }

    @Bean
    WebMvcConfigurer kernelBridgeLicenseMvcConfigurer(KernelBridgeLicenseInterceptor interceptor) {
        return new WebMvcConfigurer() {
            @Override
            public void addInterceptors(InterceptorRegistry registry) {
                registry.addInterceptor(interceptor);
            }
        };
    }

    @RestControllerAdvice
    static class KernelBridgeLicenseExceptionHandler {
        @ExceptionHandler(KernelBridgeApiException.class)
        org.springframework.http.ResponseEntity<java.util.Map<String, Object>> handle(KernelBridgeApiException exception) {
            int status = exception.getStatusCode() == 0 ? 402 : exception.getStatusCode();
            return org.springframework.http.ResponseEntity.status(HttpStatus.valueOf(status)).body(java.util.Map.of(
                "error", "license_required",
                "reason", exception.getCode(),
                "message", exception.getMessage()
            ));
        }
    }
}


