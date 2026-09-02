<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Seeder;

final class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['code' => 'ALIGNEX', 'name' => 'AlignEx', 'slug' => 'alignex', 'tagline' => 'AI-powered examination and assessment management.', 'short_description' => 'AI-powered examination and assessment management.', 'description' => 'AlignEx streamlines examination, assessment and CBT operations with dependable administration and decision-ready reporting.', 'industry' => 'Assessment', 'icon' => 'alignment', 'features' => ['Examination management', 'Assessment workflows', 'CBT operations', 'Performance reporting'], 'demo_url' => 'https://alignex.kernelbridge.com'],
            ['code' => 'EDUNEXA', 'name' => 'EduNexa', 'slug' => 'edunexa', 'tagline' => 'School and academy management platform.', 'short_description' => 'School and academy management platform.', 'description' => 'EduNexa helps schools and academies manage academic records, administration, finance and reporting from one secure platform.', 'industry' => 'Education', 'icon' => 'education', 'features' => ['Academic administration', 'Learner engagement', 'Finance workflows', 'Insightful reporting'], 'demo_url' => 'https://edunexa.kernelbridge.com'],
            ['code' => 'MEDIFLOW', 'name' => 'MediFlow', 'slug' => 'mediflow', 'tagline' => 'Healthcare and patient management platform.', 'short_description' => 'Healthcare and patient management platform.', 'description' => 'MediFlow connects patient records, billing, pharmacy, laboratory and staff workflows for coordinated healthcare operations.', 'industry' => 'Healthcare', 'icon' => 'healthcare', 'features' => ['Patient workflow coordination', 'Billing operations', 'Clinical support workflows', 'Care team collaboration'], 'demo_url' => 'https://mediflow.kernelbridge.com'],
            ['code' => 'MARKETLENS', 'name' => 'MarketLens', 'slug' => 'marketlens', 'tagline' => 'Sales, stock and business operations management.', 'short_description' => 'Sales, stock and business operations management.', 'description' => 'MarketLens brings sales, stock and business monitoring into a focused platform that gives owners clearer operational control.', 'industry' => 'Business / Retail', 'icon' => 'analytics', 'features' => ['Sales monitoring', 'Stock control', 'Business dashboards', 'Automated reporting'], 'demo_url' => 'https://marketlens.kernelbridge.com'],
            [
                'code' => 'STAYCONTROL', 'name' => 'StayControl', 'slug' => 'staycontrol',
                'tagline' => 'Smarter Hotel Operations. Controlled from One Platform.',
                'short_description' => 'Hotel operations, payments & smart room access.',
                'hero_description' => 'Connect reservations, payments, guest services, staff operations and room access through one integrated hotel management platform.',
                'seo_title' => 'StayControl – Smart Hotel Management & Room Access | KernelBridge Technologies',
                'seo_description' => 'StayControl by KernelBridge Technologies helps hotels manage reservations, payments, guest operations, staff accountability and smart room access from one integrated platform.',
                'description' => 'StayControl is a modern hotel automation platform designed to connect reservations, guest management, payments, room access, staff operations and management oversight within one secure system.',
                'industry' => 'Hospitality', 'icon' => 'hotel',
                'features' => ['Guest registration', 'Guest login', 'Room availability', 'Room booking', 'Reservation management', 'Payment management', 'Check-in', 'Check-out', 'Room assignment', 'Smart room access', 'Electronic key/card access', 'Access grant management', 'Access revocation', 'Staff operations', 'Shift management', 'Cash accountability', 'Payment reconciliation', 'Bank deposit tracking', 'Financial variance control', 'Owner/management monitoring', 'Audit trail', 'Guest self-service booking'],
                'benefits' => [
                    ['title' => 'Automate Hotel Operations', 'description' => 'Reduce manual operational processes across reception, rooms, payments and staff activities.'],
                    ['title' => 'Control Room Access', 'description' => 'Manage room access grants, key/card issuance and access revocation securely.'],
                    ['title' => 'Improve Financial Accountability', 'description' => 'Connect payments, staff shifts, reconciliation, deposits and variance monitoring.'],
                    ['title' => 'Enhance Guest Experience', 'description' => 'Allow guests to register, book, pay and prepare for hotel check-in digitally.'],
                    ['title' => 'Increase Management Visibility', 'description' => 'Give hotel management better visibility into rooms, payments, access activity and operational performance.'],
                ],
                'workflow' => ['Guest Reservation', 'Payment', 'Reservation Confirmed', 'Hotel Check-In', 'Room Assignment', 'Access Grant', 'Key/Card Encoding', 'Room Access', 'Checkout', 'Access Revoked'],
                'platform_components' => [
                    ['title' => 'Property Management Backend', 'description' => 'Central hotel operational management.'],
                    ['title' => 'Owner Dashboard', 'description' => 'Remote management oversight and monitoring.'],
                    ['title' => 'Desktop Hardware Bridge', 'description' => 'Connect the hotel management platform with supported access-control hardware.'],
                    ['title' => 'Guest Portal', 'description' => 'Allow guests to register, login, book rooms and make payments.'],
                    ['title' => 'Staff Operations', 'description' => 'Manage shifts, cash handling and operational accountability.'],
                    ['title' => 'Financial Control', 'description' => 'Support payment reconciliation, deposits and financial variance management.'],
                ],
                'demo_url' => 'https://staycontrol.kernelbridge.com', 'website_url' => 'https://staycontrol.kernelbridge.com',
            ],
        ];

        foreach ($products as $index => $product) {
            $model = Product::query()->updateOrCreate(
                ['slug' => $product['slug']],
                [...$product, 'category' => $product['industry'], 'sort_order' => $index + 1, 'is_featured' => true, 'status' => ProductStatus::Active],
            );

            $model->productFeatures()->delete();
            foreach ($product['features'] as $featureIndex => $feature) {
                $model->productFeatures()->create(['name' => $feature, 'sort_order' => $featureIndex, 'is_active' => true]);
            }

            $model->links()->delete();
            if (! empty($product['website_url'])) {
                $model->links()->create(['type' => 'website', 'label' => 'Product website', 'url' => $product['website_url'], 'sort_order' => 0, 'is_active' => true]);
            }
            if (! empty($product['demo_url'])) {
                $model->links()->create(['type' => 'demo', 'label' => 'Try demo', 'url' => $product['demo_url'], 'sort_order' => 1, 'is_active' => true]);
            }
        }
    }
}
