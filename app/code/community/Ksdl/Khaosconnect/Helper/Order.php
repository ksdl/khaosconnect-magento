<?php

/**
Copyright 2015 Keystone Software Development Ltd.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
 **/

class Ksdl_Khaosconnect_Helper_Order extends Ksdl_Khaosconnect_Helper_Basehelper
{
    const cAdressTypeInv = -1;
    const cAdressTypeDel = 0;
    
    protected $processingStoreId;
    
    function __construct()
    {
        parent::__construct();
    }   
     
    public function doOrderExport($storeIds)
    {
        $this->setAction("Order Export");
        $this->setCode("NULL");
        
        foreach ($storeIds as $storeId)
        {
            $this->processingStoreId = $storeId;
            
            $lastSyncTime = $this->systemValues->getSysValue($this->systemValues->roLastSyncPrefix . $this->processingStoreId);
            $statusToExport = $this->systemValues->getSysValue('export_stage_name');
            $statusToUpdateTo = $this->systemValues->getSysValue('export_stage_name_update');
            $statusToUpdateOnHold = $this->systemValues->getSysValue('export_stage_name_on_hold');
            $newStateValueArray = Mage::getSingleton('core/resource')->
                getConnection('core_read')->
                select()->
                from(
                    Mage::getSingleton('core/resource')->getTableName('sales/order_status_state'),
                    array('state')
                )->
                where('status = ?', $statusToUpdateTo)->
                query()->
                fetch();
            $stateToUpdateTo = $newStateValueArray['state'];
            
            $adminTable = $this->resource->getTableName('khaosconnect_admin_order');
            $orders = Mage::getModel('sales/order')->getCollection()
                ->addFieldToFilter('status', $statusToExport)
                ->addFieldToFilter('store_id', $this->processingStoreId)
                ->addFieldToFilter('updated_at', array('gteq' => date('Y-m-d H:i:s', $lastSyncTime)))
                ->addFieldToFilter('kc_exported', array('null' => true)); 
            
            //Doing a clone here because I want a total order count but doing count($orders) 
            //will cause the collection to load and in turn it won't apply the left join later on.
            //Not using clone will cause both the vars collection to be loaded as they use the same reference.
            $countOrdersObjs = clone $orders;
            $totalOrderCount = count($countOrdersObjs);
            unset($countOrdersObjs);
            
            $orders->getSelect()
                ->joinLeft(array("t1" => $adminTable), "main_table.entity_id = t1.entity_id", array("admin_field_id" => "t1.id"));
                //->where("t1.id is null");
                      
            $nonAdminOrderCount = count($orders);
            $adminOrderCount = $totalOrderCount - $nonAdminOrderCount;
            
            $message = 'Export Orders (' . $statusToExport . '): ' . $nonAdminOrderCount . ' order(s) to export.'; 
            if ($adminOrderCount > 0)
                $message .= ' Skipping ' . $adminOrderCount . ' admin created order(s).';
            
            $this->dbLog('', parent::cLogTypeOrder, $message, '', parent::cLogStatusSuccess);
            
            foreach ($orders as $orderObj)
            {          
                $incrementId = $orderObj->getIncrementId();
                $entityId = $orderObj->getEntityId();
                $this->setCode($incrementId);
                
                try
                {
                    $orderXmlStr = $this->exportOrder($orderObj);
                    $orderResult = Mage::helper('khaosconnect/webservice')->importOrder($orderXmlStr);
                    if ($orderResult->ImportedCount > 0)
                    {
                        $this->setOrderStatus($orderObj, $statusToUpdateTo, "Transferred to Khaos Control.");
                        $this->updateExportedFlag($orderObj->getId());
                    }
                    else
                    {
                        $this->setOrderStatus($orderObj, $statusToUpdateOnHold, "Skipped Khaos Import. Check Logs.");
                    }
                    $this->logOrder($orderResult, $entityId, $incrementId);
                    $this->systemValues->setSysValue($this->systemValues->roLastSyncPrefix . $this->processingStoreId, time());
                }
                catch(Exception $e)
                {
                    $this->setOrderStatus($orderObj, $statusToUpdateOnHold, "Failed to import into Khaos. Check Logs.");
                    $this->logOrder($e, $entityId, $incrementId);
                }
            }
            
            if (count($orders) == 0)
                $this->systemValues->setSysValue($this->systemValues->roLastSyncPrefix . $this->processingStoreId, time());
        }
    }
    
