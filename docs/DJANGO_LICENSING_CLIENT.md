# Django Licensing Client

The implementation lives in [`packages/kernelbridge/licensing-client-django`](../packages/kernelbridge/licensing-client-django) as a reusable Django app. Its complete installation, configuration, middleware, decorators, management commands, renewal notice, and development guide is in the [package README](../packages/kernelbridge/licensing-client-django/README.md).

## Quick Install

```bash
pip install kernelbridge-licensing-django
python manage.py migrate
```

For local development from this monorepo:

```bash
cd packages/kernelbridge/licensing-client-django
pip install -e .
```

The app provides a built-in `/license/` activation page, encrypted DB-backed state, fail-closed middleware, decorators, management commands, and non-blocking renewal notices.

Public install guide: `/docs/licensing/django`.
