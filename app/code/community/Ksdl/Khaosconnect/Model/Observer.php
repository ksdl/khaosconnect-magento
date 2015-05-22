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


class Ksdl_Khaosconnect_Model_Observer 
{
    public function OnAfterDelete($observer)
    {
        $event = $observer->getEvent();
        $category = $observer->getCategory();
        Mage::log("Deleting entity_id: " . $category->getId());
        $resource = Mage::getSingleton('core/resource');
        $db = $resource->getConnection('core_write');
        $table = $resource->getTableName('khaosconnect_catalog_category_link');
        $sql = "delete from $table where entity_id = {$category->getId()} or path like '%/{$category->getId()}%'";
        $db->query($sql);
    }
    
    public function OnBeforeSaveOrder($observer)
    {
        $orderObj = $observer->getOrder();
        if ($orderObj->getId() && $orderObj->getOrigData() == null)
        {
            $resource = Mage::getSingleton('core/resource');
            $db = $resource->getConnection('core_write');
            $table = $resource->getTableName('khaosconnect_admin_order');
            
            $sql = 
                "insert into $table (entity_id) " .
                "select '{$orderObj->getEntityId()}' from DUAL " .
                "where not exists " .
                "( " .
                  "select * from $table where entity_id = '{$orderObj->getEntityId()}' " .
                ")";
            $db->query($sql);
        }
    }
    
