from django.urls import path

from . import views

app_name = "kernelbridge_licensing"

urlpatterns = [
    path("", views.activation, name="activation"),
    path("activate/", views.activate, name="activate"),
    path("verify/", views.verify, name="verify"),
    path("deactivate/", views.deactivate, name="deactivate"),
]
