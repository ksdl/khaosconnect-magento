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


class Ksdl_Khaosconnect_Helper_Systemvalues extends Mage_Core_Helper_Abstract
{
    //Root Category / Stock Fields - (STORE)
    public $rcPrefix =                                             'store_root_cat_';
    public $rcLastSyncPrefix =                                     'store_root_cat_last_sync_';
    public $rcAutoUpdatePrefix =                                   'store_root_cat_auto_update_';
    public $rcAutoUpdateIntervalPrefix =                           'store_root_cat_auto_update_interval_';
    public $rcDefaultPriceList =                                   'store_root_cat_defalt_pricelist_';
    public $rcDefaultPriceListLastSyncPrefix =                     'store_root_cat_defalt_pricelist_last_sync_';
    public $rcDefaultPriceListAutoUpdatePrefix =                   'store_root_cat_defalt_pricelist_auto_update_';
    public $rcDefaultPriceListAutoUpdateIntervalPrefix =           'store_root_cat_defalt_pricelist_auto_update_interval_';
    public $rcPrefixStockSync =                                    'store_root_cat_sync_stock_';
    
    //Root Order Export Fields - (STORE)
    public $roPrefix =                      'store_root_order_';
    public $roLastSyncPrefix =              'store_root_order_last_sync_';
    public $roAutoUpdatePrefix =            'store_root_order_auto_update_';
    public $roAutoUpdateIntervalPrefix =    'store_root_order_auto_update_interval_';
    public $roBrandPrefix =                 'store_root_order_brand_';
    public $roSitePrefix =                  'store_root_order_site_';
    public $roSalesSource =                  'store_root_order_sales_source_';
    
    //Order Import
    public $ohPrefix =                      'order_import_';
    public $ohLastSyncPrefix =              'order_import_last_sync_';
    public $ohAutoUpdatePrefix =            'order_import_auto_update_';
    public $ohAutoUpdateIntervalPrefix =    'order_import_auto_update_interval_';
    
    //Order Status Fields
    public $rsPrefix =                      'store_root_order_status_';
    public $rsLastSyncPrefix =              'store_root_order_status_last_sync_';
    public $rsAutoUpdatePrefix =            'store_root_order_status_auto_update_';
    public $rsAutoUpdateIntervalPrefix =    'store_root_order_status_auto_update_interval_';
    
    //Customer Fields - (GLOBAL / STORE)
    public $cuPrefix =                      'customer_';
    public $cuLastSyncPrefix =              'customer_last_sync_';
    public $cuAutoUpdatePrefix =            'customer_auto_update_';
    public $cuAutoUpdateIntervalPrefix =    'customer_auto_update_interval_';
    
    //Pricelist Group Fields - (GLOBAL)
    public $plgPrefix =                      'pricelist_group';
    public $plgLastSyncPrefix =              'pricelist_group_last_sync';
    public $plgAutoUpdatePrefix =            'pricelist_group_auto_update';
    public $plgAutoUpdateIntervalPrefix =    'pricelist_group_auto_update_interval';
    
    //Keycodes - (GLOBAL)
    public $kcPrefix =                      'keycode';
    public $kcLastSyncPrefix =              'keycode_last_sync';
    public $kcAutoUpdatePrefix =            'keycode_auto_update';
    public $kcAutoUpdateIntervalPrefix =    'keycode_auto_update_interval';
    
    //Stock Levels - (GLOBAL)
    public $slPrefix =                      'all_stock_levels';
    public $slLastSyncPrefix =              'all_stock_levels_last_sync';
    public $slAutoUpdatePrefix =            'all_stock_levels_auto_update';
    public $slAutoUpdateIntervalPrefix =    'all_stock_levels_auto_update_interval';
    
    //Options [STORE]
    public $opUpdateStockWebsite =          'option_update_stock_website_';
    
    //Options [GLOBAL]
    public $opUpdateStockImages =           'option_update_stock_images_';
    public $opReindexStock =                'option_reindex_stock_';
    public $opPayPalKhaosAccountName =      'option_paypal_khaos_account_name';
    public $opPayPalKhaosAccountNumber =    'option_paypal_khaos_account_number';
    public $opCCKhaosAccountName =          'option_cc_khaos_account_name';
    public $opCCKhaosAccountNumber =        'option_cc_khaos_account_number';
    public $opUpdateStockDescriptions =     'option_update_stock_descriptions_';
    
    function __construct() 
    {
    }
    
    public function getSysValue($name)
    {
        //For some reason, getting options from the config "broke". Just stopped working. Putting this in place for the time being.
        $resource = Mage::getSingleton('core/resource');
        $readDB = $resource->getConnection('core_read');
        
        $sql = "select value from " . $resource->getTableName('core_config_data') . " where path = " . $readDB->quote('ksdl/khaosconnect/' . $name);
        $query = $readDB->query($sql);
        $row = $query->fetch();
        return $row['value']; //Mage::getStoreConfig('ksdl/khaosconnect/' . $name);
    }
    
    public function setSysValue($name, $value)
    {
        Mage::getConfig()->saveConfig('ksdl/khaosconnect/' . $name, $value, 'default', 0);
    }
}