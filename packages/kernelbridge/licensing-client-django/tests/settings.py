SECRET_KEY = "tests"
USE_TZ = True
ROOT_URLCONF = "tests.urls"
DEFAULT_AUTO_FIELD = "django.db.models.BigAutoField"
INSTALLED_APPS = [
    "django.contrib.auth",
    "django.contrib.contenttypes",
    "kernelbridge_licensing",
]
DATABASES = {"default": {"ENGINE": "django.db.backends.sqlite3", "NAME": ":memory:"}}
MIDDLEWARE = ["kernelbridge_licensing.middleware.KernelBridgeLicenseMiddleware"]
KERNELBRIDGE_LICENSING = {
    "API_URL": "https://kernelbridge.test/api/v1",
    "PRODUCT_CODE": "ALIGNEX",
    "API_TOKEN": "token",
    "CACHE_SIGNING_KEY": "0123456789abcdef0123456789abcdef",
    "ENCRYPTION_KEY": "abcdef0123456789abcdef0123456789",
    "OFFLINE_GRACE_SECONDS": 259200,
    "PROTECT_WEB_ROUTES": True,
    "PROTECT_EXCEPT": ["/license/", "/public/"],
    "RENEWAL_NOTICE_MONTHS": [3, 2, 1],
}
