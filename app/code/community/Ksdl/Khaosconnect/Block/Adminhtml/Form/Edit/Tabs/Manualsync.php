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

class Ksdl_Khaosconnect_Block_Adminhtml_Form_Edit_Tabs_Manualsync extends Ksdl_Khaosconnect_Block_Adminhtml_Form_Edit_Tabs_Basetab
{
    protected function getLockFilePath($uniqueId)
    {
        return Mage::getBaseDir('log') . '/syncing_stock_codes_' . $uniqueId . '.txt';
    }
    
    private function getCodesFromLockFile($uniqueId)
    {
        $codes = array();
        $filePath = $this->getLockFilePath($uniqueId);
        if (file_exists($filePath))
        {
            $contents = file_get_contents($filePath, true);
            if ($contents != "")
                $codes = explode(',', $contents);
        }
        return $codes;
    }
    
    protected function _prepareForm()
    {
        $form = new Varien_Data_Form();
        $this->setForm($form);
        $customersPerWebsite = Mage::getStoreConfig('customer/account_share/scope') == '1';
         
        $globalFieldset = $form->addFieldset('form_global', array('legend'=>Mage::helper('khaosconnect')->__('Global')));
        
        if (!$customersPerWebsite)
        {
            $fieldName = 'customersync_' . $this->systemValues->cuPrefix;
            $globalFieldset->addField($fieldName, 'checkbox', array(
                'label'     => 'Customers',
                'name'      => $fieldName,
                'checked' => false,
                'value'  => $this->systemValues->cuPrefix
            ));
        }
        
        $fieldName = 'plgsync_' . $this->systemValues->plgPrefix;
        $globalFieldset->addField($fieldName, 'checkbox', array(
            'label'     => 'Grouped Price Lists',
            'name'      => $fieldName,
            'checked' => false,
            'value'  => $this->systemValues->plgPrefix,
             'after_element_html' => "<br/><small>Sync all price lists which are in Khaos to Magento (only price lists that will be used will be imported i.e groups which contain customers).</small>"
        ));
        
        $fieldName = 'stocklevels_' . $this->systemValues->rcPrefixStockSync;
        $globalFieldset->addField($fieldName, 'checkbox', array(
            'label' => 'All Stock Levels',
            'name' => $fieldName,
            'checked' => false,
            'value' => $this->systemValues->rcPrefixStockSync,
             'after_element_html' => '<br/><small>Sync the stock levels of all stock items from Khaos to Magento. PLEASE NOTE: This is NOT compatible with multi site setups. Stock levels will be sync\'d as normal using the Stock / Web Categories option.</small>'
        ));
        
        $fieldName = 'keycodes_' . $this->systemValues->kcPrefix;
        $globalFieldset->addField($fieldName, 'checkbox', array(
            'label' => 'Keycodes',
            'name' => $fieldName,
            'checked' => false,
            'value' => $this->systemValues->kcPrefix,
             'after_element_html' => '<br/><small>Sync all keycodes from Khaos to magento.</small>'
        ));
        
        foreach (Mage::app()->getWebsites() as $website) {
            $websiteFieldset = $form->addFieldset('form_websites_' . $website->getId(), array('legend'=>Mage::helper('khaosconnect')->__($website->getName())));
            
            //Syncs PER WEBSITE (customer is exported based on currency).
            if ($customersPerWebsite)
            {
                $fieldName = 'customersync_' . $this->systemValues->cuPrefix . $website->getId();
                $websiteFieldset->addField($fieldName, 'checkbox', array(
                    'label'     => 'Customers',
                    'name'      => $fieldName,
                    'checked' => false,
                    'value'  => $this->systemValues->cuPrefix . $website->getId(),
                    'after_element_html' => '<br/><small>Sync customer records from Khaos to Magento.</small>'
                ));
            }
            
            foreach ($website->getStores() as $store)
            {
                $value = $this->systemValues->rcPrefix . $store->getId();
                $rootCategoryName = $this->systemValues->getSysValue($value);
                    
                $fieldset = $websiteFieldset->addFieldset('form_manual_sync_store' . $store->getId(), array('legend'=>Mage::helper('khaosconnect')->__($store->getName() . ' - (' . $rootCategoryName . ')')));
                    
                if ($rootCategoryName != '')
                {
                    $verifiedCodesInFile = array();
                    foreach ($this->getCodesFromLockFile($store->getId()) as $code)
                        if ($code != "")
                            $verifiedCodesInFile[] = $code;
                    
                    $count = count($verifiedCodesInFile);
                    $failedCountText = $count > 0 ? "The previous sync finished with an error: Refer to /var/logs/Khaosconnect.log for more information. <b>Please re-sync as there are $count items which have not yet been imported</b>." : "";
                    
                    $fieldName = 'stocksync_' . $value;
                    $fieldset->addField($fieldName, 'checkbox', array(
                        'label' => 'Stock / Web Categories',
                        'name' => $fieldName,
                        'checked' => false,
                        'value' => $value,
                        'after_element_html' => "<br/><small>Sync Stock and Web Categories which have been changed in Khaos since the last sync date.</small><br/><small><span style='color:red'>$failedCountText</span></small>"
                    ));  
                        
                    $fieldName = 'stocksync_' . $value . '_selected';
                    $fieldset->addField($fieldName, 'text', array(
                        'label' => '',
                        'name' => $fieldName,
                        'checked' => false,
                        'value' => '',
                        'after_element_html' => '<br/><small>Specify certain stock codes to sync (comma separated list).</small>',
                    ));  
                        
                    $fieldName = 'ordersync_' . $this->systemValues->roPrefix . $store->getId();
                    $fieldset->addField($fieldName, 'checkbox', array(
                        'label' => 'Orders',
                        'name' => $fieldName,
                        'checked' => false,
                        'value' => $this->systemValues->roPrefix . $store->getId(),
                        'after_element_html' => '<br/><small>Sync orders from Magento to Khaos.</small>'
                    ));
                    
                    $fieldName = 'defaultpricesync_' . $this->systemValues->rcDefaultPriceList . $store->getId();
                    $fieldset->addField($fieldName, 'checkbox', array(
                        'label' => 'Default Price List',
                        'name' => $fieldName,
                        'checked' => false,
                        'value' => $this->systemValues->rcDefaultPriceList . $store->getId(),
                        'after_element_html' => '<br/><small>Sync the default price list set in the settings tab from Khaos to Magento.</small>'
                    ));
                    
                    $fieldName = 'orderstatussync_' . $this->systemValues->rsPrefix . $store->getId();
                    $fieldset->addField($fieldName, 'checkbox', array(
                        'label' => 'Order Statuses',
                        'name' => $fieldName,
                        'checked' => false,
                        'value' => $this->systemValues->rsPrefix . $store->getId(),
                        'after_element_html' => '<br/><small>Sync all statuses of orders from Khaos to Magento.</small>'
                    ));
                }else{
                    //Just add a label to show that this store hasn't been configured yet.
                    $fieldName = 'novalues_' . $store->getId();;
                    $fieldset->addField($fieldName, 'label', array(
                        'label' => 'This store does not have a root web category setup against it. Please navigate to the Settings tab and configure a root category.',
                        'name'  => $fieldName,
                        'value' => ''
                    ));
                }
            }
        }          
        return parent::_prepareForm();
    }
}