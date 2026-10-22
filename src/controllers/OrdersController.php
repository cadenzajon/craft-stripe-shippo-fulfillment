<?php

namespace cadenzajon\stripeshippo\controllers;

use cadenzajon\stripeshippo\exceptions\UnfulfillableOrderException;
use cadenzajon\stripeshippo\records\Shipment;
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
        // Fulfillment is operational work, not a system configuration change,
        // so it remains available when allowAdminChanges is disabled.
        $this->requireAdmin(false);
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
        } catch (UnfulfillableOrderException $e) {
            return $this->failure($e->getMessage());
        } catch (\Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);
            return $this->failure('Shippo could not import this order. Check the logs and try again.');
        }

        if ($shipment->status === Shipment::STATUS_IMPORTED && $shipment->shippoOrderId) {
            $url = Plugin::getInstance()->shippo->appUrl($shipment->shippoOrderId);
            Craft::$app->getSession()->setNotice("Imported to Shippo. Buy the label: $url");
        } else {
            Craft::$app->getSession()->setNotice('This order is already being imported. Refresh in a moment.');
        }

        return $this->redirectToPostedUrl();
    }

    public function actionMarkShipped(): Response
    {
        $this->requirePostRequest();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');
        $userId = Craft::$app->getUser()->getId();

        try {
            $shipment = Plugin::getInstance()->fulfillment->markShipped($sessionId, $userId);
        } catch (UnfulfillableOrderException $e) {
            return $this->failure($e->getMessage());
        } catch (\Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);
            return $this->failure('Could not mark this order as shipped. Check the logs and try again.');
        }

        Craft::$app->getSession()->setNotice("Order #{$shipment->orderNumber} marked as shipped.");
        return $this->redirectToPostedUrl();
    }

    private function failure(string $message): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asFailure($message);
        }

        $this->setFailFlash($message);
        return $this->redirectToPostedUrl();
    }
}
