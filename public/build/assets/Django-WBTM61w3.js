import{_ as l}from"./SeoHead-OjnlQJ9p.js";import{_ as n}from"./CodeBlock-PIzI6FXW.js";import{_ as r}from"./PublicLayout-D4Py2hkU.js";import{o,c as a,a as s,w as c,d as t,j as i,F as d}from"./app-DWMDcd9c.js";const g={class:"page-shell max-w-5xl py-16 lg:py-20"},p={class:"mt-10"},_={class:"mt-14"},E={class:"mt-14"},m={class:"mt-14"},u={class:"mt-14"},N=`pip install kernelbridge-licensing-django
python manage.py migrate`,I=`cd packages/kernelbridge/licensing-client-django
pip install -e .`,b=`INSTALLED_APPS = [
    # ...
    "kernelbridge_licensing",
]

urlpatterns = [
    path("license/", include("kernelbridge_licensing.urls")),
    # ...
]

MIDDLEWARE = [
    "kernelbridge_licensing.middleware.KernelBridgeLicenseMiddleware",
    # ...
]`,T=`KERNELBRIDGE_LICENSING = {
    "API_URL": "https://kernelbridge.com/api/v1",
    "PRODUCT_CODE": "ALIGNEX",
    "API_TOKEN": env("KERNELBRIDGE_API_TOKEN"),
    "CACHE_SIGNING_KEY": env("KERNELBRIDGE_CACHE_SIGNING_KEY"),
    "ENCRYPTION_KEY": env("KERNELBRIDGE_LICENSE_ENCRYPTION_KEY"),
    "VERIFICATION_INTERVAL_SECONDS": 900,
    "OFFLINE_GRACE_SECONDS": 259200,
    "ACTIVATION_PATH": "/license/",
    "PROTECT_WEB_ROUTES": True,
    "PROTECT_EXCEPT": ["/license/", "/accounts/login/", "/static/"],
    "RENEWAL_NOTICE_ENABLED": True,
    "RENEWAL_NOTICE_MONTHS": [3, 2, 1],
}`,f=`from kernelbridge_licensing.decorators import (
    requires_kernelbridge_feature,
    requires_kernelbridge_license,
    requires_kernelbridge_limit,
)

@requires_kernelbridge_license
def reports(request):
    ...

@requires_kernelbridge_feature("finance")
def finance_dashboard(request):
    ...

@requires_kernelbridge_limit("users", 25)
def import_users(request):
    ...`,R=`python manage.py kernelbridge_activate KB-... --device-name="Office Server"
python manage.py kernelbridge_verify --force
python manage.py kernelbridge_deactivate`,k=`TEMPLATES[0]["OPTIONS"]["context_processors"] += [
    "kernelbridge_licensing.context_processors.kernelbridge_renewal_notice",
]`,P={__name:"Django",setup(C){return(L,e)=>(o(),a(d,null,[s(l,{title:"Django Licensing Client | KernelBridge",description:"Install and configure the KernelBridge Django licensing client.",canonical:"https://kernelbridge.com/docs/licensing/django"}),s(r,null,{default:c(()=>[e[12]||(e[12]=t("section",{class:"page-hero"},[t("div",{class:"page-shell"},[t("p",{class:"section-label !text-teal-300"},"Django install guide"),t("h1",{class:"page-title"},"kernelbridge-licensing-django"),t("p",{class:"page-intro"},"Django app with encrypted DB state, activation URLs, route middleware, decorators, management commands, and renewal notice context.")])],-1)),t("article",g,[e[11]||(e[11]=t("a",{href:"/docs/licensing",class:"font-bold text-cyan-700"},"Back to client SDKs",-1)),t("section",p,[e[0]||(e[0]=t("p",{class:"section-label"},"Install",-1)),e[1]||(e[1]=t("h2",{class:"section-title"},"Install the Django app.",-1)),s(n,{title:"Production",code:N}),s(n,{title:"Local monorepo",code:I})]),t("section",_,[e[2]||(e[2]=t("p",{class:"section-label"},"Project setup",-1)),e[3]||(e[3]=t("h2",{class:"section-title"},"Register the app, URLs, and middleware.",-1)),s(n,{title:"settings.py / urls.py",code:b})]),t("section",E,[e[4]||(e[4]=t("p",{class:"section-label"},"Settings",-1)),e[5]||(e[5]=t("h2",{class:"section-title"},"Copy service credentials.",-1)),s(n,{title:"settings.py",code:T})]),t("section",m,[e[6]||(e[6]=t("p",{class:"section-label"},"Protection",-1)),e[7]||(e[7]=t("h2",{class:"section-title"},"Protect globally or with decorators.",-1)),e[8]||(e[8]=t("p",{class:"mt-5 leading-8 text-slate-700"},[i("Set "),t("code",null,"PROTECT_WEB_ROUTES"),i(" true to protect the whole app. Leave it false when using decorators or direct service checks only.")],-1)),s(n,{title:"views.py",code:f})]),t("section",u,[e[9]||(e[9]=t("p",{class:"section-label"},"Operations",-1)),e[10]||(e[10]=t("h2",{class:"section-title"},"Activate, verify, deactivate.",-1)),s(n,{title:"Management commands",code:R}),s(n,{title:"Renewal context",code:k})])])]),_:1})],64))}};export{P as default};
