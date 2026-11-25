<?php

use App\Http\Controllers\Admin\AccessControlController as AdminAccessControlController;
use App\Http\Controllers\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DemoModeController as AdminDemoModeController;
use App\Http\Controllers\Admin\HostingSettingsController;
use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\LeadController as AdminLeadController;
use App\Http\Controllers\Admin\LicenseManagementController as AdminLicenseManagementController;
use App\Http\Controllers\Admin\McpController as AdminMcpController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ProductReleaseController as AdminProductReleaseController;
use App\Http\Controllers\Admin\QuotationController as AdminQuotationController;
use App\Http\Controllers\Admin\StaffController as AdminStaffController;
use App\Http\Controllers\Admin\SupportTeamController as AdminSupportTeamController;
use App\Http\Controllers\Admin\SupportTicketController as AdminSupportTicketController;
use App\Http\Controllers\Admin\UserManagementController as AdminUserManagementController;
use App\Http\Controllers\Customer\CheckoutController;
use App\Http\Controllers\Customer\DashboardController as CustomerDashboardController;
use App\Http\Controllers\Customer\LicensePurchaseController;
use App\Http\Controllers\Customer\PortalController as CustomerPortalController;
use App\Http\Controllers\Customer\SupportTicketController as CustomerSupportTicketController;
use App\Http\Controllers\CustomerInvoiceController;
use App\Http\Controllers\CustomerQuotationController;
use App\Http\Controllers\FlutterwavePaymentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicSite\ContactController;
use App\Http\Controllers\PublicSite\DemoController;
use App\Http\Controllers\PublicSite\HomeController;
use App\Http\Controllers\PublicSite\PageController;
use App\Http\Controllers\PublicSite\PricingController;
use App\Http\Controllers\PublicSite\ProductController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\SupportAttachmentController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::post('/partners/{type}/register', [\App\Http\Controllers\Partner\RegistrationController::class, 'store'])->middleware(['guest', 'throttle:5,1'])->name('partners.register.store');
Route::prefix('partner')->name('partner.')->middleware(['auth', 'verified'])->group(function () {
    $controller = \App\Http\Controllers\Partner\PortalController::class;
    Route::get('/', [$controller, 'dashboard'])->middleware('permission:partner.dashboard')->name('dashboard');
    Route::get('/bank-details', [\App\Http\Controllers\Partner\BankDetailsController::class, 'edit'])->middleware('permission:partner.bank.manage')->name('bank.edit');
    Route::put('/bank-details', [\App\Http\Controllers\Partner\BankDetailsController::class, 'update'])->middleware(['permission:partner.bank.manage', 'throttle:10,1'])->name('bank.update');
    Route::get('/pcr', [\App\Http\Controllers\Partner\ProspectiveCustomerController::class, 'index'])->middleware('permission:partner.pcr.view')->name('pcr.index');
    Route::post('/pcr', [\App\Http\Controllers\Partner\ProspectiveCustomerController::class, 'store'])->middleware(['permission:partner.pcr.create', 'throttle:20,1'])->name('pcr.store');
    Route::get('/pcr/{prospect}', [\App\Http\Controllers\Partner\ProspectiveCustomerController::class, 'show'])->middleware('permission:partner.pcr.view')->name('pcr.show');
    Route::put('/pcr/{prospect}/contact', [\App\Http\Controllers\Partner\ProspectiveCustomerController::class, 'recordContact'])->middleware(['permission:partner.pcr.update', 'throttle:20,1'])->name('pcr.contact');
    Route::get('/crm', [\App\Http\Controllers\Partner\CrmController::class, 'index'])->middleware('permission:partner.crm.view')->name('crm.index');
    Route::get('/crm/{lead}', [\App\Http\Controllers\Partner\CrmController::class, 'show'])->middleware('permission:partner.crm.view')->name('crm.show');
    Route::put('/crm/{lead}', [\App\Http\Controllers\Partner\CrmController::class, 'update'])->middleware('permission:partner.crm.update')->name('crm.update');
    Route::put('/crm/{lead}/stage', [\App\Http\Controllers\Partner\CrmController::class, 'advance'])->middleware('permission:partner.crm.update')->name('crm.advance');
    Route::get('/quotations', [\App\Http\Controllers\Partner\QuotationController::class, 'index'])->middleware('permission:partner.quotation.view')->name('quotations.index');
    Route::post('/quotations', [\App\Http\Controllers\Partner\QuotationController::class, 'store'])->middleware(['permission:partner.quotation.create', 'throttle:20,1'])->name('quotations.store');
    Route::get('/quotations/{quotation}/pdf', [\App\Http\Controllers\Partner\QuotationController::class, 'pdf'])->middleware('permission:partner.quotation.view')->name('quotations.pdf');
    Route::get('/clients', [$controller, 'customers'])->middleware('permission:partner.client.view')->name('customers');
    Route::post('/clients', [$controller, 'storeCustomer'])->middleware(['permission:partner.client.create', 'throttle:20,1'])->name('customers.store');
    Route::put('/clients/{customer}', [$controller, 'updateCustomer'])->middleware('permission:partner.client.update')->name('customers.update');
    Route::post('/clients/{customer}/invitation', [$controller, 'inviteCustomer'])->middleware(['permission:partner.client.invite', 'throttle:5,1'])->name('customers.invitation');
    Route::get('/clients/{customer}/subscriptions/create', [$controller, 'createCustomerSubscription'])->middleware('permission:partner.subscription.create')->name('customers.subscriptions.create');
    Route::get('/subscriptions', [$controller, 'subscriptions'])->middleware('permission:partner.subscription.view')->name('subscriptions');
    Route::get('/activation-codes', [$controller, 'activationCodes'])->middleware('permission:partner.license.view')->name('activation-codes');
    Route::get('/invoices', [$controller, 'invoices'])->middleware('permission:partner.invoice.view')->name('invoices');
    Route::post('/invoices', [$controller, 'storeInvoice'])->middleware(['permission:partner.invoice.create', 'throttle:10,1'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [$controller, 'showInvoice'])->middleware('permission:partner.invoice.view')->name('invoices.show');
    Route::post('/invoices/{invoice}/pay', [$controller, 'pay'])->middleware(['permission:partner.invoice.pay', 'throttle:10,1'])->name('invoices.pay');
    Route::post('/invoices/{invoice}/payments', [$controller, 'recordPayment'])->middleware(['permission:partner.payment.create', 'throttle:10,1'])->name('payments.store');
    Route::get('/payments', [$controller, 'payments'])->middleware('permission:partner.payment.view')->name('payments');
    Route::get('/commission', [$controller, 'commission'])->middleware('permission:partner.commission.view')->name('commission');
    Route::get('/nrs-commission', [$controller, 'networkCommission'])->middleware('permission:partner.network-commission.view')->name('nrs-commission');
    Route::get('/effort-points', [$controller, 'effortPoints'])->middleware('permission:partner.effort-points.view')->name('effort-points');
    Route::get('/organization-upgrade', [\App\Http\Controllers\Partner\OrganizationUpgradeController::class, 'show'])->middleware('permission:partner.organization.manage')->name('organization-upgrade');
    Route::post('/organization-upgrade', [\App\Http\Controllers\Partner\OrganizationUpgradeController::class, 'store'])->middleware(['permission:partner.organization.manage', 'throttle:5,1'])->name('organization-upgrade.store');
    Route::get('/revenue', [$controller, 'revenue'])->middleware('permission:partner.revenue.view')->name('revenue');
    Route::get('/support', [$controller, 'support'])->middleware('permission:portal.support.view')->name('support');
    Route::post('/support', [$controller, 'storeSupport'])->middleware(['permission:portal.support.create', 'throttle:10,1'])->name('support.store');
    Route::get('/support/{ticket}', [$controller, 'showSupport'])->middleware('permission:portal.support.view')->name('support.show');
    Route::put('/support/{ticket:uuid}', [$controller, 'updateSupport'])->middleware('permission:portal.support.update')->name('support.update');
    Route::post('/support/{ticket}/resolve', [$controller, 'resolveSupport'])->middleware('permission:portal.support.resolve')->name('support.resolve');
    Route::post('/support/{ticket}/messages', [$controller, 'reply'])->middleware(['permission:portal.support.reply', 'throttle:20,1'])->name('support.reply');
    Route::get('/support-attachments/{attachment:uuid}', SupportAttachmentController::class)->middleware('permission:support.view|portal.support.view')->name('support.attachments.show');
});
Route::get('/partners', [\App\Http\Controllers\PublicSite\PartnerController::class, 'index'])->name('partners.index');
Route::get('/partners/{type}/register', [\App\Http\Controllers\PublicSite\PartnerController::class, 'start'])->name('partners.register');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/partners/{type}/onboarding', [\App\Http\Controllers\PublicSite\PartnerController::class, 'edit'])->middleware('permission:partner.application')->name('partners.onboarding');
    Route::post('/partners/{type}/onboarding', [\App\Http\Controllers\PublicSite\PartnerController::class, 'save'])->middleware(['permission:partner.application', 'throttle:10,1'])->name('partners.save');
    Route::get('/partner-applications/{application}/photo', [\App\Http\Controllers\PublicSite\PartnerController::class, 'photo'])->middleware('permission:partner.application|partner.manage')->name('partners.photo');
});
Route::get('/health', HealthController::class)->middleware('throttle:30,1')->name('health');
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{product:slug}/pricing', [PricingController::class, 'show'])->name('products.pricing');
Route::get('/products/{product:slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/services', [PageController::class, 'services'])->name('services');
Route::get('/about', [PageController::class, 'about'])->name('about');
Route::get('/docs/licensing', [PageController::class, 'licensingDocs'])->name('docs.licensing');
Route::get('/docs/licensing/laravel', [PageController::class, 'laravelLicensingDocs'])->name('docs.licensing.laravel');
Route::get('/docs/licensing/typescript', [PageController::class, 'typescriptLicensingDocs'])->name('docs.licensing.typescript');
Route::get('/docs/licensing/spring-boot', [PageController::class, 'springBootLicensingDocs'])->name('docs.licensing.spring-boot');
Route::get('/docs/licensing/django', [PageController::class, 'djangoLicensingDocs'])->name('docs.licensing.django');
Route::get('/docs/licensing/mcp', [PageController::class, 'mcpLicensingDocs'])->name('docs.licensing.mcp');
Route::get('/contact', [ContactController::class, 'create'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');
Route::get('/demo', [DemoController::class, 'create'])->name('demo');
Route::post('/demo', [DemoController::class, 'store'])->middleware('throttle:5,1')->name('demo.store');
Route::get('/payments/flutterwave/callback', [FlutterwavePaymentController::class, 'callback'])->middleware('throttle:30,1')->name('payments.flutterwave.callback');
Route::post('/payments/flutterwave/webhook', [FlutterwavePaymentController::class, 'webhook'])->middleware('throttle:120,1')->name('payments.flutterwave.webhook');
Route::get('/client-invitations/{token}', [\App\Http\Controllers\Customer\ClientInvitationController::class, 'show'])->middleware('throttle:30,1')->name('client-invitations.show');
Route::post('/client-invitations/{token}', [\App\Http\Controllers\Customer\ClientInvitationController::class, 'accept'])->middleware('throttle:5,1')->name('client-invitations.accept');
Route::middleware('signed')->group(function (): void {
    Route::get('/invoice/{invoice:uuid}', [CustomerInvoiceController::class, 'show'])->name('invoices.customer.show');
    Route::get('/quotation/{quotation:uuid}', [CustomerQuotationController::class, 'show'])->name('quotations.customer.show');
    Route::post('/quotation/{quotation:uuid}/accept', [CustomerQuotationController::class, 'accept'])->name('quotations.customer.accept');
    Route::post('/quotation/{quotation:uuid}/reject', [CustomerQuotationController::class, 'reject'])->name('quotations.customer.reject');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', CustomerDashboardController::class)->middleware('permission:portal.dashboard')->name('dashboard');
    Route::get('/notifications', [\App\Http\Controllers\NotificationInboxController::class, 'index'])->middleware('permission:notification.inbox')->name('notifications.index');
    Route::get('/notification-attachments/{attachment:uuid}', \App\Http\Controllers\AdminNotificationAttachmentController::class)->middleware('permission:notification.inbox')->name('notifications.attachments.download');
    Route::get('/notification-attachments/{attachment:uuid}/preview', [\App\Http\Controllers\AdminNotificationAttachmentController::class, 'preview'])->middleware('permission:notification.inbox')->name('notifications.attachments.preview');
    Route::put('/notifications/read-all', [\App\Http\Controllers\NotificationInboxController::class, 'readAll'])->middleware('permission:notification.inbox')->name('notifications.read-all');
    Route::put('/notifications/{notification}/read', [\App\Http\Controllers\NotificationInboxController::class, 'read'])->middleware('permission:notification.inbox')->name('notifications.read');
    Route::get('/staff', StaffDashboardController::class)->middleware('permission:staff.dashboard')->name('staff.dashboard');
    Route::prefix('account')->name('portal.')->group(function (): void {
        Route::get('/checkout', [CheckoutController::class, 'show'])->middleware('permission:portal.checkout.view')->name('checkout.show');
        Route::post('/checkout', [CheckoutController::class, 'store'])->middleware(['permission:portal.checkout.create', 'throttle:10,1'])->name('checkout.store');
        Route::get('/services/{subscription:uuid}/environment', [CheckoutController::class, 'environment'])->middleware('permission:portal.services.view')->name('services.environment');
        Route::get('/services/{subscription:uuid}/release', [CheckoutController::class, 'downloadRelease'])->middleware('permission:portal.services.view')->name('services.release.download');
        Route::get('/services/{subscription:uuid}/installation-guide', [CustomerPortalController::class, 'installationGuide'])->middleware('permission:portal.services.view')->name('services.installation-guide');
        Route::get('/services', [CustomerPortalController::class, 'services'])->middleware('permission:portal.services.view')->name('services.index');
        Route::get('/subscriptions', [CustomerPortalController::class, 'subscriptions'])->middleware('permission:portal.subscriptions.view')->name('subscriptions.index');
        Route::get('/organization', [CustomerPortalController::class, 'organization'])->middleware('permission:portal.organization.view')->name('organization.index');
        Route::get('/services/create', [CustomerPortalController::class, 'createService'])->middleware('permission:portal.services.create')->name('services.create');
        Route::post('/services', [CustomerPortalController::class, 'storeService'])->middleware('permission:portal.services.create')->name('services.store');
        Route::get('/services/{subscription:uuid}/edit', [CustomerPortalController::class, 'editService'])->middleware('permission:portal.services.update')->name('services.edit');
        Route::put('/services/{subscription:uuid}', [CustomerPortalController::class, 'updateService'])->middleware('permission:portal.services.update')->name('services.update');
        Route::post('/services/{subscription:uuid}/suspend', [CustomerPortalController::class, 'suspendService'])->middleware('permission:portal.services.suspend')->name('services.suspend');
        Route::delete('/services/{subscription:uuid}', [CustomerPortalController::class, 'destroyService'])->middleware('permission:portal.services.delete')->name('services.destroy');
        Route::post('/licenses/{subscription:uuid}/preview', [LicensePurchaseController::class, 'preview'])->middleware('permission:portal.licenses.manage')->name('licenses.preview');
        Route::post('/licenses/{subscription:uuid}/packages', [LicensePurchaseController::class, 'change'])->middleware('permission:portal.licenses.manage')->name('licenses.change');
        Route::post('/licenses/{subscription:uuid}/renew', [LicensePurchaseController::class, 'renew'])->middleware('permission:portal.licenses.manage')->name('licenses.renew');
        Route::post('/package-orders/{order:uuid}/cancel', [LicensePurchaseController::class, 'cancel'])->middleware('permission:portal.licenses.manage')->name('licenses.cancel-order');
        Route::post('/invoices/{invoice:uuid}/credit', [LicensePurchaseController::class, 'credit'])->middleware('permission:portal.invoices.credit')->name('invoices.credit');
        Route::post('/licenses/purchase', [LicensePurchaseController::class, 'store'])->middleware(['permission:portal.licenses.manage', 'throttle:10,1'])->name('licenses.purchase');
        Route::post('/licenses/edunexa/purchase', [LicensePurchaseController::class, 'purchaseEdunexa'])->middleware(['permission:portal.licenses.manage', 'throttle:10,1'])->name('licenses.edunexa.purchase');
        Route::get('/licenses', [CustomerPortalController::class, 'licenses'])->middleware('permission:portal.licenses.view')->name('licenses.index');
        Route::post('/licenses/{license:uuid}/activation-codes', [CustomerPortalController::class, 'issueActivationCode'])->middleware('permission:portal.licenses.manage')->name('licenses.activation-codes.issue');
        Route::post('/license-installations/{installation:uuid}/revoke', [CustomerPortalController::class, 'revokeInstallation'])->middleware('permission:portal.licenses.manage')->name('licenses.installations.revoke');
        Route::post('/platform-account-links/{link:uuid}/approve', [CustomerPortalController::class, 'approvePlatformAccountLink'])->middleware('permission:portal.licenses.manage')->name('platform-account-links.approve');
        Route::post('/platform-account-links/{link:uuid}/reject', [CustomerPortalController::class, 'rejectPlatformAccountLink'])->middleware('permission:portal.licenses.manage')->name('platform-account-links.reject');
        Route::post('/platform-account-links/{link:uuid}/revoke', [CustomerPortalController::class, 'revokePlatformAccountLink'])->middleware('permission:portal.licenses.manage')->name('platform-account-links.revoke');
        Route::post('/license-activations/{activation:uuid}/deactivate', [CustomerPortalController::class, 'deactivate'])->middleware('permission:portal.licenses.manage')->name('licenses.activations.deactivate');
        Route::get('/invoices', [CustomerPortalController::class, 'invoices'])->middleware('permission:portal.invoices.view')->name('invoices.index');
        Route::get('/invoices/{invoice:uuid}', [CustomerPortalController::class, 'showInvoice'])->middleware('permission:portal.invoices.view')->name('invoices.show');
        Route::post('/invoices/{invoice:uuid}/pay-online', [FlutterwavePaymentController::class, 'initialize'])->middleware(['permission:portal.invoices.pay', 'throttle:10,1'])->name('invoices.pay-online');
        Route::get('/payments', [CustomerPortalController::class, 'payments'])->middleware('permission:portal.payments.view')->name('payments.index');
        Route::get('/payments/create', [CustomerPortalController::class, 'createPayment'])->middleware('permission:portal.payments.create')->name('payments.create');
        Route::post('/payments', [CustomerPortalController::class, 'storePayment'])->middleware('permission:portal.payments.create')->name('payments.store');
    });
    Route::get('/support', [CustomerSupportTicketController::class, 'index'])->middleware('permission:portal.support.view')->name('support.index');
    Route::get('/support/create', [CustomerSupportTicketController::class, 'create'])->middleware('permission:portal.support.create')->name('support.create');
    Route::post('/support', [CustomerSupportTicketController::class, 'store'])->middleware('permission:portal.support.create')->name('support.store');
    Route::get('/support/{ticket:uuid}', [CustomerSupportTicketController::class, 'show'])->middleware('permission:portal.support.view')->name('support.show');
    Route::put('/support/{ticket:uuid}', [CustomerSupportTicketController::class, 'update'])->middleware('permission:portal.support.update')->name('support.update');
    Route::post('/support/{ticket:uuid}/resolve', [CustomerSupportTicketController::class, 'resolve'])->middleware('permission:portal.support.resolve')->name('support.resolve');
    Route::post('/support/{ticket:uuid}/messages', [CustomerSupportTicketController::class, 'message'])->middleware('permission:portal.support.reply')->name('support.messages.store');
    Route::get('/support-attachments/{attachment:uuid}', SupportAttachmentController::class)->middleware('permission:support.view|portal.support.view')->name('support.attachments.show');
    Route::prefix('admin')->name('admin.')->middleware('throttle:120,1')->group(function (): void {
        Route::get('/notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'index'])->middleware('permission:notification.manage')->name('notifications.index');
        Route::get('/notifications/recipients', [\App\Http\Controllers\Admin\NotificationController::class, 'recipients'])->middleware('permission:notification.manage')->name('notifications.recipients');
        Route::get('/notifications/role-recipients', [\App\Http\Controllers\Admin\NotificationController::class, 'roleRecipients'])->middleware('permission:notification.manage')->name('notifications.role-recipients');
        Route::get('/notifications/progress', [\App\Http\Controllers\Admin\NotificationController::class, 'progress'])->middleware('permission:notification.manage')->name('notifications.progress');
        Route::post('/notifications', [\App\Http\Controllers\Admin\NotificationController::class, 'store'])->middleware(['permission:notification.manage', 'throttle:admin-notification-send'])->name('notifications.store');
        Route::post('/notification-templates', [\App\Http\Controllers\Admin\NotificationController::class, 'storeTemplate'])->middleware('permission:notification.manage')->name('notification-templates.store');
        Route::put('/notification-templates/{template}', [\App\Http\Controllers\Admin\NotificationController::class, 'updateTemplate'])->middleware('permission:notification.manage')->name('notification-templates.update');
        Route::delete('/notification-templates/{template}', [\App\Http\Controllers\Admin\NotificationController::class, 'destroyTemplate'])->middleware('permission:notification.manage')->name('notification-templates.destroy');
        Route::get('/partner-commissions', [\App\Http\Controllers\Admin\PartnerCommissionController::class, 'index'])->middleware('permission:partner.commission.manage')->name('partners.commissions');
        Route::put('/partner-commissions/{product:slug}', [\App\Http\Controllers\Admin\PartnerCommissionController::class, 'update'])->middleware('permission:partner.commission.manage')->name('partners.commissions.update');
        Route::put('/partner-point-value', [\App\Http\Controllers\Admin\PartnerCommissionController::class, 'updatePointValue'])->middleware('permission:partner.commission.manage')->name('partners.point-value.update');
        Route::put('/partner-network-policy', [\App\Http\Controllers\Admin\PartnerCommissionController::class, 'updateNetworkPolicy'])->middleware('permission:partner.commission.manage')->name('partners.network-policy.update');
        Route::post('/partner-network-commissions/{commission}/paid', [\App\Http\Controllers\Admin\PartnerCommissionController::class, 'markNetworkCommissionPaid'])->middleware('permission:partner.commission.manage')->name('partners.network-commissions.paid');
        Route::get('/partners', [App\Http\Controllers\Admin\PartnerController::class, 'index'])->middleware('permission:partner.manage')->name('partners.index');
        Route::put('/partners/{application}', [App\Http\Controllers\Admin\PartnerController::class, 'update'])->middleware('permission:partner.manage')->name('partners.update');
        Route::patch('/partners/{application}/details', [App\Http\Controllers\Admin\PartnerController::class, 'updateDetails'])->middleware('permission:partner.manage')->name('partners.details.update');
        Route::delete('/partners/{application}', [App\Http\Controllers\Admin\PartnerController::class, 'destroy'])->middleware('permission:partner.manage')->name('partners.destroy');
        Route::put('/organization-upgrades/{upgrade}', [App\Http\Controllers\Admin\PartnerController::class, 'reviewOrganizationUpgrade'])->middleware('permission:partner.manage')->name('partners.organization-upgrades.review');
        Route::get('/organization-upgrades/{upgrade}/documents/{document}', [App\Http\Controllers\Admin\PartnerController::class, 'downloadOrganizationDocument'])->middleware('permission:partner.manage')->name('partners.organization-upgrades.documents');
        Route::get('/billing-settings', [\App\Http\Controllers\Admin\BillingSettingsController::class, 'edit'])->middleware('permission:billing.manage')->name('billing.edit');
        Route::put('/billing-settings', [\App\Http\Controllers\Admin\BillingSettingsController::class, 'update'])->middleware('permission:billing.manage')->name('billing.update');
        Route::get('/hosting', [HostingSettingsController::class, 'edit'])->middleware('permission:hosting.manage')->name('hosting.edit');
        Route::put('/hosting', [HostingSettingsController::class, 'update'])->middleware('permission:hosting.manage')->name('hosting.update');
        Route::get('/', AdminDashboardController::class)->middleware('permission:admin.dashboard')->name('dashboard');
        Route::middleware('permission:users.manage')->prefix('users')->name('users.')->group(function (): void {
            Route::get('/', [AdminUserManagementController::class, 'index'])->name('index');
            Route::get('/deleted', [AdminUserManagementController::class, 'deleted'])->name('deleted');
            Route::post('/{user:public_id}/restore', [AdminUserManagementController::class, 'restore'])->withTrashed()->name('restore');
            Route::put('/{user:public_id}', [AdminUserManagementController::class, 'update'])->name('update');
            Route::post('/{user:public_id}/password-reset', [AdminUserManagementController::class, 'sendPasswordResetLink'])->middleware('throttle:5,1')->name('password-reset');
            Route::delete('/{user:public_id}', [AdminUserManagementController::class, 'destroy'])->name('destroy');
        });
        Route::resource('staff', AdminStaffController::class)->only(['index', 'store', 'update'])->scoped(['staff' => 'public_id'])->middleware('permission:staff.manage');
        Route::get('/access-control', fn () => redirect()->route('admin.access-control.role-permissions'))->middleware('permission:access.manage')->name('access-control.index');
        Route::prefix('access-control')->name('access-control.')->middleware('permission:access.manage')->group(function (): void {
            Route::get('/roles', [AdminAccessControlController::class, 'rolesIndex'])->name('roles');
            Route::post('/roles', [AdminAccessControlController::class, 'storeRole'])->name('roles.store');
            Route::put('/roles/{role}', [AdminAccessControlController::class, 'updateRole'])->name('roles.update');
            Route::delete('/roles/{role}', [AdminAccessControlController::class, 'destroyRole'])->name('roles.destroy');

            Route::get('/permissions', [AdminAccessControlController::class, 'permissionsIndex'])->name('permissions');
            Route::post('/permissions', [AdminAccessControlController::class, 'storePermission'])->name('permissions.store');
            Route::put('/permissions/{permission}', [AdminAccessControlController::class, 'updatePermission'])->name('permissions.update');
            Route::delete('/permissions/{permission}', [AdminAccessControlController::class, 'destroyPermission'])->name('permissions.destroy');

            Route::get('/role-permissions', [AdminAccessControlController::class, 'rolePermissions'])->name('role-permissions');
            Route::put('/role-permissions/{role}', [AdminAccessControlController::class, 'updateRolePermissions'])->name('role-permissions.update');

            Route::get('/user-roles', [AdminAccessControlController::class, 'userRoles'])->name('user-roles');
            Route::put('/user-roles/{user:public_id}', [AdminAccessControlController::class, 'updateUserRoles'])->name('user-roles.update');

            Route::get('/user-permissions', [AdminAccessControlController::class, 'userPermissions'])->name('user-permissions');
            Route::put('/user-permissions/{user:public_id}', [AdminAccessControlController::class, 'updateUserPermissions'])->name('user-permissions.update');
            Route::delete('/user-permissions/{user:public_id}', [AdminAccessControlController::class, 'revokeUserPermissions'])->name('user-permissions.destroy');
        });
        Route::get('/support', [AdminSupportTicketController::class, 'index'])->middleware('permission:support.view')->name('support.index');
        Route::resource('support-teams', AdminSupportTeamController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('permission:support.manage');
        Route::post('/support-teams/{support_team}/members', [AdminSupportTeamController::class, 'addMember'])->middleware('permission:support.manage')->name('support-teams.members.store');
        Route::delete('/support-teams/{support_team}/members/{userPublicId}', [AdminSupportTeamController::class, 'removeMember'])->middleware('permission:support.manage')->name('support-teams.members.destroy');
        Route::get('/support/{ticket:uuid}', [AdminSupportTicketController::class, 'show'])->middleware('permission:support.view')->name('support.show');
        Route::put('/support/{ticket:uuid}', [AdminSupportTicketController::class, 'update'])->middleware('permission:support.update')->name('support.update');
        Route::post('/support/{ticket:uuid}/messages', [AdminSupportTicketController::class, 'message'])->middleware('permission:support.reply')->name('support.messages.store');
        Route::get('/demo-mode', [AdminDemoModeController::class, 'index'])->middleware('permission:demo.configure')->name('demo-mode.index');
        Route::put('/demo-mode/{profile:uuid}', [AdminDemoModeController::class, 'update'])->middleware('permission:demo.configure')->name('demo-mode.update');
        Route::get('/mcp', [AdminMcpController::class, 'index'])->middleware('permission:mcp.manage')->name('mcp.index');
        Route::post('/mcp/clients', [AdminMcpController::class, 'storeClient'])->middleware('permission:mcp.manage')->name('mcp.clients.store');
        Route::post('/mcp/credentials/{credential:uuid}/rotate', [AdminMcpController::class, 'rotateCredential'])->middleware('permission:mcp.manage')->name('mcp.credentials.rotate');
        Route::post('/mcp/credentials/{credential:uuid}/revoke', [AdminMcpController::class, 'revokeCredential'])->middleware('permission:mcp.manage')->name('mcp.credentials.revoke');
        Route::get('/licenses', [AdminLicenseManagementController::class, 'index'])->middleware('permission:license.manage')->name('licenses.index');
        Route::get('/licenses/{license:uuid}', [AdminLicenseManagementController::class, 'show'])->middleware('permission:license.manage')->name('licenses.show');
        Route::post('/licenses/{license:uuid}/codes', [AdminLicenseManagementController::class, 'issueCode'])->middleware('permission:license.manage')->name('licenses.codes.issue');
        Route::post('/licenses/{license:uuid}/codes/{activationCode:uuid}/revoke', [AdminLicenseManagementController::class, 'revokeCode'])->middleware('permission:license.manage')->name('licenses.codes.revoke');
        Route::post('/licenses/{license:uuid}/installations/{installation:uuid}/revoke', [AdminLicenseManagementController::class, 'revokeInstallation'])->middleware('permission:license.manage')->name('licenses.installations.revoke');
        Route::post('/payments/{payment:uuid}/verify', [AdminPaymentController::class, 'verify'])->middleware('permission:payment.verify')->name('payments.verify');
        Route::post('/payments/{payment:uuid}/reject', [AdminPaymentController::class, 'reject'])->middleware('permission:payment.reject')->name('payments.reject');
        Route::post('/payments/{payment:uuid}/refund', [AdminPaymentController::class, 'refund'])->middleware('permission:payment.refund')->name('payments.refund');
        Route::get('/payments/{payment:uuid}/receipt', [AdminPaymentController::class, 'receipt'])->middleware('permission:payment.view')->name('payments.receipt');
        Route::resource('payments', AdminPaymentController::class)->only(['index', 'create', 'store', 'show'])
            ->middlewareFor(['index', 'show'], 'permission:payment.view')
            ->middlewareFor('create', 'permission:payment.create')
            ->middlewareFor('store', 'permission:payment.create');
        Route::post('/invoices/{invoice:uuid}/issue', [AdminInvoiceController::class, 'issue'])->middleware('permission:invoice.issue')->name('invoices.issue');
        Route::post('/invoices/{invoice:uuid}/payments', [AdminInvoiceController::class, 'payment'])->middleware('permission:invoice.payment')->name('invoices.payments.store');
        Route::post('/invoices/{invoice:uuid}/cancel', [AdminInvoiceController::class, 'cancel'])->middleware('permission:invoice.cancel')->name('invoices.cancel');
        Route::resource('invoices', AdminInvoiceController::class)->except('destroy')
            ->middlewareFor(['index', 'show'], 'permission:invoice.view')
            ->middlewareFor(['create', 'store'], 'permission:invoice.create')
            ->middlewareFor(['edit', 'update'], 'permission:invoice.update');
        Route::post('/quotations/{quotation:uuid}/send', [AdminQuotationController::class, 'send'])->middleware('permission:quotation.send')->name('quotations.send');
        Route::post('/quotations/{quotation:uuid}/convert', [AdminQuotationController::class, 'convert'])->middleware('permission:quotation.convert')->name('quotations.convert');
        Route::resource('quotations', AdminQuotationController::class)
            ->middlewareFor(['index', 'show'], 'permission:quotation.view')
            ->middlewareFor(['create', 'store'], 'permission:quotation.create')
            ->middlewareFor(['edit', 'update'], 'permission:quotation.update')
            ->middlewareFor('destroy', 'permission:quotation.delete');
        Route::get('/leads', [AdminLeadController::class, 'index'])->middleware('permission:lead.view')->name('leads.index');
        Route::get('/leads/{lead:uuid}', [AdminLeadController::class, 'show'])->middleware('permission:lead.view')->name('leads.show');
        Route::put('/leads/{lead:uuid}', [AdminLeadController::class, 'update'])->middleware('permission:lead.update')->name('leads.update');
        Route::post('/leads/{lead:uuid}/notes', [AdminLeadController::class, 'storeNote'])->middleware('permission:lead.note')->name('leads.notes.store');
        Route::post('/leads/{lead:uuid}/convert', [AdminLeadController::class, 'convert'])->middleware('permission:lead.convert')->name('leads.convert');
        Route::put('/demo-requests/{demoRequest:uuid}', [AdminLeadController::class, 'updateDemo'])->middleware('permission:demo.manage')->name('demo-requests.update');
        Route::post('/customers/{customer:uuid}/notes', [AdminCustomerController::class, 'storeNote'])->middleware('permission:customer.note')->name('customers.notes.store');
        Route::resource('customers', AdminCustomerController::class)
            ->middlewareFor(['index', 'show'], 'permission:customer.view')
            ->middlewareFor(['create', 'store'], 'permission:customer.create')
            ->middlewareFor(['edit', 'update'], 'permission:customer.update')
            ->middlewareFor('destroy', 'permission:customer.delete');
        Route::resource('products', AdminProductController::class)
            ->middlewareFor(['index', 'show'], 'permission:product.view')
            ->middlewareFor(['create', 'store'], 'permission:product.create')
            ->middlewareFor(['edit', 'update'], 'permission:product.update')
            ->middlewareFor('destroy', 'permission:product.delete');
        Route::post('/products/{product:slug}/release', [AdminProductReleaseController::class, 'store'])->middleware('permission:product.update')->name('products.release.build');
        Route::get('/products/{product:slug}/plans', [AdminPlanController::class, 'index'])->middleware('permission:plan.view')->name('products.plans.index');
        Route::get('/products/{product:slug}/plans/create', [AdminPlanController::class, 'create'])->middleware('permission:plan.create')->name('products.plans.create');
        Route::post('/products/{product:slug}/plans', [AdminPlanController::class, 'store'])->middleware('permission:plan.create')->name('products.plans.store');
        Route::get('/plans/{plan:uuid}', [AdminPlanController::class, 'show'])->middleware('permission:plan.view')->name('plans.show');
        Route::get('/plans/{plan:uuid}/edit', [AdminPlanController::class, 'edit'])->middleware('permission:plan.update')->name('plans.edit');
        Route::put('/plans/{plan:uuid}', [AdminPlanController::class, 'update'])->middleware('permission:plan.update')->name('plans.update');
        Route::get('/plans/{plan:uuid}/entitlements', [AdminPlanController::class, 'entitlements'])->middleware('permission:plan.view')->name('plans.entitlements');
        Route::put('/plans/{plan:uuid}/entitlements', [AdminPlanController::class, 'publishEntitlements'])->middleware('permission:plan.update')->name('plans.entitlements.publish');
        Route::delete('/plans/{plan:uuid}', [AdminPlanController::class, 'destroy'])->middleware('permission:plan.delete')->name('plans.destroy');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->middleware('permission:profile.manage')->name('profile.edit');
    Route::get('/profile/photo', [ProfileController::class, 'photo'])->middleware('permission:profile.manage')->name('profile.photo');
    Route::post('/profile/photo', [ProfileController::class, 'updatePhoto'])->middleware(['permission:profile.manage', 'throttle:10,1'])->name('profile.photo.update');
    Route::post('/profile/document-assets/{kind}', [\App\Http\Controllers\DocumentAssetController::class, 'update'])->whereIn('kind', ['stamp', 'signature'])->middleware(['permission:admin.profile.manage', 'throttle:10,1'])->name('profile.document-assets.update');
    Route::delete('/profile/document-assets/{kind}', [\App\Http\Controllers\DocumentAssetController::class, 'destroy'])->whereIn('kind', ['stamp', 'signature'])->middleware(['permission:admin.profile.manage', 'throttle:10,1'])->name('profile.document-assets.destroy');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('permission:profile.manage')->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('permission:profile.manage')->name('profile.destroy');
});

require __DIR__.'/auth.php';
