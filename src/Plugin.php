<?php

namespace cadenzajon\stripeshippo;

use cadenzajon\stripeshippo\models\Settings;
use cadenzajon\stripeshippo\services\Fulfillment;
use cadenzajon\stripeshippo\services\Notifications;
use cadenzajon\stripeshippo\services\Shippo;
use cadenzajon\stripeshippo\services\StripeOrders;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\stripe\events\StripeEvent;
use craft\stripe\services\Webhooks as StripeWebhooks;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * @property-read StripeOrders $stripeOrders
 * @property-read Shippo $shippo
 * @property-read Fulfillment $fulfillment
 * @property-read Notifications $notifications
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_MANAGE = 'stripe-shippo-fulfillment:manage';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'stripeOrders' => StripeOrders::class,
                'shippo' => Shippo::class,
                'fulfillment' => Fulfillment::class,
                'notifications' => Notifications::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->controllerNamespace = 'cadenzajon\\stripeshippo\\controllers';

        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerStripeListener();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('stripe-shippo-fulfillment/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Fulfillment';
        $item['url'] = 'stripe-shippo-fulfillment';
        return $item;
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['stripe-shippo-fulfillment'] = 'stripe-shippo-fulfillment/orders/index';
                $event->rules['stripe-shippo-fulfillment/orders'] = 'stripe-shippo-fulfillment/orders/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Stripe → Shippo Fulfillment',
                    'permissions' => [
                        self::PERMISSION_MANAGE => [
                            'label' => 'Manage fulfillment and buy shipping labels',
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * The free craftcms/stripe plugin receives all Stripe webhooks and re-fires
     * them as EVENT_STRIPE_EVENT, so we listen there rather than registering our
     * own Stripe endpoint. Only Shippo needs its own webhook controller.
     */
    private function registerStripeListener(): void
    {
        Event::on(
            StripeWebhooks::class,
            StripeWebhooks::EVENT_STRIPE_EVENT,
            function(StripeEvent $event) {
                // Immediate card payments arrive as checkout.session.completed;
                // delayed methods (e.g. ACH) complete unpaid and later fire
                // checkout.session.async_payment_succeeded.
                $handled = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];
                if (!in_array($event->stripeEvent->type, $handled, true)) {
                    return;
                }

                $session = $event->stripeEvent->data->object;

                // Act only once the session is actually paid, so a delayed
                // payment does not import or email at the unpaid 'completed' step.
                if (($session->payment_status ?? null) === 'unpaid') {
                    return;
                }

                try {
                    $shipment = null;
                    if ($this->getSettings()->autoImportToShippo) {
                        $shipment = $this->fulfillment->importToShippo($session->id);
                    }
                    $this->notifications->sendAdminOrderEmail($session->id, $shipment);
                } catch (\Throwable $e) {
                    Craft::error(
                        "Fulfillment webhook failed for {$session->id}: {$e->getMessage()}",
                        __METHOD__,
                    );
                }
            }
        );
    }
}
