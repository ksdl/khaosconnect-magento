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

class Ksdl_Khaosconnect_Block_Adminhtml_Form_Edit_Tabs extends Mage_Adminhtml_Block_Widget_Tabs
{
 
  public function __construct()
  {
      parent::__construct();
      $this->setId('form_tabs');
      $this->setDestElementId('edit_form'); // this should be same as the form id define above
      $this->setTitle(Mage::helper('khaosconnect')->__('Khaos Connect Options'));
  }
 
  protected function _beforeToHtml()
  {
      $this->addTab('khaosconnect_webcategories', array(
          'label'     => Mage::helper('khaosconnect')->__('Manual Website Sync'),
          'title'     => Mage::helper('khaosconnect')->__('Manual Website Sync'),
          'content'   => $this->getLayout()->createBlock('khaosconnect/adminhtml_form_edit_tabs_manualsync')->toHtml()
      ));
      
      $this->addTab('khaosconnect_settings', array(
          'label'     => Mage::helper('khaosconnect')->__('Settings'),
          'title'     => Mage::helper('khaosconnect')->__('Settings'),
          'content'   => $this->getLayout()->createBlock('khaosconnect/adminhtml_form_edit_tabs_settings')->toHtml()
      ));
      
      $this->addTab('khaosconnect_synclog', array(
          'label'     => Mage::helper('khaosconnect')->__('Logs'),
          'title'     => Mage::helper('khaosconnect')->__('Logs'),
          'content'   => $this->getLayout()->createBlock('khaosconnect/adminhtml_form_edit_tabs_logs')->toHtml()
      ));
      
      return parent::_beforeToHtml();
  }
}