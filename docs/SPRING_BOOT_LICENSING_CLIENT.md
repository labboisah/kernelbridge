# Spring Boot Licensing Client

The implementation lives in [`packages/kernelbridge/licensing-client-spring-boot`](../packages/kernelbridge/licensing-client-spring-boot) as a Maven-ready Spring Boot starter. Its complete installation, configuration, route protection, Spring Security, scheduled verification, renewal notice, and development guide is in the [package README](../packages/kernelbridge/licensing-client-spring-boot/README.md).

## Quick Install

```bash
cd packages/kernelbridge/licensing-client-spring-boot
mvn install
```

Then add the dependency to the client Spring Boot application:

```xml
<dependency>
  <groupId>com.kernelbridge</groupId>
  <artifactId>kernelbridge-licensing-spring-boot-starter</artifactId>
  <version>1.0.0</version>
</dependency>
```

The starter provides a built-in `/license` activation page, encrypted local state, fail-closed route protection, annotations, optional Spring Security integration, and non-blocking renewal notices.

Public install guide: `/docs/licensing/spring-boot`.