    protected function updateExportedFlag($id)
    {
        $sql = "update sales_flat_order set kc_exported = 1 where entity_id = " . $this->writeDB->quote($id); 
        $this->writeDB->beginTransaction();
        $this->writeDB->query($sql);
        $this->writeDB->commit();
    }
    
    public function setOrderStatus($orderObj, $status, $message)
    {
        $orderObj->setState($status, $status, $message, false)->save();
    }
    
    public function doOrderStatusSync($storeIds)
    {
        $this->setAction("Order Status Sync");
        $this->setCode("");
        
        foreach ($storeIds as $storeId)
        {
            $associatedRefs = array();
            $lastSyncTime = $this->systemValues->getSysValue($this->systemValues->rsLastSyncPrefix . $storeId);
            $orders = Mage::getModel('sales/order')->getCollection()
                    ->addFieldToFilter('store_id', $storeId)
                    ->addAttributeToFilter('status', array('neq' => 'complete'));
            
            foreach ($orders as $orderObj)
                $associatedRefs[] = $orderObj->getIncrementId();
            
            $statuses = Mage::helper('khaosconnect/webservice')->exportOrderStatus($associatedRefs);
            if (!empty($statuses))
            {                
                $sql = "select status, state from " . $this->resource->getTableName('sales_order_status_state');                
                $query = $this->readDB->query($sql);            
                $states = array();
                while ($row = $query->fetch())
                    $states[$row['status']] = $row['state'];
        
                foreach ($statuses as $status)
                {
                    $incrementId = (string)$status->attributes()->ASSOCIATED_REF;
                    $orderObj->load($incrementId, 'increment_id');
                    $currentOrderState = $orderObj->getState();
                    
                    foreach ($status->INVOICES->INVOICE as $invoice)
                    {
                        $stateCode = "kc_" . $invoice->attributes()->INVOICE_STAGE_ID;   
                                                
                        if (array_key_exists($stateCode, $states)) //Look for a specific mapping that the user has set up.
                        {
                            $state = $states[$stateCode];
                            switch ($state)
                            {
                                case "complete": 
                                    $orderObj->addStatusToHistory(Mage_Sales_Model_Order::STATE_COMPLETE)->save();
                                    break;
                                case "closed": 
                                    $orderObj->addStatusToHistory(Mage_Sales_Model_Order::STATE_CLOSED)->save();
                                    break;
                                default:
                                    if ($state != $currentOrderState)
                                        $orderObj->setState($state, $stateCode, "State Updated by Khaos Connect", false)->save();
                                    break;
                            }
                        }
                        else //Otherwise revert to what we think it should be based on the stage in Khaos.
                        {
                            $stateSet = false;
                            $state = "";
                            
                            switch ((int)$invoice->attributes()->INVOICE_STAGE_ID)
                            {
                                case 1: //Cancelled
                                    $orderObj->addStatusToHistory(Mage_Sales_Model_Order::STATE_CLOSED)->save();
                                    $stateSet = true;
                                    break;
                                case 16: //Issue Invoices
                                case 70: //Issued Current
                                case 71: //Issued A1
                                case 72: //Issued A2
                                case 73: //Issued A3
                                    $orderObj->addStatusToHistory(Mage_Sales_Model_Order::STATE_COMPLETE)->save();
                                    $stateSet = true;
                                    break;
                                case 17: //Future Date
                                case 18: //Future Stock
                                case 19: //Manual Hold
                                case 21: //Account Terms
                                    $state = Mage_Sales_Model_Order::STATE_HOLDED;
                                    break;
                                case 12: //Picking
                                case 13: //Packing
                                case 14: //Shipping
                                case 15: //Invoice Print
                                case 20: //Processing
                                    $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                                    break;
                                case 9: //Released
                                case 10: //Staging
                                    $state = Mage_Sales_Model_Order::STATE_NEW;
                                    break;
                                case 11: //Authorise Payment
                                    $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
                                    break;                                
                            }
                            
                            if (!$stateSet && $state != $currentOrderState)
                                $orderObj->setState($state, true, "State Updated by Khaos Connect", false)->save();
                        }
                    }
                }
            }
            
            $this->systemValues->setSysValue($this->systemValues->rsLastSyncPrefix . $storeId, time());
        }
    }
    
