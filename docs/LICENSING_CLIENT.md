# Reusable licensing client

The implementation lives in [`packages/kernelbridge/licensing-client-laravel`](../packages/kernelbridge/licensing-client-laravel) as a Composer-ready Laravel package. Its installation, security model, migration, activation flow, and operational guidance are documented in the [package README](../packages/kernelbridge/licensing-client-laravel/README.md).

Each independent product app installs the same package and supplies only its own product code, machine credential, signing key, and KernelBridge API URL. No product should copy central licensing tables or reimplement license policy logic in its own application.

## Repository and install flow

The package is hosted in the public GitHub repository `labboisah/laravel-licensing-package` and is not published to Packagist. Client apps therefore register a VCS or path repository before requiring it.

For sibling Laravel apps, use:

```bash
composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client-laravel
composer require kernelbridge/licensing-client-laravel:^1.0
```

If the apps are not sibling folders, use an absolute path such as `C:/laragon/www/kernelbridge/packages/kernelbridge/licensing-client-laravel` in the repository definition. After publishing the config and migrations and running `php artisan migrate`, the client app gets a built-in `/license` screen with activation, verification, deactivation, and deployment re-provision controls.

## Hardened security model

The current package behavior is intentionally fail-closed:

- local config is never treated as a trusted deployment authority
- a persisted deployment profile records the expected deployment configuration
- sensitive values are fingerprinted and checked before activation and verification
- mismatches and tampering require re-activation or an intentional re-provision
- an operator-driven deployment rotation is supported instead of silent local fallback

This prevents a product app from quietly ignoring a deployment mismatch or silently switching to a weaker local policy when environment values are changed.

## Activation, verification and re-provision

The package redirects browser users who need activation or re-activation to `/license`, while JSON/API requests receive `402 Payment Required` when activation is missing or the runtime config no longer matches the persisted deployment fingerprint.

Set `KERNELBRIDGE_PROTECT_WEB_ROUTES=true` to protect the whole client app, or leave it false and add `kernelbridge.subscription.active` only to selected routes. Using both is safe but redundant.

When a real deployment change is required, the operational path is explicit:

```bash
php artisan kernelbridge:reprovision-profile
```

The CLI command prompts for confirmation (defaulting to no), validates required deployment settings, and records the new profile fingerprint. Use `--force` only for a controlled non-interactive operation. This detects config drift but is not a trust anchor against an administrator who can modify the client files and database.

## Related clients

Electron and Node/SQLite applications use the separate [TypeScript licensing client](TYPESCRIPT_LICENSING_CLIENT.md), which implements the same contract without Laravel dependencies.

The same security principles also apply across the other SDKs in this repository: strict verification, signed local cache state, and fail-closed handling when the runtime no longer matches the persisted deployment profile.


