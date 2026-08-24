<?php

namespace Database\Seeders;

use App\Models\SubscriptionPackage;
use Illuminate\Database\Seeder;

class SubscriptionPackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // All 3 tiers ship with the same 10 core features - Starter/Pro/Business only differ on
        // price and LenaAI token allowance, not on feature access. '*' marks a feature every role
        // can use; anything else lists exactly which roles see it in the app today (see the
        // navItems/header wiring in frontend/src/App.tsx).
        $features = [
            ['key' => 'freight_exchange', 'title' => 'Freight Exchange (Berza tereta)', 'description' => 'Browse and post loads on the open freight marketplace to match cargo with available capacity in real time.', 'icon' => 'Boxes', 'roles' => ['driver', 'company', 'superadmin', 'master']],
            ['key' => 'load_tracking', 'title' => 'Tracking My Loads', 'description' => 'Track every one of your shipments in real time, from pickup all the way to proof of delivery.', 'icon' => 'Package', 'roles' => ['user', 'driver', 'company', 'superadmin', 'master']],
            ['key' => 'live_map', 'title' => 'Live GPS Map', 'description' => 'See your fleet and active shipments moving on a live map at all times.', 'icon' => 'Map', 'roles' => ['user', 'driver', 'company', 'superadmin', 'master']],
            ['key' => 'fleet_management', 'title' => 'Fleet Management', 'description' => 'Register vehicles, assign drivers, and manage your entire fleet from one workspace.', 'icon' => 'Truck', 'roles' => ['driver', 'company', 'superadmin', 'master']],
            ['key' => 'lena_ai', 'title' => 'LenaAI Assistant', 'description' => 'An AI dispatcher that scans documents, drafts loads, and answers logistics questions instantly.', 'icon' => 'Sparkles', 'roles' => ['*']],
            ['key' => 'messaging', 'title' => 'Messages & Chat', 'description' => 'Coordinate directly with drivers, companies, and customers in one shared inbox.', 'icon' => 'MessageSquare', 'roles' => ['*']],
            ['key' => 'analytics', 'title' => 'Analytics Dashboard', 'description' => 'Track performance, revenue, and operational KPIs with real-time analytics.', 'icon' => 'BarChart3', 'roles' => ['user', 'driver', 'company', 'superadmin', 'master']],
            ['key' => 'network', 'title' => 'Business Network', 'description' => 'Discover and connect with verified carriers, shippers, and logistics partners.', 'icon' => 'Globe', 'roles' => ['user', 'driver', 'company', 'superadmin', 'master']],
            ['key' => 'invoicing', 'title' => 'Invoicing & Finance', 'description' => 'Generate invoices, track payments, and manage the financial side of every shipment.', 'icon' => 'Banknote', 'roles' => ['company', 'finance', 'superadmin', 'master']],
            ['key' => 'team_management', 'title' => 'Team & Permissions', 'description' => 'Invite teammates, assign roles, and control who can access what inside your company workspace.', 'icon' => 'Users', 'roles' => ['company', 'superadmin', 'master']],
        ];

        $packages = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'tagline' => 'Get moving with the essentials.',
                'price_monthly' => 90.00,
                'currency' => 'BAM',
                'lena_ai_tokens' => 1000,
                'icon' => 'Rocket',
                'color' => 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                'features' => $features,
                'is_popular' => false,
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'tagline' => 'More AI power for growing teams.',
                'price_monthly' => 150.00,
                'currency' => 'BAM',
                'lena_ai_tokens' => 3000,
                'icon' => 'Gem',
                'color' => 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
                'features' => $features,
                'is_popular' => true,
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'tagline' => 'Full-scale logistics for high-volume operations.',
                'price_monthly' => 250.00,
                'currency' => 'BAM',
                'lena_ai_tokens' => 10000,
                'icon' => 'Building2',
                'color' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                'features' => $features,
                'is_popular' => false,
                'sort_order' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($packages as $data) {
            SubscriptionPackage::query()->updateOrCreate(['slug' => $data['slug']], $data);
        }

        $this->command?->info('Subscription packages seeded (Starter, Pro, Business).');
    }
}