    public function doKeycodeSync()
    {
        $keycodes = Mage::helper('khaosconnect/webservice')->getKeycodes();
        $this->setAction("Keycode");
        
        foreach ($keycodes->KEYCODES->KEYCODE as $keycode)
        {
            $couponCode = $this->getPropS($keycode, "CODE");
            $this->setCode($couponCode);
            
            try
            {
                if ($keycode->WEB_USE == "-1")
                {
                    $existingCustomerGroupIds = array();
                    $coupon = Mage::getModel('salesrule/coupon')->load($couponCode, "code");
                    $rule = Mage::getModel('salesrule/rule');
                    if ($coupon->getId())
                    {
                        $rule->load($coupon->getRuleId());
                        $existingCustomerGroupIds = $rule->getData("customer_group_ids");
                    }
                    
                    $data = array(
                        "name"              => $this->getPropS($keycode, "DESCRIPTION"),
                        "description"       => $this->getPropS($keycode, "DESCRIPTION"),
                        "coupon_code"       => $couponCode,
                        "coupon_type"       => "2",
                        "from_date"         => date('Y-m-d', strtotime($this->getPropS($keycode, "KEYCODE_DATE"))),
                        "to_date"           => date('Y-m-d', strtotime($this->getPropS($keycode, "EXPIRY_DATE"))),
                        "is_active"         => "1",
                        "store_labels"      => array($this->getPropS($keycode, "DESCRIPTION")),
                        "product_ids"       => "",
                        "website_ids"       => array(),
                        "sort_order"        => "",
                        "is_rss"            => "0",
                        "stop_rules_processing" => "0"
                    );
                
                    $companyClass = $this->getPropS($keycode, "COMPANY_CLASS");
                    if ($companyClass == "Unknown")
                        $companyClass = "NOT LOGGED IN";
                    
                    if (!empty($companyClass))
                    {
                        $customerGroupId = $groupObj = Mage::getModel('customer/group')->load($companyClass, 'customer_group_code')->getId();
                        $data["customer_group_ids"] = array_unique(array_merge($existingCustomerGroupIds, array($customerGroupId)));
                    }
                
                    //Order Discount
                    if (!empty($keycode->DISCOUNTS))
                    {
                        $orderDiscount = $keycode->DISCOUNTS->DISCOUNT[0];              
                        $data["simple_action"] = "by_percent";
                        $data["discount_amount"] = $this->getPropS($orderDiscount->attributes(), "PCDISCOUNT");                   
                        $data["discount_qty"] = "0";
                        $data["discount_step"] = "0";
                    
                        $data["conditions"]["1"] = array(
                            "type"          => "salesrule/rule_condition_combine",
                            "aggregator"    => "all",
                            "value"         => "1"
                        );
                    
                        $data["conditions"]["1--1"] = array(
                            "type"          => "salesrule/rule_condition_address",
                            "attribute"     => "base_subtotal",
                            "operator"      => ">=",
                            "value"         => $this->getPropS($orderDiscount->attributes(), "ORDERLOW")
                        );
                    
                        $data["conditions"]["1--2"] = array(
                            "type"          => "salesrule/rule_condition_address",
                            "attribute"     => "base_subtotal",
                            "operator"      => "<=",
                            "value"         => $this->getPropS($orderDiscount->attributes(), "ORDERHIGH")
                        );
                    
                        $data["actions"]["1"] = array(
                            "type"          => "salesrule/rule_condition_combine",
                            "aggregator"    => "all",
                            "value"         => "1"
                        );
                    }
                
                    $rule->loadPost($data);
                    $rule->save();
                }
            }
            catch(Exception $e)
            {
                $message = "
                    Coupon: {" . $couponCode . "}<br>
                    Error: {" . $e->getMessage() . "}
                ";
                $this->dbLog("0", parent::cLogTypeKeycode, $message, "0", parent::cLogStatusFailed);
            }
        }
        
        $this->systemValues->setSysValue($this->systemValues->kcLastSyncPrefix, time());
    }
    
