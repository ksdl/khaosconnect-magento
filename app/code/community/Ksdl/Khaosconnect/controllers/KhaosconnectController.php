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

class Ksdl_Khaosconnect_KhaosconnectController extends Mage_Adminhtml_Controller_Action
{
    private $request;
    private $post;
    private $resource;
    private $systemValues;
    
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('khaosconnect');
    }
    
    public function indexAction()
    {
        $this->loadLayout();
        $this->_title($this->__("Khaos Web Sync"));
        $this->_addContent($this->getLayout()->createBlock('khaosconnect/adminhtml_formheader'))
            ->_addLeft($this->getLayout()->createBlock('khaosconnect/adminhtml_form_edit_tabs'));
        $this->renderLayout();
    }
    
    public function saveAction()
    {
        $this->setupPostBack();
        try 
        {
            $this->updateSettings();
                        
            $message = $this->__('Your settings have been updated.');
            Mage::getSingleton('adminhtml/session')->addSuccess($message);
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }
    }
    
    public function syncAction()
    {
        register_shutdown_function(array($this, "fatalErrorHandler"));
        $this->setupPostBack();
        try 
        {
            $this->syncStores();
            
            $message = $this->__('Your data has been synchronised successfully.');
            Mage::getSingleton('adminhtml/session')->addSuccess($message);
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }
    }
    
    public function resetStockSyncDateAction()
    {
        try 
        {
            $sql = "update core_config_data set value = 0 where path like 'ksdl%root_cat_last_sync%'";
            $resource = Mage::getSingleton('core/resource');
            $write = $resource->getConnection('core_write');
            $write->query($sql);
            
            $message = $this->__('Stock sync dates have been reset.');
            Mage::getSingleton('adminhtml/session')->addSuccess($message);
        }
        catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }
    }
    
    function fatalErrorHandler() {
        $error = error_get_last();

        if($error !== null) {
            if (!empty($_SESSION['ProcessingStockCode']))
            {
                $stockCode = $_SESSION['ProcessingStockCode'];
                $this->logFatalError("Unhandled Exception - Stock Code: " . $stockCode);
                $this->logFatalError($error);
                $stockObj = Mage::helper("khaosconnect/stock"); 
                $stockObj->removeStockCodeFromLockFile($stockCode, $_SESSION['ProcessingStoreId']);           
            }
            else if (!empty($_SESSION['GeneralFatalErrorCode']) && !empty($_SESSION['GeneralFatalErrorAction']))
            {
                $this->logFatalError("Unhandled Exception [" . $_SESSION['GeneralFatalErrorAction'] . "] :: [" . $_SESSION['GeneralFatalErrorCode'] . "]");
                $this->logFatalError($error);
            }
        }
        return;
    }
    
    private function logFatalError($message)
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
    
    private function setupPostBack()
    {
        $this->request = Mage::app()->getRequest();
        $this->post = $this->request->getPost();
        $this->resource = Mage::getSingleton('core/resource');
        $this->systemValues = Mage::helper("khaosconnect/systemvalues");
        
        if (empty($this->request)) 
            Mage::throwException($this->__('Invalid form data.'));
    }
    
    private function updateSettings()
    {
        //General
        $this->setParam("web_service_url");
        $this->setParam("export_stage_name");
        $this->setParam("export_stage_name_update");
        $this->setParam("export_stage_name_on_hold");
        $this->setParam($this->systemValues->cuAutoUpdatePrefix);
        $this->setParam($this->systemValues->cuAutoUpdateIntervalPrefix);
        $this->setParam($this->systemValues->plgAutoUpdatePrefix);
        $this->setParam($this->systemValues->plgAutoUpdateIntervalPrefix);
        $this->setParam($this->systemValues->opUpdateStockImages);
        $this->setParam($this->systemValues->opReindexStock);
        $this->setParam($this->systemValues->opUpdateStockDescriptions);
        $this->setParam($this->systemValues->opPayPalKhaosAccountName);
        $this->setParam($this->systemValues->opPayPalKhaosAccountNumber);
        $this->setParam($this->systemValues->opCCKhaosAccountName);
        $this->setParam($this->systemValues->opCCKhaosAccountNumber);
        $this->setParam($this->systemValues->kcAutoUpdatePrefix);
        $this->setParam($this->systemValues->kcAutoUpdateIntervalPrefix);
        $this->setParam($this->systemValues->slAutoUpdatePrefix);
        $this->setParam($this->systemValues->slAutoUpdateIntervalPrefix);
        $this->clearConfigCacheAfterUpdate();
        
        //Per Website
        $this->updateWebsiteSettings();
        $this->clearConfigCacheAfterUpdate();
    }
    
    private function updateWebsiteSettings()
    {
        //Update website wide settings.
        foreach (Mage::app()->getWebsites() as $website) 
        {
            $this->setParam($this->systemValues->opUpdateStockWebsite, $website);
            $this->setParam($this->systemValues->cuAutoUpdatePrefix, $website);
            $this->setParam($this->systemValues->cuAutoUpdateIntervalPrefix, $website);
            
            foreach ($website->getStores() as $store)
            {
                $this->updateWebsiteStoreRootCategory($store);
                $this->updateWebsiteStoreSettings($store);
            }
        }
    }
    
    private function updateWebsiteStoreSettings($store)
    {
        $this->setParam($this->systemValues->rcDefaultPriceList, $store);
        $this->setParam($this->systemValues->rcDefaultPriceListAutoUpdatePrefix, $store);
        $this->setParam($this->systemValues->rcDefaultPriceListAutoUpdateIntervalPrefix, $store);
        $this->setParam($this->systemValues->rcAutoUpdatePrefix, $store);
        $this->setParam($this->systemValues->rcAutoUpdateIntervalPrefix, $store);
        $this->setParam($this->systemValues->roAutoUpdatePrefix, $store);
        $this->setParam($this->systemValues->roAutoUpdateIntervalPrefix, $store);        
        $this->setParam($this->systemValues->rsAutoUpdatePrefix, $store);
        $this->setParam($this->systemValues->rsAutoUpdateIntervalPrefix, $store);        
        $this->setParam($this->systemValues->roBrandPrefix, $store);            
        $this->setParam($this->systemValues->roSalesSource, $store);        
        $this->setParam($this->systemValues->roSitePrefix, $store);   
        $this->setParam($this->systemValues->rcLastSyncPrefix, $store, true);        
        $this->setParam($this->systemValues->cuLastSyncPrefix, $store, true);        
        $this->setParam($this->systemValues->ohLastSyncPrefix, $store, true);        
        $this->setParam($this->systemValues->roLastSyncPrefix, $store, true);        
    }
    
    private function setParam($param, $obj = null, $convertToTimeStamp = false)
    {
        $field = ($obj != null) ? $param . $obj->getId() : $param;
        if ($convertToTimeStamp === true)
            $this->systemValues->setSysValue($field, strtotime($this->request->getParam($field)));
        else
            $this->systemValues->setSysValue($field, $this->request->getParam($field));
    }
    
    private function updateWebsiteStoreRootCategory($store)
    {
        $param = $this->systemValues->rcPrefix . $store->getId();
        $this->systemValues->setSysValue($param, $this->request->getParam($param));
        
        $rootCategoryName = $this->request->getParam($param);
        if ($rootCategoryName != '')
        {
            $rootCategoryId = Mage::helper("khaosconnect/category")->getCategoryId($rootCategoryName);
            if (!$rootCategoryId) //If the category doesn't already exist then sync so that it can set the Id correctly.
            {
                $stockObj = Mage::helper("khaosconnect/stock");            
                $stockObj->getStockCategoryList('', true);
                $rootCategoryId = Mage::helper("khaosconnect/category")->getCategoryId($rootCategoryName);
            }

            $store->setRootCategoryId($rootCategoryId)->save();
        }
    }
    
    private function syncStores()
    {
        $syncWebCategories = array();
        $syncOrders = array();
        $orderWebsiteIds = array();
        $customersWebsiteIds = array();
        $plgWebsiteIds = array();
        $syncSelectedStockCodes = array();
        $syncStockLevels = false;
        $defaultPrices = array();
        $syncOrderStatuses = array();
        $syncKeycodes = false;
        $syncOrderHistory = false;
        $customersPerWebsite = Mage::getStoreConfig('customer/account_share/scope') == '1';
        
        $postValue = 'stocklevels_' . $this->systemValues->rcPrefixStockSync;
        $syncStockLevels = isset($this->post[$postValue]);
        
        $postValue = 'keycodes_' . $this->systemValues->kcPrefix;
        $syncKeycodes = isset($this->post[$postValue]);
        
        $postValue = 'orderhistory_' . $this->systemValues->kcPrefix;
        $syncOrderHistory = isset($this->post[$postValue]);
        
        $postValue = 'plgsync_' . $this->systemValues->plgPrefix;
        if (isset($this->post[$postValue]))
            $plgWebsiteIds[] = "0";
        
        //Build a list of websites that need updating
        //Do this because the same Action is used for updating settings as it is for sync'ing.
        foreach (Mage::app()->getWebsites() as $website)
        {
            $postValue = ($customersPerWebsite) ? 
                'customersync_' . $this->systemValues->cuPrefix . $website->getId() :
                $postValue = 'customersync_' . $this->systemValues->cuPrefix;
            
            if (isset($this->post[$postValue]))
                $customersWebsiteIds[] = $website->getId();
            
            foreach ($website->getStores() as $store)
            {
                $postValue = 'stocksync_' . $this->systemValues->rcPrefix . $store->getId();
                if (isset($this->post[$postValue]))
                    $syncWebCategories[(string)$store->getId()] = $this->post[$postValue];
                
                $postValue = 'stocksync_' . $this->systemValues->rcPrefix . $store->getId() . '_selected';
                if (isset($this->post[$postValue]) && $this->post[$postValue] != '')
                    $syncSelectedStockCodes[(string)$store->getId()] = $this->post[$postValue];
                
                $postValue = 'ordersync_' . $this->systemValues->roPrefix . $store->getId();
                if (isset($this->post[$postValue]))
                {
                    $syncOrders[] = $store->getId();
                    $orderWebsiteIds[] = $website->getId();
                }
                
                $postValue = 'defaultpricesync_' . $this->systemValues->rcDefaultPriceList . $store->getId();
                if (isset($this->post[$postValue]) && $this->post[$postValue] != '')
                    $defaultPrices[] = $store->getId();
                
                $postValue = 'orderstatussync_' . $this->systemValues->rsPrefix . $store->getId();
                if (isset($this->post[$postValue]))
                    $syncOrderStatuses[] = $store->getId();
                    
            }
        }
        
        $stockObj = Mage::helper("khaosconnect/stock");            
        $customerObj = Mage::helper("khaosconnect/customer");   
        $orderObj = Mage::helper("khaosconnect/order");            
        
        //Stock / Web Categories [STORE]
        $stockObj->doStockSync($syncWebCategories, $syncSelectedStockCodes);
        if ((!empty($syncWebCategories)) && ($this->systemValues->getSysValue($this->systemValues->opReindexStock) == "-1"))
        {
            $this->reindexAll();
        }
        
        if ($syncStockLevels)
        {
            $stockObj->syncAllStockLevels();
            $this->reindexProcess(8, true);
        }
        
        //Orders [STORE]
        if (!empty($syncOrders))
        {
            //Not doing this anymore as the order export routine will update customer details as required.
            /*try
            {
                $customerObj->doCustomerExport(array_unique($orderWebsiteIds));        
            } 
            catch(Exception $ex) 
            {
                //Don't stop the order export if something goes wrong with the customer export.
            }*/
            
            $orderObj->doOrderExport($syncOrders);
        }
        
        if (!empty($syncOrderStatuses))
        {
            $orderObj->doOrderStatusSync($syncOrderStatuses);
        }
        
        if ($syncKeycodes)
            $orderObj->doKeycodeSync();
        
        if ($syncOrderHistory)
            $orderObj->importOrders();
        
        //Customers  [WEBSITE / GLOBAL]
        
        //Some customers don't have a website assigned to them so add 0 here if we are sync'ing customers.
        if (!empty($customersWebsiteIds))
            $customersWebsiteIds[] = 0; 
        //$customerObj->doCustomerExport($customersWebsiteIds); //Not doing this anymore as the order export routine can update the customer information when required.
        $customerObj->doCustomerImport($customersWebsiteIds);
        
        foreach($customersWebsiteIds as $websiteId) {
            $this->systemValues->setSysValue($this->systemValues->cuLastSyncPrefix . $websiteId, time());
        }
        
        //Grouped Price Lists [GLOBAL]
        $customerObj->doGroupedPriceListImport($plgWebsiteIds);
        
        //Default Prices
        if (!empty($defaultPrices))
            $stockObj->doDefaultPricesSync($defaultPrices);
        
        unset($stockObj);
        unset($orderObj);
        unset($customerObj);
    }

    public function massDeleteAction()
    {
        $logIds = $this->getRequest()->getParam('logs');
        if (!is_array($logIds))
        {
             Mage::getSingleton('adminhtml/session')->addError(Mage::helper('adminhtml')->__('Please select customer(s).'));
        } else {
            try {
                $log = Mage::getModel('khaosconnect/logs');
                foreach ($logIds as $logId) {
                    $log->load($logId)
                        ->delete();
                }
                Mage::getSingleton('adminhtml/session')->addSuccess(
                    Mage::helper('adminhtml')->__('Total of %d record(s) were deleted.', count($logIds))
                );
            } catch (Exception $e) {
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
        }

        $this->_redirect('*/*/index');
    }
    
    private function reindexAll()
    {
        for ($i = 1; $i <= 9; $i++) 
        {
            $this->reindexProcess($i, false);
        }
    }
    
    private function reindexProcess($id, $force)
    {
        if ($this->systemValues->getSysValue($this->systemValues->opReindexStock) == "-1")
        {
            $process = Mage::getModel('index/process')->load($id);
            
            if ($process->getStatus() == Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX || $force)
            {
                $process->reindexEverything();
            }
        }
    }
    
    private function clearConfigCacheAfterUpdate()
    {
        Mage::app()->getCacheInstance()->cleanType('config');
    }
}