# Reusable licensing client

The implementation lives in [`packages/kernelbridge/licensing-client-laravel`](../packages/kernelbridge/licensing-client-laravel) as a Composer-ready Laravel package. Its complete installation, configuration, middleware, queue, offline-grace, security, and usage guide is in the [package README](../packages/kernelbridge/licensing-client-laravel/README.md).

Each independently deployed product installs the same package and supplies only its own product code, machine credential, signing key, and KernelBridge API URL. No product should copy central licensing tables or reimplement license calculations.

The package is private and is not discoverable through Packagist. During local development, client applications must register its path repository before running `composer require`. For sibling Laragon projects, run both commands from the client application's root:

```bash
composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client-laravel
composer require kernelbridge/licensing-client-laravel:^1.0
```

If the applications are not sibling folders, use an absolute path such as `C:/laragon/www/kernelbridge/packages/kernelbridge/licensing-client-laravel` in the first command. After publishing config/migrations and running `php artisan migrate`, the package provides a built-in `/license` screen with activation, verification, and deactivation actions. See the package README for route, view, and private production repository installation details.

The package redirects browser users who need activation or re-activation to `/license`, while JSON/API requests receive `402 Payment Required`. Set `KERNELBRIDGE_PROTECT_WEB_ROUTES=true` to protect the whole client app, or leave it false/unset and add `kernelbridge.subscription.active` only to selected routes. Using both is safe but redundant. It also records a signed fingerprint of sensitive `.env` licensing values and requires re-activation if those values change after activation.

Electron and Node/SQLite applications use the separate [TypeScript licensing client](TYPESCRIPT_LICENSING_CLIENT.md), which implements the same versioned API contract without Laravel dependencies.