    protected function logOrder($result, $entityId, $incrementId)
    {
        $type = parent::cLogTypeOrder;
        
        if ($result instanceof Exception)
        {
            $message = "
                Order: {" . $incrementId . "}<br>
                Error: {" . $result->getMessage() . "}
            ";
            $status = parent::cLogStatusFailed;
        }
        else
        {
            $importedCount = $result->ImportedCount;
            if ($importedCount > 0)
            {
                $importedOrder = $result->OrderImport[0];
                
                $salesOrderCode = $importedOrder->SalesOrderCode;
                $message = "Order: {" . $incrementId . "} to Khaos {" . $salesOrderCode . "}";
                $status = parent::cLogStatusSuccess;
            }
            else
            {
                $message = "No orders imported {" . $result->OrderSkipped[0]->OrderRef . " :: " . $result->OrderSkipped[0]->SkipReason . "}";
                $status = parent::cLogStatusSuccess;
            }
        }
        
        $this->dbLog($entityId, $type, $message, $incrementId, $status);
    }
    
    protected function exportOrder($orderObj)
    {
        $xmlSalesOrders = new SimpleXMLElement('<SALES_ORDERS></SALES_ORDERS>');
        $xmlSalesOrder = $xmlSalesOrders->addChild('SALES_ORDER');
        $this->exportOrderCustomerDetail($orderObj, $xmlSalesOrder);
        $this->exportOrderHeader($orderObj, $xmlSalesOrder);
        $this->exportOrderItems($orderObj, $xmlSalesOrder);
        $this->exportOrderPayment($orderObj, $xmlSalesOrder);
        
        return $xmlSalesOrders->asXML();
    }
    
    protected function exportOrderPayment($orderObj, &$xmlSalesOrder)
    {
        $paymentData = $this->getPaymentData($orderObj->getPayment()->getMethodInstance()->getCode(), $orderObj);
        if ($paymentData)
            $this->exportPayment($xmlSalesOrder, $paymentData);
    }
    
