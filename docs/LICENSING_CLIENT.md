# Reusable licensing client

The implementation lives in [`packages/kernelbridge/licensing-client`](../packages/kernelbridge/licensing-client) as a Composer-ready Laravel package. Its complete installation, configuration, middleware, queue, offline-grace, security, and usage guide is in the [package README](../packages/kernelbridge/licensing-client/README.md).

Each independently deployed product installs the same package and supplies only its own product code, machine credential, signing key, and KernelBridge API URL. No product should copy central licensing tables or reimplement license calculations.

The package is private and is not discoverable through Packagist. During local development, client applications must register its path repository before running `composer require`. For sibling Laragon projects, run from the client application:

```bash
composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client
composer require kernelbridge/licensing-client:^1.0
```

See the package README for copied-package layouts and private production repository installation.

Electron and Node/SQLite applications use the separate [TypeScript licensing client](TYPESCRIPT_LICENSING_CLIENT.md), which implements the same versioned API contract without Laravel dependencies.
