<?php

namespace App\Services\Admin;

use App\Http\Resources\Api\V1\AdminProfileResource;
use App\Models\User;
use App\Services\RestaurantSettingsService;

class AdminBootstrapService
{
    public function __construct(private AdminNavigationService $navigation, private RestaurantSettingsService $settings) {}

    public function get(User $user): array
    {
        $profile = (new AdminProfileResource($user))->resolve();

        return ['admin' => $profile, 'roles' => $profile['roles'], 'permissions' => $profile['permissions'], 'navigation' => $this->navigation->get($user),
            'restaurant' => ['name' => $this->settings->getString('restaurant.name'), 'currency' => $this->settings->getString('restaurant.currency'), 'timezone' => $this->settings->getString('restaurant.timezone')],
            'features' => ['inventory_enabled' => true, 'scheduled_orders_enabled' => $this->settings->getBoolean('orders.allow_scheduled_orders'), 'delivery_enabled' => $this->settings->getBoolean('delivery.enabled'), 'pickup_enabled' => $this->settings->getBoolean('pickup.enabled'), 'cms_enabled' => true, 'contact_messages_enabled' => false]];
    }
}