    protected function getPaymentData($type, $orderObj)
    {
        $accountNumber = $this->systemValues->getSysValue($this->systemValues->opCCKhaosAccountNumber) != "" 
            ? $this->systemValues->getSysValue($this->systemValues->opCCKhaosAccountNumber)
            : "0";
        
        $sql = '';
        switch ($type)
        {
            case "ekashu":
                $sql = "select ";
                $sql .= "'" . $type . "' as type, ";
                $sql .= "'' as card_number, ";
                $sql .= "ekashu_auth_code as auth_code, ";
                $sql .= "ekashu_threed_secure_xid as preauth_ref, ";
                $sql .= "ekashu_transaction_id as transaction_id, ";
                $sql .= "concat(ekashu_card_reference, '#', ekashu_card_hash) as security_ref, ";
                $sql .= "ekashu_card_reference as card_ref, ";
                $sql .= "'' as account_name, ";
                $sql .= "'' as account_number, ";
                $sql .= "'' as status, ";
                $sql .= "'' as tx_type ";
                $sql .= "from " . $this->resource->getTableName('ekashu') . " where order_id = ";
                break;
            case "datacash_api":
                $sql = "select ";
                $sql .= "'" . $type . "' as type, ";
                $sql .= "'' as card_number, ";
                $sql .= "cc_approval as auth_code, ";
                $sql .= "'' as preauth_ref, ";
                $sql .= "concat(cc_status, '/', last_trans_id, '/', cc_status_description) as transaction_id, ";
                $sql .= "'' as security_ref, ";
                $sql .= "'' as account_name, ";
                $sql .= "'' as account_number, ";
                $sql .= "'' as status, ";
                $sql .= "'' as tx_type ";
                $sql .= "from " . $this->resource->getTableName('sales_flat_order_payment') . " where parent_id = ";
                break;
            case "sagepaydirectpro":
            case "sagepayserver":
            case "sagepayserver_moto":
                $sql = "select ";
                $sql .= "'" . $type . "' as type, ";
                $sql .= "'' as card_number, ";
                $sql .= "tx_auth_no as auth_code, ";
                $sql .= "vendor_tx_code as preauth_ref, ";
                $sql .= "vps_tx_id as transaction_id, ";
                $sql .= "security_key as security_ref, ";
                $sql .= "'" . $this->systemValues->getSysValue($this->systemValues->opCCKhaosAccountName) . "' as account_name, ";
                $sql .= "'" . $accountNumber . "' as account_number, ";
                $sql .= "status, ";
                $sql .= "tx_type ";
                $sql .= "from " . $this->resource->getTableName('sagepaysuite_transaction') . " where order_id = ";
                break;
            case "paypal_express":
            case "paypal_standard":
            case "paypal_advanced":
                $sql = "select";
                $sql .= "'" . $type . "' as type, ";
                $sql .= "'PAYPAL' as card_number, ";
                $sql .= "'PAYPAL' as auth_code, ";
                $sql .= "'' as preauth_ref, ";
                $sql .= "last_trans_id as transaction_id, ";
                $sql .= "'' as security_ref, ";
                $sql .= "'" . $this->systemValues->getSysValue($this->systemValues->opPayPalKhaosAccountName) . "' as account_name, ";
                $sql .= "'" . $this->systemValues->getSysValue($this->systemValues->opPayPalKhaosAccountNumber) . "' as account_number, ";
                $sql .= "'' as status, ";
                $sql .= "'' as tx_type ";
                $sql .= "from " . $this->resource->getTableName('sales_flat_order_payment') . " where parent_id = ";
                break;
            case "checkmo": $sql = ""; break;
        }
        
        if ($sql == '')
            return false;
        
        $sql .= $orderObj->getEntityId();
        return $this->mapPaymentArray($orderObj, $sql);
    }
    
    protected function mapPaymentArray($orderObj, $sql)
    {
        $query = $this->readDB->query($sql);
        if ($query->rowCount() == 0)
            return false;
        
        $result = array();
        $result['payment_amount'] = (string)$orderObj->getGrandTotal();
        $result['payment_type'] = '2';
        $preAuth = false;
        
        while ($row = $query->fetch())
        {
            $result['auth_code'] = $row['auth_code'];
            if ($row['auth_code'] == "")
                $preAuth = true;            
            
            $result['preauth_ref'] = $row['preauth_ref'];
            $result['transaction_id'] = $row['transaction_id'];
            $result['security_ref'] = $row['security_ref'];
            $result['card_number'] = $row['card_number'];
            $result['account_name'] = $row['account_name'];
            $result['account_number'] = $row['account_number'];
            if ($row['tx_type'] == "AUTHENTICATE")
                $result['auth_code'] = $row['status'];
        }
        
        $result['preauth'] = $preAuth ? "-1" : "0";
        
        return $result;
    }
    
    protected function exportPayment(&$xmlSalesOrder, $paymentData)
    {
        $xmlOrderPayments = $xmlSalesOrder->addChild('PAYMENTS');
        $xmlOrderPaymentDetail = $xmlOrderPayments->addChild('PAYMENT_DETAIL');
        foreach ($paymentData as $key => $value)
            $xmlOrderPaymentDetail->addChild(strtoupper($key), $value);
        
    }
    
