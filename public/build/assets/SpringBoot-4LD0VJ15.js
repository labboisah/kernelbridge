import{_ as r}from"./SeoHead-u42wrgOe.js";import{_ as n}from"./CodeBlock-DdABLw3t.js";import{_ as o}from"./PublicLayout-BhBrVoER.js";import{o as l,c as a,a as s,w as c,d as t,j as i,F as p}from"./app-w1ePINo_.js";const d={class:"page-shell max-w-5xl py-16 lg:py-20"},g={class:"mt-10"},u={class:"mt-14"},m={class:"mt-14"},f={class:"mt-14"},b=`<dependency>
  <groupId>com.kernelbridge</groupId>
  <artifactId>kernelbridge-licensing-spring-boot-starter</artifactId>
  <version>1.0.0</version>
</dependency>`,y=`cd packages/kernelbridge/licensing-client-spring-boot
mvn install`,B=`kernelbridge:
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
      months: [3, 2, 1]`,E=`@RestController
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
}`,S=`@Bean
SecurityFilterChain security(HttpSecurity http, KernelBridgeLicenseService licenses) throws Exception {
    return http.authorizeHttpRequests(registry -> registry
        .requestMatchers("/license/**", "/login", "/assets/**").permitAll()
        .anyRequest().access(new KernelBridgeAuthorizationManager(licenses))
    ).build();
}`,v=`licenses.assertActive();
licenses.assertFeature("billing");
licenses.assertLimit("monthly_invoices", 100);

licenses.renewalNotice().ifPresent(notice -> {
    showBanner(notice.message());
});`,_={__name:"SpringBoot",setup(I){return(h,e)=>(l(),a(p,null,[s(r,{title:"Spring Boot Licensing Starter | KernelBridge",description:"Install and configure the KernelBridge Spring Boot licensing starter.",canonical:"https://kernelbridge.com/docs/licensing/spring-boot"}),s(o,null,{default:c(()=>[e[11]||(e[11]=t("section",{class:"page-hero"},[t("div",{class:"page-shell"},[t("p",{class:"section-label !text-teal-300"},"Spring Boot install guide"),t("h1",{class:"page-title"},"kernelbridge-licensing-spring-boot-starter"),t("p",{class:"page-intro"},"Auto-configured Java starter with encrypted state, servlet protection, annotations, Spring Security integration, scheduled verification, and renewal notices.")])],-1)),t("article",d,[e[10]||(e[10]=t("a",{href:"/docs/licensing",class:"font-bold text-cyan-700"},"Back to client SDKs",-1)),t("section",g,[e[0]||(e[0]=t("p",{class:"section-label"},"Install",-1)),e[1]||(e[1]=t("h2",{class:"section-title"},"Install the starter.",-1)),e[2]||(e[2]=t("p",{class:"mt-5 leading-8 text-slate-700"},"Build the local starter during development, then add the Maven dependency to the client Spring Boot application.",-1)),s(n,{title:"Local monorepo",code:y}),s(n,{title:"pom.xml",code:b})]),t("section",u,[e[3]||(e[3]=t("p",{class:"section-label"},"Configuration",-1)),e[4]||(e[4]=t("h2",{class:"section-title"},"Add product credentials.",-1)),s(n,{title:"application.yml",code:B})]),t("section",m,[e[5]||(e[5]=t("p",{class:"section-label"},"Route protection",-1)),e[6]||(e[6]=t("h2",{class:"section-title"},"Use global protection or annotations.",-1)),e[7]||(e[7]=t("p",{class:"mt-5 leading-8 text-slate-700"},[i("Set "),t("code",null,"protect-web-routes: true"),i(" to protect the whole application, or leave it false and use annotations or Spring Security rules.")],-1)),s(n,{title:"Controller annotations",code:E}),s(n,{title:"Spring Security",code:S})]),t("section",f,[e[8]||(e[8]=t("p",{class:"section-label"},"Service API",-1)),e[9]||(e[9]=t("h2",{class:"section-title"},"Repeat checks in sensitive operations.",-1)),s(n,{title:"KernelBridgeLicenseService",code:v})])])]),_:1})],64))}};export{_ as default};