    public function checkCronJobs()
    {
        $syncWebCategories = array();
        $syncOrders = array();
        $customersWebsiteIds = array();
        $plgWebsiteIds = array();
        $defaultPriceLists = array();
        $syncOrderStatuses = array();
        $syncKeycodes = false;
        $syncAllStockLevels = false;
        $systemValues = Mage::helper('khaosconnect/systemvalues');
        
        //Keycodes
        $autoUpdate = $systemValues->getSysValue($systemValues->kcAutoUpdatePrefix);
        $autoUpdateInterval = $systemValues->getSysValue($systemValues->kcAutoUpdateIntervalPrefix);
        $lastSync = $systemValues->getSysValue($systemValues->kcLastSyncPrefix);
        if ($this->isDue($autoUpdateInterval, $lastSync, $autoUpdate))
            $syncKeycodes = true;
        
        //All Stock Levels
        $autoUpdate = $systemValues->getSysValue($systemValues->slAutoUpdatePrefix);
        $autoUpdateInterval = $systemValues->getSysValue($systemValues->slAutoUpdateIntervalPrefix);
        $lastSync = $systemValues->getSysValue($systemValues->slLastSyncPrefix);
        if ($this->isDue($autoUpdateInterval, $lastSync, $autoUpdate))
            $syncAllStockLevels = true;
        
        //Price Lists
        $autoUpdate = $systemValues->getSysValue($systemValues->plgAutoUpdatePrefix);
        $autoUpdateInterval = $systemValues->getSysValue($systemValues->plgAutoUpdateIntervalPrefix);
        $lastSync = $systemValues->getSysValue($systemValues->plgLastSyncPrefix);
        if ($this->isDue($autoUpdateInterval, $lastSync, $autoUpdate))
            $plgWebsiteIds[] = "0";
        
        foreach (Mage::app()->getWebsites() as $website)
        {
            //Customers
            $autoUpdate = $systemValues->getSysValue($systemValues->cuAutoUpdatePrefix . $website->getId());
            $autoUpdateInterval = $systemValues->getSysValue($systemValues->cuAutoUpdateIntervalPrefix . $website->getId());
            $lastSync = $systemValues->getSysValue($systemValues->cuLastSyncPrefix . $website->getId());
            if ($this->isDue($autoUpdateInterval, $lastSync, $autoUpdate))
                $customersWebsiteIds[] = $website->getId();
            
            foreach ($website->getStores() as $store) 
            {
                //Stock Items / Web Categories
                $autoUpdate = $systemValues->getSysValue($systemValues->rcAutoUpdatePrefix . $store->getId());
                $autoUpdateInterval = $systemValues->getSysValue($systemValues->rcAutoUpdateIntervalPrefix . $store->getId());
                $lastSync = $systemValues->getSysValue($systemValues->rcLastSyncPrefix . $store->getId());
                if ($this->isDue($autoUpdateInterval, $lastSync, $autoUpdate))
                    $syncWebCategories[(string)$store->getId()] = $systemValues->rcPrefix . $store->getId();
                
                //Orders (Export to Khaos)
                $autoUpdate = $systemValues->getSysValue($systemValues->roAutoUpdatePrefix . $store->getId());
                $autoUpdateInterval = $systemValues->getSysValue($systemValues->roAutoUpdateIntervalPrefix . $store->getId());
                $lastSync = $systemValues->getSysValue($systemValues->roLastSyncPrefix . $store->getId());
                if ($this->isDue($autoUpdateInterval, $lastSync, $autoUpdate))
                    $syncOrders[] = $store->getId();
                
                //Default Prices
                $autoUpdate = $systemValues->getSysValue($systemValues->rcDefaultPriceListAutoUpdatePrefix . $store->getId());
                $autoUpdateInterval = $systemValues->getSysValue($systemValues->rcDefaultPriceListAutoUpdateIntervalPrefix . $store->getId());
                $lastSync = $systemValues->getSysValue($systemValues->rcDefaultPriceListLastSyncPrefix . $store->getId());
                if ($this->isDue($autoUpdateInterval, $lastSync, $autoUpdate))
                    $defaultPriceLists[] = $store->getId();
                
                //Order Status
                $autoUpdate = $systemValues->getSysValue($systemValues->rsAutoUpdatePrefix . $store->getId());
                $autoUpdateInterval = $systemValues->getSysValue($systemValues->rsAutoUpdateIntervalPrefix . $store->getId());
                $lastSync = $systemValues->getSysValue($systemValues->rsLastSyncPrefix . $store->getId());
                if ($this->isDue($autoUpdateInterval, $lastSync, $autoUpdate))
                    $syncOrderStatuses[] = $store->getId();
            }
        }
        
        $stockObj = Mage::helper("khaosconnect/stock");            
        $orderObj = Mage::helper("khaosconnect/order");            
        $customerObj = Mage::helper("khaosconnect/customer");   
        
        try
        {
            //Stock / Web Categories
            $stockObj->doStockSync($syncWebCategories);
            $stockObj->doDefaultPricesSync($defaultPriceLists);
            if (!empty($syncWebCategories) || !empty($defaultPriceLists))
            {
                $this->reindexProcess(2, true);
            }
        }
        catch(Exception $e)
        {
            $this->logException($e->getMessage());
        }
        
        
        //Orders
        if (!empty($syncOrders))
        {
            try
            {
                $customerObj->doCustomerExport(array_unique($customersWebsiteIds));        
                $orderObj->doOrderExport($syncOrders);
            }
            catch(Exception $e)
            {
                $this->logException($e->getMessage());
            }
        }       
        
        //Customers
        if (!empty($customersWebsiteIds))
            $customersWebsiteIds[] = 0; 
        try
        {
            $customerObj->doCustomerExport($customersWebsiteIds);        
            $customerObj->doCustomerImport($customersWebsiteIds);
        }
        catch(Exception $e)
        {
            $this->logException($e->getMessage());
        }
        
        foreach($customersWebsiteIds as $websiteId) {
            $systemValues->setSysValue($systemValues->cuLastSyncPrefix . $websiteId, time());
        }
        
        try
        {
            //Grouped Price Lists
            $customerObj->doGroupedPriceListImport($plgWebsiteIds);
        }
        catch(Exception $e)
        {
            $this->logException($e->getMessage());
        }
        
        if (!empty($syncOrderStatuses))
        {
            try
            {
                $orderObj->doOrderStatusSync($syncOrderStatuses);  
            }
            catch(Exception $e)
            {
                $this->logException($e->getMessage());
            }
        }
        
        //Keycodes
        if ($syncKeycodes)
        {
            try
            {
                $orderObj->doKeycodeSync();
            }
            catch(Exception $e)
            {
                $this->logException($e->getMessage());
            }
        }
        
        //All Stock Levels
        if ($syncAllStockLevels)
        {
            try
            {
                $stockObj->syncAllStockLevels();
                $systemValues->setSysValue($systemValues->slLastSyncPrefix, time());
                $this->reindexProcess(8, true);
            }
            catch(Exception $e)
            {
                $this->logException($e->getMessage());
            }
        }
        
        unset($stockObj);
        unset($customerObj);
        unset($orderObj);
        
        return;
    } 
    
    private function logException($message)
    {
        $fileName = Mage::getBaseDir('log') . '/Khaosconnect.log';
        if (is_array($message) || is_object($message)) 
            $message = print_r($message, true);
        
        $message = '[' . date('Y-m-d H:i:s', time()) . ']:: ' . $message; 
        
        if (!file_exists($fileName))
            file_put_contents($fileName, $message);
        else    
        {
            $content = file_get_contents($fileName, true) . PHP_EOL . $message;
            file_put_contents($fileName, $content);
        }
    }
    
    private function isDue($autoUpdateInterval, $lastSync, $autoUpdate)
    {
        $timeCheck = strtotime('+' . $autoUpdateInterval . ' minutes', $lastSync);
        if ($autoUpdate == '-1' && ($timeCheck <= time() || !$lastSync)) //Correct time or never sync'd before.
            return true;
        else
            return false;
    }
    
    private function reindexProcess($id, $force)
    {
        $systemValues = Mage::helper('khaosconnect/systemvalues');
        
        if ($systemValues->getSysValue($systemValues->opReindexStock) == "-1")
        {
            $process = Mage::getModel('index/process')->load($id);
        
            if ($process->getStatus() == Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX || $force)
            {
                $process->reindexEverything();
            }
        }
    }
}