    protected function exportOrderItems($orderObj, &$xmlSalesOrder)
    {
        $xmlOrderItems = $xmlSalesOrder->addChild('ORDER_ITEMS');
        foreach ($orderObj->getAllItems() as $itemObj)
        {
            $parentId = $itemObj->getParentItemId();
            if (!isset($parentId)) //We don't want to add children to the import as Khaos will do this automatically.
                $this->exportItem($itemObj, $xmlOrderItems);
        }
    }
    
    protected function exportItem($itemObj, &$xmlOrderItems)
    {
        $xmlOrderItem = $xmlOrderItems->addChild('ORDER_ITEM');
        $xmlOrderItem->STOCK_CODE = $itemObj->getSku();
        $xmlOrderItem->MAPPING_TYPE = '1'; //Match on stock code.
        //Don't send down the description and Magento uses parent desc for configurable children. Let Khaos sort the descriptions out.
        //$xmlOrderItem->STOCK_DESC = $itemObj->getProduct()->getName();
        $xmlOrderItem->ORDER_QTY = floor($itemObj->getQtyOrdered());
        if ($itemObj->getTaxAmount() == 0)
            $xmlOrderItem->PRICE_NET = $itemObj->getPriceInclTax();
        else
            $xmlOrderItem->PRICE_GRS = $itemObj->getPriceInclTax();      
        $xmlOrderItem->KSD_DISCOUNT = $itemObj->getDiscountPercent();      
    }
    
    protected function exportOrderHeader($orderObj, &$xmlSalesOrder)
    {
        $xmlOrderHeader = $xmlSalesOrder->addChild('ORDER_HEADER');
        $xmlOrderHeader->ORDER_AMOUNT = (string)$orderObj->getGrandTotal();
        $xmlOrderHeader->ORDER_CURRENCY = $orderObj->getOrderCurrencyCode();
        $xmlOrderHeader->ASSOCIATED_REF = $orderObj->getIncrementId();
        $xmlOrderHeader->DELIVERY_GRS = (string)$orderObj->getShippingInclTax();
        $xmlOrderHeader->PO_NUMBER = ((string)$orderObj->getKcPoNumber() == "") ? substr($orderObj->getOrderRefNo(),0,30) : substr((string)$orderObj->getKcPoNumber(),0,30);
        $xmlOrderHeader->ORDER_NOTE = ((string)$orderObj->getKcOrderNote() == "") ? $orderObj->getShippingArrivalComments() : (string)$orderObj->getKcOrderNote();
        $xmlOrderHeader->INV_PRIORITY = (string)$orderObj->getKcInvoicePriority();
        $xmlOrderHeader->SITE = (string)$orderObj->getKcStockSite();
        $xmlOrderHeader->KEYCODE_CODE = (string)$orderObj->getCouponCode();
        
        if ((string)$xmlOrderHeader->PO_NUMBER == "")
            $xmlOrderHeader->PO_NUMBER = substr($orderObj->getPayment()->getPoNumber(),0,30);
        
        $brand = $this->systemValues->getSysValue($this->systemValues->roBrandPrefix . $this->processingStoreId);
        if (!empty($brand))
            $xmlOrderHeader->BRAND = $brand;
        
        $salesSource = $this->systemValues->getSysValue($this->systemValues->roSalesSource . $this->processingStoreId);
        if (!empty($salesSource))
            $xmlOrderHeader->SALES_SOURCE = $salesSource;
        
        $site = $this->systemValues->getSysValue($this->systemValues->roSitePrefix . $this->processingStoreId);
        if (!empty($site) && ($xmlOrderHeader->SITE == null || $xmlOrderHeader->SITE == "")) //If it's been set via an attribute then don't overwrite it.
            $xmlOrderHeader->SITE = $site;
        
        $orderTimeStamp = strtotime($orderObj->getCreatedAtDate());
        if ($orderTimeStamp)
            $xmlOrderHeader->ORDER_DATE = date('Y-m-d\TH:i:s', $orderTimeStamp);        
    }
    
