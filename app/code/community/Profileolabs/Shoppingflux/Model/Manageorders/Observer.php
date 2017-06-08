<?php

class Profileolabs_Shoppingflux_Model_Manageorders_Observer
{
    /**
     * @var array
     */
    protected $_trackingUrlCallbacks = array(
        'owebia' => '_extractOwebiaTrackingUrl',
        'dpdfrclassic' => '_extractDpdTrackingUrl',
        'dpdfrpredict' => '_extractDpdTrackingUrl',
        'dpdfrrelais' => '_extractDpdTrackingUrl',
    );

    /**
     * @return Profileolabs_Shoppingflux_Model_Config
     */
    public function getConfig()
    {
        return Mage::getSingleton('profileolabs_shoppingflux/config');
    }

    /**
     * @return Profileolabs_Shoppingflux_Helper_Data
     */
    public function getHelper()
    {
        return Mage::helper('profileolabs_shoppingflux');
    }

    /**
     * @param Varien_Event_Observer $observer
     */
    public function setCustomerTaxClassId($observer)
    {
        if (!$this->getConfig()->applyTax()
            && Mage::registry('is_shoppingfeed_import')
            && ($customerGroup = $observer->getEvent()->getData('object'))
            && ($customerGroup instanceof Varien_Object)
        ) {
            $customerGroup->setData('tax_class_id', 999);
        }
    }

    /**
     * @param Varien_Object $trackingInfo
     * @return string
     */
    protected function _extractOwebiaTrackingUrl(Varien_Object $trackingInfo)
    {
        return preg_match('%href="(.*?)"%i', $trackingInfo->getStatus(), $matches)
            ? $matches[1]
            : '';
    }

    /**
     * @param Varien_Object $trackingInfo
     * @return string
     */
    protected function _extractDpdTrackingUrl(Varien_Object $trackingInfo)
    {
        return preg_match('%iframe src="(.*?)"%i', $trackingInfo->getStatus(), $matches)
            ? $matches[1]
            : '';
    }

    /**
     * @param Mage_Sales_Model_Order_Shipment_Track $shipmentTrack
     * @return string
     */
    protected function _getShipmentTrackingUrl($shipmentTrack)
    {
        $trackingUrl = '';

        if (preg_match('%^(owebia|(dpdfr)(classic|predict|relais))%i', $shipmentTrack->getCarrierCode(), $matches)
            && isset($this->_trackingUrlCallbacks[$matches[1]])
        ) {
            /** @var Mage_Shipping_Model_Config $shippingConfig */
            $shippingConfig = Mage::getSingleton('shipping/config');
            $carrierInstance = $shippingConfig->getCarrierInstance($shipmentTrack->getCarrierCode());

            if ($carrierInstance
                && ($trackingInfo = $carrierInstance->getTrackingInfo($shipmentTrack->getData('number')))
                && ($trackingInfo instanceof Varien_Object)
            ) {
                $trackingUrl = call_user_func(array($this, $this->_trackingUrlCallbacks[$matches[1]]), $trackingInfo);
            }
        }

        return $trackingUrl;
    }

    /**
     * @param Mage_Sales_Model_Order_Shipment $shipment
     * @return string|false
     */
    public function getShipmentTrackingNumber($shipment)
    {
        $result = false;
        $tracks = $shipment->getAllTracks();

        if (is_array($tracks) && !empty($tracks)) {
            $firstTrack = array_shift($tracks);

            if (trim($firstTrack->getData('number'))) {
                $result = array(
                    'trackUrl' => $this->_getShipmentTrackingUrl($firstTrack),
                    'trackId' => $firstTrack->getData('number'),
                    'trackTitle' => $firstTrack->getData('title'),
                );
            }
        }

        $dataObject = new Varien_Object(array('result' => $result, 'shipment' => $shipment));
        Mage::dispatchEvent('shoppingflux_get_shipment_tracking', array('data_object' => $dataObject));
        $result = $dataObject->getData('result');

        return $result;
    }

