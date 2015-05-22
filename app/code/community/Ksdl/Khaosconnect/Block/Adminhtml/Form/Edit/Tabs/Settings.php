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

//TODO: Rewrite this page so that it works of an array of options to avoid duplicating lots of code.

class Ksdl_Khaosconnect_Block_Adminhtml_Form_Edit_Tabs_Settings extends Ksdl_Khaosconnect_Block_Adminhtml_Form_Edit_Tabs_Basetab
{
    protected function _prepareForm()
    {
        $form = new Varien_Data_Form();
        $this->setForm($form);
        $customersPerWebsite = Mage::getStoreConfig('customer/account_share/scope') == '1';
        $timeArray = array(
            '-1' => 'Please Select ...',
            '15' => '15 Minutes',
            '20' => '20 Minutes',
            '25' => '25 Minutes',
            '30' => '30 Minutes',
            '60' => '60 Minutes',
            '120' => '120 Minutes'
        );        
        
        $fieldset = $form->addFieldset('form_settings', array('legend'=>Mage::helper('khaosconnect')->__('Module Settings')));
        
        $fieldName = 'web_service_url';
        $fieldset->addField($fieldName, 'text', array(
            'label'     => Mage::helper('khaosconnect')->__('Web Service Url'),
            'class'     => 'required-entry',
            'required'  => true,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($fieldName),
            'style'     => 'width:500px',
            'after_element_html' => '<br/><small><span style="color:red;">NOTE: Khaos Web Services are subject to an annual licence cost. Please contact KSDL for more information about activating this service.</span></small>',
            
        ));
        
        $orderStatuses = Mage::getModel('sales/order_status')->getCollection()->toOptionArray();
        
        $fieldName = 'export_stage_name';
        $fieldset->addField($fieldName, 'select', array(
            'label'     => Mage::helper('khaosconnect')->__('Order Export Status'),
            'required'  => true,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($fieldName),
            'after_element_html' => '<br/><small>Determines which stage orders are exported to Khaos from. This applies to both the manual and automatic sync.</small>',
            'values'     => $orderStatuses
        ));
        
        $fieldName = 'export_stage_name_update';
        $fieldset->addField($fieldName, 'select', array(
            'label'     => Mage::helper('khaosconnect')->__('Order Export Update Status'),
            'required'  => true,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($fieldName),
            'after_element_html' => '<br/><small>Determines which status orders will have once they have been exported to Khaos. This applies to both the manual and automatic sync.</small>',
            'values'     => $orderStatuses
        ));
        
        $fieldName = 'export_stage_name_on_hold';
        $fieldset->addField($fieldName, 'select', array(
            'label'     => Mage::helper('khaosconnect')->__('Order Export Failed Status'),
            'required'  => true,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($fieldName),
            'after_element_html' => '<br/><small>Determines which status orders will have if they fail to export to Khaos Control. This prevents failed orders trying to export multiple times without review.</small>',
            'values'     => $orderStatuses
        ));
        
        $fieldName = $this->systemValues->opUpdateStockImages;
        $fieldset->addField($fieldName, 'checkbox', array(
            'label'     => Mage::helper('khaosconnect')->__('Sync Stock Images?'),
            'name'      => $fieldName,
            'checked' => $this->systemValues->getSysValue($this->systemValues->opUpdateStockImages) == '-1',
            'onclick' => "",
            'onchange' => "",
            'value'  => '-1',
            'disabled' => false,
            'tabindex' => 1,
            'after_element_html' => '<br/><small>Should the module sync the stock images with Khaos? Note: All images that don\'t exist in Khaos will be removed from the website for the stock items being synchronised.</small>'
        ));
        
        $fieldName = $this->systemValues->opReindexStock;
        $fieldset->addField($fieldName, 'checkbox', array(
            'label'     => Mage::helper('khaosconnect')->__('Re-Index After Sync?'),
            'name'      => $fieldName,
            'checked' => $this->systemValues->getSysValue($this->systemValues->opReindexStock) == '-1',
            'onclick' => "",
            'onchange' => "",
            'value'  => '-1',
            'disabled' => false,
            'tabindex' => 1,
            'after_element_html' => '<br/><small>Should a re-index be triggered for all indexes marked as "required index" after a sync?</small>'
        ));
        
        $fieldName = $this->systemValues->opUpdateStockDescriptions;
        $fieldset->addField($fieldName, 'checkbox', array(
            'label'     => Mage::helper('khaosconnect')->__('Sync Stock Descriptions?'),
            'name'      => $fieldName,
            'checked' => $this->systemValues->getSysValue($this->systemValues->opUpdateStockDescriptions) == '-1',
            'onclick' => "",
            'onchange' => "",
            'value'  => '-1',
            'disabled' => false,
            'tabindex' => 1,
            'after_element_html' => '<br/><small>Should the module sync the stock descriptions with Khaos? Untick this option if you plan on entering descriptions in Magento directly.</small>'
        ));
        
        $fieldName = $this->systemValues->opPayPalKhaosAccountName;
        $fieldset->addField($fieldName, 'text', array(
            'label'     => Mage::helper('khaosconnect')->__('PAYPAL: Khaos Account Name?'),
            'class'     => '',
            'required'  => false,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($fieldName),
            'style'     => 'width:200px',
            'after_element_html' => '<br/><small>When a paypal payment is imported with an order, which Khaos Control account name should it be set to?</small>'
        ));
        
        $fieldName = $this->systemValues->opPayPalKhaosAccountNumber;
        $fieldset->addField($fieldName, 'text', array(
            'label'     => Mage::helper('khaosconnect')->__('PAYPAL: Khaos CT Account Number?'),
            'class'     => '',
            'required'  => false,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($fieldName),
            'style'     => 'width:200px',
            'after_element_html' => '<br/><small>When a paypal payment is imported with an order, which Khaos Control card transaction account number should it be set to?</small>'
        ));
        
        $fieldName = $this->systemValues->opCCKhaosAccountName;
        $fieldset->addField($fieldName, 'text', array(
            'label'     => Mage::helper('khaosconnect')->__('Credit Card: Khaos Account Name?'),
            'class'     => '',
            'required'  => false,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($fieldName),
            'style'     => 'width:200px',
            'after_element_html' => '<br/><small>When a credit card payment is imported with an order, which Khaos Control account name should it be set to?</small>'
        ));
        
        $fieldName = $this->systemValues->opCCKhaosAccountNumber;
        $fieldset->addField($fieldName, 'text', array(
            'label'     => Mage::helper('khaosconnect')->__('Credit Card: Khaos CT Account Number?'),
            'class'     => '',
            'required'  => false,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($fieldName),
            'style'     => 'width:200px',
            'after_element_html' => '<br/><small>When a credit card payment is imported with an order, which Khaos Control card transaction account number should it be set to?</small>'
        ));
        
        $globalFieldset = $form->addFieldset('form_global', array('legend'=>Mage::helper('khaosconnect')->__('Global Settings')));
        
        if (!$customersPerWebsite)
        {
            $customerAutoUpdate = $this->systemValues->getSysValue($this->systemValues->cuAutoUpdatePrefix);
            $customerAutoUpdateInterval = $this->systemValues->getSysValue($this->systemValues->cuAutoUpdateIntervalPrefix);
        
            $fieldName = $this->systemValues->cuAutoUpdatePrefix;
            $globalFieldset->addField($fieldName, 'checkbox', array(
                'label'     => Mage::helper('khaosconnect')->__('Customer Auto Sync?'),
                'name'      => $fieldName,
                'checked' => $customerAutoUpdate == '-1',
                'onclick' => "",
                'onchange' => "",
                'value'  => '-1',
                'disabled' => false,
                'tabindex' => 1,
                'after_element_html' => '<br/><small>Should magento import customers from Khaos at the set interval?</small>'
            ));  

            $fieldName = $this->systemValues->cuAutoUpdateIntervalPrefix;
            $globalFieldset->addField($fieldName, 'select', array(
                'label'     => Mage::helper('khaosconnect')->__('Customer Auto Update Interval'),
                'required'  => true,
                'name'      => $fieldName,
                'value'     => $customerAutoUpdateInterval,
                'after_element_html' => '<br/><small>Only applicable when Auto Sync is ticked. Determines how often the sync is performed.</small>',
                'values'     => $timeArray
            ));
        }
        
        $plgAutoUpdate = $this->systemValues->getSysValue($this->systemValues->plgAutoUpdatePrefix);
        $plgAutoUpdateInterval = $this->systemValues->getSysValue($this->systemValues->plgAutoUpdateIntervalPrefix);
        $fieldName = $this->systemValues->plgAutoUpdatePrefix;
        $globalFieldset->addField($fieldName, 'checkbox', array(
            'label'     => Mage::helper('khaosconnect')->__('Group Price List Auto Sync?'),
            'name'      => $fieldName,
            'checked' => $plgAutoUpdate == '-1',
            'onclick' => "",
            'onchange' => "",
            'value'  => '-1',
            'disabled' => false,
            'tabindex' => 1,
            'after_element_html' => '<br/><small>Should magento import company class based price lists (only price lists that will be used will be imported i.e groups which contain customers)?</small>'
        ));  

        $fieldName = $this->systemValues->plgAutoUpdateIntervalPrefix;
        $globalFieldset->addField($fieldName, 'select', array(
            'label'     => Mage::helper('khaosconnect')->__('Group Price List Auto Update Interval'),
            'required'  => true,
            'name'      => $fieldName,
            'value'     => $plgAutoUpdateInterval,
            'after_element_html' => '<br/><small>Only applicable when Auto Sync is ticked. Determines how often the sync is performed.</small>',
            'values'     => $timeArray
        ));
        
        $fieldName = $this->systemValues->kcAutoUpdatePrefix;
        $globalFieldset->addField($fieldName, 'checkbox', array(
            'label'     => Mage::helper('khaosconnect')->__('Keycodes Auto Sync?'),
            'name'      => $fieldName,
            'checked' => $this->systemValues->getSysValue($this->systemValues->kcAutoUpdatePrefix) == '-1',
            'onclick' => "",
            'onchange' => "",
            'value'  => '-1',
            'disabled' => false,
            'tabindex' => 1,
            'after_element_html' => '<br/><small>Should magento import keycodes?</small>'
        ));  

        $fieldName = $this->systemValues->kcAutoUpdateIntervalPrefix;
        $globalFieldset->addField($fieldName, 'select', array(
            'label'     => Mage::helper('khaosconnect')->__('Keycodes Auto Update Interval'),
            'required'  => true,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($this->systemValues->kcAutoUpdateIntervalPrefix),
            'after_element_html' => '<br/><small>Only applicable when Auto Sync is ticked. Determines how often the sync is performed.</small>',
            'values'     => $timeArray
        ));
        
        $fieldName = $this->systemValues->slAutoUpdatePrefix;
        $globalFieldset->addField($fieldName, 'checkbox', array(
            'label'     => Mage::helper('khaosconnect')->__('All Stock Levels Auto Sync?'),
            'name'      => $fieldName,
            'checked' => $this->systemValues->getSysValue($this->systemValues->slAutoUpdatePrefix) == '-1',
            'onclick' => "",
            'onchange' => "",
            'value'  => '-1',
            'disabled' => false,
            'tabindex' => 1,
            'after_element_html' => '<br/><small>Should Magento auto run the All Stock Levels sync? Note: This is not necessary if you are running the Stock / Web Categories auto update.</small>'
        ));  

        $fieldName = $this->systemValues->slAutoUpdateIntervalPrefix;
        $globalFieldset->addField($fieldName, 'select', array(
            'label'     => Mage::helper('khaosconnect')->__('All Stock Levels Auto Update Interval'),
            'required'  => true,
            'name'      => $fieldName,
            'value'     => $this->systemValues->getSysValue($this->systemValues->slAutoUpdateIntervalPrefix),
            'after_element_html' => '<br/><small>Only applicable when Auto Sync is ticked. Determines how often the sync is performed.</small>',
            'values'     => $timeArray
        ));
        
        foreach (Mage::app()->getWebsites() as $website) {
            $websiteFieldset = $form->addFieldset('form_websites_' . $website->getId(), array('legend'=>Mage::helper('khaosconnect')->__($website->getName())));
            
            if ($customersPerWebsite)
            {
                $customerAutoUpdate = $this->systemValues->getSysValue($this->systemValues->cuAutoUpdatePrefix . $website->getId());
                $customerAutoUpdateInterval = $this->systemValues->getSysValue($this->systemValues->cuAutoUpdateIntervalPrefix . $website->getId());

                $fieldName = $this->systemValues->cuAutoUpdatePrefix . $website->getId();
                $websiteFieldset->addField($fieldName, 'checkbox', array(
                    'label'     => Mage::helper('khaosconnect')->__('Customer Auto Sync?'),
                    'name'      => $fieldName,
                    'checked' => $customerAutoUpdate == '-1',
                    'onclick' => "",
                    'onchange' => "",
                    'value'  => '-1',
                    'disabled' => false,
                    'tabindex' => 1,
                    'after_element_html' => '<br/><small>Should magento import customers from Khaos at the set interval?</small>'
                ));  

                $fieldName = $this->systemValues->cuAutoUpdateIntervalPrefix . $website->getId();
                $websiteFieldset->addField($fieldName, 'select', array(
                    'label'     => Mage::helper('khaosconnect')->__('Customer Auto Update Interval'),
                    'required'  => true,
                    'name'      => $fieldName,
                    'value'     => $customerAutoUpdateInterval,
                    'after_element_html' => '<br/><small>Only applicable when Auto Sync is ticked. Determines how often the sync is performed.</small>',
                    'values'     => $timeArray
                )); 
            }
            
            $fieldName = $this->systemValues->opUpdateStockWebsite . $website->getId();
            $websiteFieldset->addField($fieldName, 'checkbox', array(
                'label'     => Mage::helper('khaosconnect')->__('Update Stock Websites?'),
                'name'      => $fieldName,
                'checked' => $this->systemValues->getSysValue($this->systemValues->opUpdateStockWebsite . $website->getId()) == '-1',
                'onclick' => "",
                'onchange' => "",
                'value'  => '-1',
                'disabled' => false,
                'tabindex' => 1,
                'after_element_html' => '<br/><small>Should this website be set against stock items that are being synchronised within the root category?</small>'
            )); 
            
            foreach ($website->getStores() as $store) {
                $rootCategoryName = $this->systemValues->getSysValue($this->systemValues->rcPrefix . $store->getId());
                $stockAutoUpdate = $this->systemValues->getSysValue($this->systemValues->rcAutoUpdatePrefix . $store->getId());
                $stockAutoUpdateInterval = $this->systemValues->getSysValue($this->systemValues->rcAutoUpdateIntervalPrefix . $store->getId());
                $orderAutoUpdate = $this->systemValues->getSysValue($this->systemValues->roAutoUpdatePrefix . $store->getId());
                $orderAutoUpdateInterval = $this->systemValues->getSysValue($this->systemValues->roAutoUpdateIntervalPrefix . $store->getId());
                $defaultPriceList = $this->systemValues->getSysValue($this->systemValues->rcDefaultPriceList . $store->getId());
                $defaultPriceListAutoUpdate = $this->systemValues->getSysValue($this->systemValues->rcDefaultPriceListAutoUpdatePrefix . $store->getId());
                $defaultPriceListAutoUpdateInterval = $this->systemValues->getSysValue($this->systemValues->rcDefaultPriceListAutoUpdateIntervalPrefix . $store->getId());
                $orderStatusAutoUpdate = $this->systemValues->getSysValue($this->systemValues->rsAutoUpdatePrefix . $store->getId());
                $orderStatusAutoUpdateInterval = $this->systemValues->getSysValue($this->systemValues->rsAutoUpdateIntervalPrefix . $store->getId());
                $lastStockSyncTime = $this->systemValues->getSysValue($this->systemValues->rcLastSyncPrefix . $store->getId());
                $lastCustomerSyncTime = $this->systemValues->getSysValue($this->systemValues->cuLastSyncPrefix . $store->getId());
                $lastOrderImportSyncTime = $this->systemValues->getSysValue($this->systemValues->ohLastSyncPrefix . $store->getId());
                $lastOrderExportSyncTime = $this->systemValues->getSysValue($this->systemValues->roLastSyncPrefix . $store->getId());
                
                $fieldset = $websiteFieldset->addFieldset('form_stores_' . $store->getId(), array('legend'=>Mage::helper('khaosconnect')->__($store->getName())));
                
                $fieldName = $this->systemValues->rcPrefix . $store->getId();
                $fieldset->addField($fieldName, 'text', array(
                    'label'     => Mage::helper('khaosconnect')->__('Root Category Name'),
                    'class'     => 'required-entry',
                    'required'  => true,
                    'name'      => $fieldName,
                    'value'     => $rootCategoryName,
                    'after_element_html' => '<br/><small>The top level category in Khaos Control | Web Configuration for this website.</small>'
                ));
                
                $fieldName = $this->systemValues->roSalesSource . $store->getId();
                $fieldset->addField($fieldName, 'text', array(
                    'label'     => Mage::helper('khaosconnect')->__('Order Sales Source'),
                    'class'     => 'required-entry',
                    'required'  => false,
                    'name'      => $fieldName,
                    'value'     => $this->systemValues->getSysValue($this->systemValues->roSalesSource . $store->getId()),
                    'after_element_html' => '<br/><small>The sales source that will be set against the order in Khaos.</small>'
                )); 
                
                $fieldName = $this->systemValues->roBrandPrefix . $store->getId();
                $fieldset->addField($fieldName, 'text', array(
                    'label'     => Mage::helper('khaosconnect')->__('Order Brand'),
                    'class'     => 'required-entry',
                    'required'  => false,
                    'name'      => $fieldName,
                    'value'     => $this->systemValues->getSysValue($this->systemValues->roBrandPrefix . $store->getId()),
                    'after_element_html' => '<br/><small>The brand that orders exported from this store will have set against the order in Khaos.</small>'
                ));  
                
                $fieldName = $this->systemValues->roSitePrefix . $store->getId();
                $fieldset->addField($fieldName, 'text', array(
                    'label'     => Mage::helper('khaosconnect')->__('Order / Stock Control Site'),
                    'class'     => 'required-entry',
                    'required'  => false,
                    'name'      => $fieldName,
                    'value'     => $this->systemValues->getSysValue($this->systemValues->roSitePrefix . $store->getId()),
                    'after_element_html' => '<br/><small>The site that orders exported from this store will have set against the order in Khaos. This will also be used to calculate the stock levels for this store.</small>'
                ));  
                
                $fieldName = $this->systemValues->rcDefaultPriceList . $store->getId();
                $fieldset->addField($fieldName, 'text', array(
                    'label'     => Mage::helper('khaosconnect')->__('Default Price List'),
                    'class'     => 'required-entry',
                    'required'  => false,
                    'name'      => $fieldName,
                    'value'     => $defaultPriceList,
                    'after_element_html' => '<br/><small>The default prices that will be shown for this store view (Note: Item\'s existing in this store but not in the default price list will be deactivated).</small>'
                ));    
                
                $fieldName = $this->systemValues->rcDefaultPriceListAutoUpdatePrefix . $store->getId();
                $fieldset->addField($fieldName, 'checkbox', array(
                    'label'     => Mage::helper('khaosconnect')->__('Default Price List Auto Sync?'),
                    'name'      => $fieldName,
                    'checked' => $defaultPriceListAutoUpdate == '-1',
                    'onclick' => "",
                    'onchange' => "",
                    'value'  => '-1',
                    'disabled' => false,
                    'tabindex' => 1,
                    'after_element_html' => '<br/><small>Should magento update the stock prices at the set interval?</small>'
                ));  
                
                $fieldName = $this->systemValues->rcDefaultPriceListAutoUpdateIntervalPrefix . $store->getId();
                $fieldset->addField($fieldName, 'select', array(
                    'label'     => Mage::helper('khaosconnect')->__('Default Price List Auto Update Interval'),
                    'required'  => true,
                    'name'      => $fieldName,
                    'value'     => $defaultPriceListAutoUpdateInterval,
                    'after_element_html' => '<br/><small>Only applicable when Auto Sync is ticked. Determines how often the sync is performed.</small>',
                    'values'     => $timeArray
                )); 
                
                $fieldName = $this->systemValues->rcAutoUpdatePrefix . $store->getId();
                $fieldset->addField($fieldName, 'checkbox', array(
                    'label'     => Mage::helper('khaosconnect')->__('Stock Auto Sync?'),
                    'name'      => $fieldName,
                    'checked' => $stockAutoUpdate == '-1',
                    'onclick' => "",
                    'onchange' => "",
                    'value'  => '-1',
                    'disabled' => false,
                    'tabindex' => 1,
                    'after_element_html' => '<br/><small>Should magento update this website\'s stock at the set interval?</small>'
                ));  
                
                $fieldName = $this->systemValues->rcAutoUpdateIntervalPrefix . $store->getId();
                $fieldset->addField($fieldName, 'select', array(
                    'label'     => Mage::helper('khaosconnect')->__('Stock Auto Update Interval'),
                    'required'  => true,
                    'name'      => $fieldName,
                    'value'     => $stockAutoUpdateInterval,
                    'after_element_html' => '<br/><small>Only applicable when Auto Sync is ticked. Determines how often the sync is performed.</small>',
                    'values'     => $timeArray
                ));
                
                $fieldName = $this->systemValues->roAutoUpdatePrefix . $store->getId();
                $fieldset->addField($fieldName, 'checkbox', array(
                    'label'     => Mage::helper('khaosconnect')->__('Order Auto Sync?'),
                    'name'      => $fieldName,
                    'checked' => $orderAutoUpdate == '-1',
                    'onclick' => "",
                    'onchange' => "",
                    'value'  => '-1',
                    'disabled' => false,
                    'tabindex' => 1,
                    'after_element_html' => '<br/><small>Should magento send orders to Khaos at the set interval?</small>'
                ));  
                
                $fieldName = $this->systemValues->roAutoUpdateIntervalPrefix . $store->getId();
                $fieldset->addField($fieldName, 'select', array(
                    'label'     => Mage::helper('khaosconnect')->__('Order Auto Update Interval'),
                    'required'  => true,
                    'name'      => $fieldName,
                    'value'     => $orderAutoUpdateInterval,
                    'after_element_html' => '<br/><small>Only applicable when Auto Sync is ticked. Determines how often the sync is performed.</small>',
                    'values'     => $timeArray
                ));  
                
                $fieldName = $this->systemValues->rsAutoUpdatePrefix . $store->getId();
                $fieldset->addField($fieldName, 'checkbox', array(
                    'label'     => Mage::helper('khaosconnect')->__('Order Status Auto Sync?'),
                    'name'      => $fieldName,
                    'checked' => $orderStatusAutoUpdate == '-1',
                    'onclick' => "",
                    'onchange' => "",
                    'value'  => '-1',
                    'disabled' => false,
                    'tabindex' => 1,
                    'after_element_html' => '<br/><small>Should Magento order statuses be updated at the set interval?</small>'
                ));  
                
                $fieldName = $this->systemValues->rsAutoUpdateIntervalPrefix . $store->getId();
                $fieldset->addField($fieldName, 'select', array(
                    'label'     => Mage::helper('khaosconnect')->__('Order Status Auto Update Interval'),
                    'required'  => true,
                    'name'      => $fieldName,
                    'value'     => $orderStatusAutoUpdateInterval,
                    'after_element_html' => '<br/><small>Only applicable when Auto Sync is ticked. Determines how often the sync is performed.</small>',
                    'values'     => $timeArray
                ));   
                
                $fieldName = $this->systemValues->rcLastSyncPrefix . $store->getId();
                $fieldset->addField($fieldName, 'date', array(
                    'label'     => Mage::helper('khaosconnect')->__('Last Stock Sync Time'),
                    'name'      => $fieldName,
                    'value'     => $this->getSyncTime($lastStockSyncTime),
                    'after_element_html' => '<br/><small>Override the time the stock import routine picks records up from (Format: yyyy-MM-dd HH:mm:ss).</small>',
                    'format'    => 'yyyy-MM-dd HH:mm:ss',
                    'width'     => '280'
                )); 
                
                $fieldName = $this->systemValues->cuLastSyncPrefix . $store->getId();
                $fieldset->addField($fieldName, 'date', array(
                    'label'     => Mage::helper('khaosconnect')->__('Last Customer Sync Time'),
                    'name'      => $fieldName,
                    'value'     => $this->getSyncTime($lastCustomerSyncTime),
                    'after_element_html' => '<br/><small>Override the time the customer import routine picks records up from (Format: yyyy-MM-dd HH:mm:ss).</small>',
                    'format'    => 'yyyy-MM-dd HH:mm:ss',
                    'width'     => '280'
                ));  
                
                $fieldName = $this->systemValues->roLastSyncPrefix . $store->getId();
                $fieldset->addField($fieldName, 'date', array(
                    'label'     => Mage::helper('khaosconnect')->__('Last Order Export Sync Time'),
                    'name'      => $fieldName,
                    'value'     => $this->getSyncTime($lastOrderExportSyncTime),
                    'after_element_html' => '<br/><small>Override the time the order export routine picks records up from (Format: yyyy-MM-dd HH:mm:ss).</small>',
                    'format'    => 'yyyy-MM-dd HH:mm:ss',
                    'width'     => '280'
                )); 
                
            }
        }
          
        return parent::_prepareForm();
    }
    
    private function getSyncTime($str)
    {
        if ($str == "")
            return "1970-01-01 00:00:01";
        
        return $str;
    }
}