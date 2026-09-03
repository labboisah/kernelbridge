import type { ClientRuntime, SetupTemplate, SetupTemplateInput } from './types.js';

export function setupTemplate(input: SetupTemplateInput): SetupTemplate {
  const apiUrl = input.apiUrl.replace(/\/$/, '');
  const productCode = input.productCode.toUpperCase();
  const token = input.apiToken ?? 'replace-with-product-machine-token';
  const signingKey = input.cacheSigningKey ?? 'replace-with-independent-long-random-secret';
  const templates: Record<ClientRuntime, SetupTemplate> = {
    laravel: {
      runtime: 'laravel',
      packageName: 'kernelbridge/licensing-client-laravel',
      install: ['composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client-laravel', 'composer require kernelbridge/licensing-client-laravel:^1.0', 'php artisan vendor:publish --tag=kernelbridge-licensing-config', 'php artisan vendor:publish --tag=kernelbridge-licensing-migrations', 'php artisan vendor:publish --tag=kernelbridge-licensing-assets', 'php artisan migrate'].join('\n'),
      configuration: [`KERNELBRIDGE_API_URL=${apiUrl}`, `KERNELBRIDGE_PRODUCT_CODE=${productCode}`, `KERNELBRIDGE_API_TOKEN=${token}`, 'KERNELBRIDGE_VERIFICATION_INTERVAL=15', 'KERNELBRIDGE_OFFLINE_GRACE_HOURS=72', `KERNELBRIDGE_CACHE_SIGNING_KEY=${signingKey}`, 'KERNELBRIDGE_PROTECT_WEB_ROUTES=true', 'KERNELBRIDGE_PROTECT_EXCEPT=login,register,password/*', 'KERNELBRIDGE_RENEWAL_NOTICE_ENABLED=true'].join('\n'),
      protection: "Set KERNELBRIDGE_PROTECT_WEB_ROUTES=true, or add middleware 'kernelbridge.subscription.active', 'kernelbridge.feature:<key>', and 'kernelbridge.limit:<key>,<amount>' to selected routes.",
      activationPath: '/license',
      docsPath: '/docs/licensing/laravel',
    },
    typescript: {
      runtime: 'typescript',
      packageName: '@kernelbridge/licensing-client-typescript',
      install: 'npm install @kernelbridge/licensing-client-typescript better-sqlite3',
      configuration: [`KERNELBRIDGE_API_URL=${apiUrl}`, `KERNELBRIDGE_PRODUCT_CODE=${productCode}`, `KERNELBRIDGE_API_TOKEN=${token}`, 'KERNELBRIDGE_API_TIMEOUT_MS=10000', 'KERNELBRIDGE_VERIFICATION_INTERVAL_MS=900000', 'KERNELBRIDGE_OFFLINE_GRACE_MS=259200000', `KERNELBRIDGE_CACHE_SIGNING_KEY=${signingKey}`, 'KERNELBRIDGE_APP_KEY=replace-with-stable-app-installation-scope', 'KERNELBRIDGE_RENEWAL_NOTICE_ENABLED=true', 'KERNELBRIDGE_RENEWAL_NOTICE_MONTHS=3,2,1'].join('\n'),
      protection: 'Call LicenseGuard.checkActive() before opening Electron windows or serving protected Node routes. Return 402 JSON when allowed is false.',
      activationPath: 'host-app-defined',
      docsPath: '/docs/licensing/typescript',
    },
    'spring-boot': {
      runtime: 'spring-boot',
      packageName: 'com.kernelbridge:kernelbridge-licensing-spring-boot-starter',
      install: '<dependency>\n  <groupId>com.kernelbridge</groupId>\n  <artifactId>kernelbridge-licensing-spring-boot-starter</artifactId>\n  <version>1.0.0</version>\n</dependency>',
      configuration: ['kernelbridge:', '  licensing:', `    api-url: ${apiUrl}`, `    product-code: ${productCode}`, `    api-token: ${token}`, `    cache-signing-key: ${signingKey}`, '    verification-interval: PT15M', '    offline-grace: PT72H', '    activation-path: /license', '    protect-web-routes: true', '    renewal-notice:', '      enabled: true', '      months: [3, 2, 1]'].join('\n'),
      protection: 'Set protect-web-routes: true, annotate controllers with @RequiresKernelBridgeLicense, or use KernelBridgeAuthorizationManager in Spring Security.',
      activationPath: '/license',
      docsPath: '/docs/licensing/spring-boot',
    },
    django: {
      runtime: 'django',
      packageName: 'kernelbridge-licensing-django',
      install: 'pip install kernelbridge-licensing-django\npython manage.py migrate',
      configuration: ['KERNELBRIDGE_LICENSING = {', `    "API_URL": "${apiUrl}",`, `    "PRODUCT_CODE": "${productCode}",`, `    "API_TOKEN": "${token}",`, `    "CACHE_SIGNING_KEY": "${signingKey}",`, '    "OFFLINE_GRACE_SECONDS": 259200,', '    "PROTECT_WEB_ROUTES": True,', '    "PROTECT_EXCEPT": ["/license/", "/accounts/login/", "/static/"],', '    "RENEWAL_NOTICE_ENABLED": True,', '    "RENEWAL_NOTICE_MONTHS": [3, 2, 1],', '}'].join('\n'),
      protection: 'Set PROTECT_WEB_ROUTES true, or use @requires_kernelbridge_license, @requires_kernelbridge_feature, and @requires_kernelbridge_limit decorators.',
      activationPath: '/license/',
      docsPath: '/docs/licensing/django',
    },
  };
  return templates[input.runtime];
}
