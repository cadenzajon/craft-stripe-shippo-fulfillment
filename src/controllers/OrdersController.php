<?php

namespace cadenzajon\stripeshippo\controllers;

use cadenzajon\stripeshippo\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class OrdersController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission(Plugin::PERMISSION_MANAGE);
        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $ready = $plugin->stripeOrders->isConfigured();

        $orders = $ready ? $plugin->stripeOrders->getRecentOrders() : [];

        return $this->renderTemplate('stripe-shippo-fulfillment/orders/index', [
            'orders' => $orders,
            'ready' => $ready,
            'shippoReady' => $plugin->shippo->isConfigured(),
        ]);
    }

    public function actionImport(): Response
    {
        $this->requirePostRequest();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');
        $userId = Craft::$app->getUser()->getId();

        try {
            $shipment = Plugin::getInstance()->fulfillment->importToShippo($sessionId, $userId);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        $url = Plugin::getInstance()->shippo->appUrl($shipment->shippoOrderId);
        Craft::$app->getSession()->setNotice("Imported to Shippo. Buy the label: $url");

        return $this->redirectToPostedUrl();
    }
}
