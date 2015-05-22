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


class Ksdl_Khaosconnect_Block_Adminhtml_Formheader extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        parent::__construct();
        
        $this->_objectId = 'tab';
        $this->_blockGroup = 'khaosconnect';
        $this->_controller = 'adminhtml_form';
         
        $postBackUrl = $this->getUrl('*/khaosconnect/save');
        $this->_updateButton('save', 'label', Mage::helper('khaosconnect')->__('Save Settings'));
        $this->_updateButton('save', 'onclick', "submitForm('$postBackUrl')");
                
        $postBackUrl = $this->getUrl('*/khaosconnect/sync');
        $this->_addButton('sync', array(
            'label'     => Mage::helper('adminhtml')->__('Trigger Sync'),
            'onclick'   => "submitForm('$postBackUrl')",
            'class'     => 'save'
        ),3,5);
    }
 
    public function getHeaderText()
    {
        return Mage::helper('khaosconnect')->__('Khaos Control Connector Magento Integration Configuration');
    }
}