    /**
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function sendStatusCanceled($observer)
    {
        if (($order = $observer->getEvent()->getData('order'))
            && ($order instanceof Mage_Sales_Model_Order)
        ) {
            if (!$order->getFromShoppingflux()) {
                return $this;
            }

            $storeId = $order->getStoreId();
            $apiKey = $this->getConfig()->getApiKey($storeId);
            $wsUri = $this->getConfig()->getWsUri();
            $service = new Profileolabs_Shoppingflux_Model_Service($apiKey, $wsUri);

            $orderIdShoppingflux = $order->getOrderIdShoppingflux();
            $marketPlace = $order->getMarketplaceShoppingflux();

            try {
                $service->updateCanceledOrder(
                    $orderIdShoppingflux,
                    $marketPlace,
                    Profileolabs_Shoppingflux_Model_Service::ORDER_STATUS_CANCELED
                );
            } catch (Exception $e) {
            }

            $this->getHelper()->log(
                'Order ' . $orderIdShoppingflux . ' has been canceled. Information sent to ShoppingFlux.'
            );
        }
        return $this;
    }

    /**
     * @param Varien_Event_Observer $observer
     */
    public function scheduleShipmentUpdate($observer)
    {
        if (($shipment = $observer->getEvent()->getData('shipment'))
            && ($shipment instanceof Mage_Sales_Model_Order_Shipment)
        ) {
            /** @var Profileolabs_Shoppingflux_Model_Manageorders_Export_Shipments $exporter */
            $exporter = Mage::getModel('profileolabs_shoppingflux/manageorders_export_shipments');
            $exporter->scheduleShipmentExport($shipment->getId());
        }
    }

