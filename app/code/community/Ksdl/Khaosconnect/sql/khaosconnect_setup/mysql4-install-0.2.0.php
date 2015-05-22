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


echo 'Running This Upgrade: '.get_class($this)."\n <br /> \n";

$installer = $this;
$installer->startSetup();
$setup = new Mage_Eav_Model_Entity_Setup('core_setup');

$installer->run("
    CREATE TABLE `{$installer->getTable('khaosconnect_catalog_category_link')}` (
      `id` int(11) NOT NULL auto_increment,
      `entity_id` int NOT NULL,
      `category_id` int NOT NULL,
      `path` varchar(255) NOT NULL,
      PRIMARY KEY  (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");
    
$table = $installer->getTable('khaosconnect/logs');
$installer->run("
    CREATE TABLE `{$table}` (
        `id` int(11) NOT NULL auto_increment,
        `entity_id` int NOT NULL,
        `increment_id` varchar(50) NOT NULL,
        `message` varchar(1000) NOT NULL,
        `retry_count` int NOT NULL DEFAULT 0,
        `status` int NOT NULL, 
        `timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP,
        `type` int NOT NULL,
        PRIMARY KEY  (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");
    
$installer->run("
    CREATE TABLE `{$installer->getTable('khaosconnect_product_image_mapping')}` (
      `id` int(11) NOT NULL auto_increment,
      `stock_code` varchar(30) NOT NULL,
      `product_id` int NOT NULL,
      `file_name` varchar(255) NOT NULL,
      `magento_file_path` varchar(255) NOT NULL,
      PRIMARY KEY  (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");
    
//Add UDAs group to default Attribute Set
$entityTypeId = Mage::getModel('catalog/product')->getResource()->getTypeId();
$defaultSetId = Mage::getModel('catalog/product')->getResource()->getEntityType()->getDefaultAttributeSetId();
$groupName = 'UDAs';
$newGroupId = $setup->getAttributeGroup($entityTypeId, $defaultSetId, $groupName, 'attribute_group_id');
if ((!is_numeric($newGroupId)) || $newGroupId == 0)
{
    $setup->addAttributeGroup($entityTypeId, $defaultSetId, $groupName);
    $newGroupId = $setup->getAttributeGroup($entityTypeId, $defaultSetId, $groupName, 'attribute_group_id');
}

$setup->addAttribute('customer_address', 'kc_contact_id', array(
    'type'            => 'int',
    'label'         => 'Khaos Contact ID',
    'visible'       => false,
    'required'      => false,
    'unique'        => true
));
    
$setup->addAttribute('customer_address', 'kc_address_id', array(
    'type'            => 'int',
    'label'         => 'Khaos Address ID',
    'visible'       => false,
    'required'      => false,
    'unique'        => true
));

$setup->addAttribute('customer', 'kc_company_code', array(
    'type'            => 'varchar',
    'label'         => 'Khaos Company Code',
    'visible'       => false,
    'required'      => false,
    'unique'        => false
));

$config = new Mage_Core_Model_Config();
$config->saveConfig('ksdl/khaosconnect/' . Mage::helper('khaosconnect/systemvalues')->cuLastSyncPrefix, date('Y-m-d\TH:i:s'), 'default', 0);
    
$installer->endSetup();