    protected function exportOrderCustomerDetail($orderObj, &$xmlSalesOrder)
    {
        $customerObj = Mage::getModel('customer/customer')->load($orderObj->getCustomerId());
        
        $xmlCustomerDetail = $xmlSalesOrder->addChild('CUSTOMER_DETAIL');
        $xmlCustomerDetail->IS_NEW_CUSTOMER = '0'; //Let Khaos do the matching.
        $xmlCustomerDetail->COMPANY_CODE = $customerObj->getKcCompanyCode(); //If we have it, provide it.
        //Don't set the other ref as the order will try to macth on this INSTEAD of the web username which isn't right.
        //$xmlCustomerDetail->OTHER_REF = $customerObj->getId();
        $xmlCustomerDetail->WEB_USER = $orderObj->getCustomerEmail();
        $xmlCustomerDetail->COMPANY_CLASS = Mage::getModel('customer/group')->load($orderObj->getCustomerGroupId())->getCode();
        $xmlCustomerDetail->COMPANY_NAME = $orderObj->getBillingAddress()->getName();
        
        $this->exportOrderAddresses($orderObj, $xmlCustomerDetail, $customerObj);
    }
    
    protected function exportOrderAddresses($orderObj, &$xmlCustomerDetail, $customerObj)
    {
        $xmlAddresses = $xmlCustomerDetail->addChild('ADDRESSES');
        $this->exportOrderAddress($orderObj, self::cAdressTypeInv, $xmlAddresses, $customerObj);
        $this->exportOrderAddress($orderObj, self::cAdressTypeDel, $xmlAddresses, $customerObj);
    }
    
    protected function exportOrderAddress($orderObj, $addressType, &$xmlAddresses, $customerObj)
    {
        //Tags are formatted like <IADDRESS1> or <DADDRESS1>
        //Also <INVADDR> or <DELADDR>
        if ($addressType == self::cAdressTypeInv)
        {
            $addressTypeField = 'INVADDR'; 
            $ap = 'I'; 
            $addressObj = $orderObj->getBillingAddress();
        }
        else if ($addressType == self::cAdressTypeDel) //Just to be sure incase we come up with another address type
        {
            $addressTypeField = 'DELADDR';
            $ap = 'D';            
            $addressObj = $orderObj->getShippingAddress();
        }
        
        $xmlAddress = $xmlAddresses->addChild($addressTypeField);
        
        //If the user has specified a company name against the delivery address 
        //then switch things around a little so that company name becomes address1 and so on.
        $addressLines = $addressObj->getStreet();
        if ($addressType == self::cAdressTypeDel && $addressObj->getCompany() != "")
        {
            $currentAddressLines = $addressLines;
            
            $addressLines[0] = $addressObj->getCompany();
            $addressLines[1] = $currentAddressLines[0];
            if (count($currentAddressLines) > 1)
                $addressLines[2] = $currentAddressLines[1];
        }
        
        $count = 1;
        foreach ($addressLines as $addressLine)
        {
            $xmlAddress->{$ap . 'ADDRESS' . $count} = $addressLine;
            $count++;
        }
            
        $xmlAddress->{$ap . 'TOWN'} = $addressObj->getCity();
        $xmlAddress->{$ap . 'COUNTY'} = $addressObj->getRegion();
        $postcode = ($addressObj->getPostcode() == "") ? "." : $addressObj->getPostcode();
        $xmlAddress->{$ap . 'POSTCODE'} = $postcode;
        $xmlAddress->{$ap . 'COUNTRY_NAME'} = Mage::getModel('directory/country')->load($addressObj->getCountryId())->getName();
        $xmlAddress->{$ap . 'TEL'} = $addressObj->getTelephone();
        
        $xmlAddress->{$ap . 'TITLE'} = $addressObj->getPrefix();
        $xmlAddress->{$ap . 'FORENAME'} = $addressObj->getFirstname();
        $xmlAddress->{$ap . 'SURNAME'} = $addressObj->getLastname();
        $xmlAddress->{$ap . 'EMAIL'} = $orderObj->getCustomerEmail();
    }
}