    public function sendScheduledShipments()
    {
        /** @var Profileolabs_Shoppingflux_Model_Mysql4_Manageorders_Export_Shipments_Collection $collection */
        $collection = Mage::getResourceModel('profileolabs_shoppingflux/manageorders_export_shipments_collection');

        foreach ($collection as $item) {
            try {
                /** @var Mage_Sales_Model_Order_Shipment $shipment */
                $shipment = Mage::getModel('sales/order_shipment');
                $shipment->load($item->getShipmentId());

                if ((Mage::app()->getStore()->getCode() == 'admin')
                    || (Mage::app()->getStore()->getId() == $shipment->getStoreId())
                ) {
                    $trackingInfos = $this->getShipmentTrackingNumber($shipment);

                    if ($trackingInfos || $shipment->getUpdatedAt() < $this->getConfig()->getShipmentUpdateLimit()) {
                        $this->sendStatusShipped($shipment);
                        $item->delete();
                    }
                }
            } catch (Exception $e) {
                if ($shipment->getId()) {
                    $message = 'Erreur de mise à jour de l\'expédition #'
                        . $shipment->getIncrementId()
                        . ' (commande #' . $shipment->getOrder()->getIncrementId() . ') : <br/>';
                } else {
                    $message = 'Erreur de mise à jour d\'une expédition : <br/>';
                }

                $message .= $e->getMessage();
                $message .= '<br/><br/> Merci de vérifier les infos de votre commandes '
                    . 'ou de contacter le support Shopping Flux ou celui de la place de marché';

                $this->getHelper()->notifyError($message);

                if ($item->getId()
                    && !preg_match('%Result is not Varien_Simplexml_Element%', $message)
                    && !preg_match('%Error in cURL request: connect.. timed out%', $message)
                ) {
                    try {
                        $item->delete();
                    } catch (Exception $e) {
                    }
                }
            }
        }
        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Shipment $shipment
     * @return $this
     */
    public function sendStatusShipped($shipment)
    {
        if (!$shipment->getId()) {
            return $this;
        }

        $order = $shipment->getOrder();
        $storeId = $order->getStoreId();
        $apiKey = $this->getConfig()->getApiKey($storeId);
        $wsUri = $this->getConfig()->getWsUri();

        if (!$order->getFromShoppingflux()) {
            return $this;
        }
        if ($order->getShoppingfluxShipmentFlag()) {
            return $this;
        }

        $trackingInfos = $this->getShipmentTrackingNumber($shipment);
        $orderIdShoppingflux = $order->getOrderIdShoppingflux();
        $marketplace = $order->getMarketplaceShoppingflux();

        $service = new Profileolabs_Shoppingflux_Model_Service($apiKey, $wsUri);

        $result = $service->updateShippedOrder(
            $orderIdShoppingflux,
            $marketplace,
            Profileolabs_Shoppingflux_Model_Service::ORDER_STATUS_SHIPPED,
            $trackingInfos ? $trackingInfos['trackId'] : '',
            $trackingInfos ? $trackingInfos['trackTitle'] : '',
            $trackingInfos ? $trackingInfos['trackUrl'] : ''
        );


        if ($result) {
            if ($result->Response->Orders->Order->StatusUpdated == 'False') {
                Mage::throwException('Error in update status shipped to shopping flux');
            } else {
                $status = $result->Response->Orders->Order->StatusUpdated;

                $order->setShoppingfluxShipmentFlag(1);
                $order->save();

                $this->getHelper()->log(
                    $this->getHelper()->__(
                        'Order %s has been updated in ShoppingFlux. Status returned : %s',
                        $orderIdShoppingflux,
                        $status
                    )
                );
            }
        } else {
            $message = $this->getHelper()->__('Error in update status shipped to ShoppingFlux');
            $this->getHelper()->log($message);
            Mage::throwException($message);
        }

        return $this;
    }

    /**
     * @param Varien_Event_Observer $observer
     */
    public function observeSalesOrderPlaceAfter($observer)
    {
        if (($order = $observer->getEvent()->getData('order'))
            && ($order instanceof Mage_Sales_Model_Order)
        ) {
            $idTracking = $this->getConfig()->getIdTracking();

            if (!$idTracking || !$order || !$order->getId()) {
                return;
            }

            try {
                if (version_compare(Mage::getVersion(), '1.6.0') > 0) {
                    if (!$order->getRemoteIp() || $order->getFromShoppingflux()) {
                        return;
                    }
                } else if ($order->getFromShoppingflux()) {
                    return;
                }

                /** @var Mage_Core_Helper_Http $httpHelper */
                $httpHelper = Mage::helper('core/http');
                $ip = $order->getRemoteIp() ? $order->getRemoteIp() : $httpHelper->getRemoteAddr(false);
                $grandTotal = $order->getBaseGrandTotal();
                $incrementId = $order->getIncrementId();

                $tagUrl = 'https://tag.shopping-flux.com/order/'
                    . base64_encode($idTracking . '|' . $incrementId . '|' . $grandTotal)
                    . '?ip=' . $ip;

                file_get_contents($tagUrl);
            } catch (Exception $ex) {
            }
        }
    }

    /**
     * @param Varien_Event_Observer $observer
     */
    public function observeAdminhtmlBlockHtmlBefore($observer)
    {
        if (($block = $observer->getEvent()->getData('block'))
            && ($block instanceof Mage_Adminhtml_Block_Sales_Order_View)
        ) {
            if (method_exists($block, 'addButton')
                && $block->getOrderId()
                && $block->getOrder()
                && !$block->getOrder()->getShoppingfluxShipmentFlag()
                && $block->getOrder()->getFromShoppingflux()
                && $block->getOrder()->hasShipments()
            ) {
                /** @var Mage_Adminhtml_Helper_Data $adminHelper */
                $adminHelper = Mage::helper('adminhtml');

                $notifyUrl = $adminHelper->getUrl(
                    'adminhtml/shoppingfeed_order_import/sendShipment',
                    array('order_id' => $block->getOrder()->getId())
                );

                $block->addButton(
                    'shoppingflux_shipment',
                    array(
                        'label' => $this->getHelper()->__('Send notification to ShoppingFeed'),
                        'onclick' => 'setLocation(\'' . $notifyUrl . '\');',
                        'class' => 'shoppingflux-shipment-notification',
                    ),
                    0
                );
            }
        }
    }

    public function updateMarketplaceList()
    {
        $apiKey = false;

        foreach (Mage::app()->getStores() as $store) {
            if (!$apiKey) {
                $apiKey = $this->getConfig()->getApiKey($store->getId());
            }
        }
        if (!$apiKey) {
            return;
        }

        $wsUri = $this->getConfig()->getWsUri();
        $service = new Profileolabs_Shoppingflux_Model_Service($apiKey, $wsUri);

        try {
            $marketplaces = $service->getMarketplaces();

            if (count($marketplaces) > 5) {
                $marketplaceCsvFile = Mage::getModuleDir('', 'Profileolabs_Shoppingflux')
                    . DS
                    . 'etc'
                    . DS
                    . 'marketplaces.csv';

                $handle = fopen($marketplaceCsvFile, 'w+');

                foreach ($marketplaces as $marketplace) {
                    fwrite($handle, $marketplace . "\n");
                }

                fclose($handle);
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }
}
