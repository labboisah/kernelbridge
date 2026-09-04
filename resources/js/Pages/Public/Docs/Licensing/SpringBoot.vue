<script setup>
import SeoHead from '@/Components/Public/SeoHead.vue';
import CodeBlock from '@/Components/Public/CodeBlock.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const dependency = `<dependency>
  <groupId>com.kernelbridge</groupId>
  <artifactId>kernelbridge-licensing-spring-boot-starter</artifactId>
  <version>1.0.0</version>
</dependency>`;
const localInstall = `cd packages/kernelbridge/licensing-client-spring-boot
mvn install`;
const yaml = `kernelbridge:
  licensing:
    api-url: https://kernelbridge.com/api/v1
    product-code: ALIGNEX
    api-token: \${KERNELBRIDGE_API_TOKEN}
    cache-signing-key: \${KERNELBRIDGE_CACHE_SIGNING_KEY}
    encryption-key: \${KERNELBRIDGE_LICENSE_ENCRYPTION_KEY:\${KERNELBRIDGE_CACHE_SIGNING_KEY}}
    verification-interval: PT15M
    offline-grace: PT72H
    activation-path: /license
    protect-web-routes: true
    protect-except:
      - /license/**
      - /login
      - /assets/**
      - /error
    renewal-notice:
      enabled: true
      months: [3, 2, 1]`;
const annotations = `@RestController
@RequiresKernelBridgeLicense
class ReportsController {
    @GetMapping("/reports")
    List<Report> index() {
        return reports.all();
    }
}

@GetMapping("/finance")
@RequiresKernelBridgeFeature("finance")
FinanceDashboard finance() {
    return dashboard.load();
}

@PostMapping("/users/import")
@RequiresKernelBridgeLimit(key = "users", required = 25)
void importUsers() {
    importer.run();
}`;
const security = `@Bean
SecurityFilterChain security(HttpSecurity http, KernelBridgeLicenseService licenses) throws Exception {
    return http.authorizeHttpRequests(registry -> registry
        .requestMatchers("/license/**", "/login", "/assets/**").permitAll()
        .anyRequest().access(new KernelBridgeAuthorizationManager(licenses))
    ).build();
}`;
const programmatic = `licenses.assertActive();
licenses.assertFeature("billing");
licenses.assertLimit("monthly_invoices", 100);

licenses.renewalNotice().ifPresent(notice -> {
    showBanner(notice.message());
});`;
</script>

<template>
  <SeoHead title="Spring Boot Licensing Starter | KernelBridge" description="Install and configure the KernelBridge Spring Boot licensing starter." canonical="https://kernelbridge.com/docs/licensing/spring-boot" />
  <PublicLayout>
    <section class="page-hero"><div class="page-shell"><p class="section-label !text-teal-300">Spring Boot install guide</p><h1 class="page-title">kernelbridge-licensing-spring-boot-starter</h1><p class="page-intro">Auto-configured Java starter with encrypted state, servlet protection, annotations, Spring Security integration, scheduled verification, and renewal notices.</p></div></section>
    <article class="page-shell max-w-5xl py-16 lg:py-20">
      <a href="/docs/licensing" class="font-bold text-cyan-700">Back to client SDKs</a>
      <section class="mt-10"><p class="section-label">Install</p><h2 class="section-title">Install the starter.</h2><p class="mt-5 leading-8 text-slate-700">Build the local starter during development, then add the Maven dependency to the client Spring Boot application.</p><CodeBlock title="Local monorepo" :code="localInstall" /><CodeBlock title="pom.xml" :code="dependency" /></section>
      <section class="mt-14"><p class="section-label">Configuration</p><h2 class="section-title">Add product credentials.</h2><CodeBlock title="application.yml" :code="yaml" /></section>
      <section class="mt-14"><p class="section-label">Route protection</p><h2 class="section-title">Use global protection or annotations.</h2><p class="mt-5 leading-8 text-slate-700">Set <code>protect-web-routes: true</code> to protect the whole application, or leave it false and use annotations or Spring Security rules.</p><CodeBlock title="Controller annotations" :code="annotations" /><CodeBlock title="Spring Security" :code="security" /></section>
      <section class="mt-14"><p class="section-label">Service API</p><h2 class="section-title">Repeat checks in sensitive operations.</h2><CodeBlock title="KernelBridgeLicenseService" :code="programmatic" /></section>
    </article>
  </PublicLayout>
</template>
