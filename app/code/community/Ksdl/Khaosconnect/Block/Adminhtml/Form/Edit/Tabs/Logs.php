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

class Ksdl_Khaosconnect_Block_Adminhtml_Form_Edit_Tabs_Logs extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('khaosconnect_logs');
        $this->setDefaultSort('id');
        $this->setDefaultDir('desc');
        $this->setSaveParametersInSession(true);
    }

    protected function _prepareCollection()
    {
        $model = Mage::getModel('khaosconnect/logs');
        $collection = $model->getCollection();
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $baseHelper = Mage::helper('khaosconnect/basehelper');
        
        $this->addColumn('id', array(
            'header'    => Mage::helper('khaosconnect')->__('ID'),
            'align'     =>'left',
            'index'     => 'id',
            'width'    => '25'
        ));
        
        $this->addColumn('type', array(
            'header'    => Mage::helper('khaosconnect')->__('Log Type'),
            'align'     => 'left',
            'index'     => 'type',
            'type'      => 'options',
            'options'   => array(
                $baseHelper::cLogTypeOrder => 'Order', 
                $baseHelper::cLogTypeStock => 'Stock', 
                $baseHelper::cLogTypeCustomer => 'Customer',
                $baseHelper::cLogTypeGroupPriceList => 'Grouped Price List',
                $baseHelper::cLogTypeKeycode => 'Keycode',
            )
        ));
        
        $this->addColumn('status', array(
            'header'    => Mage::helper('khaosconnect')->__('Status'),
            'align'     => 'left',
            'index'     => 'status',
            'type'      => 'options',
            'options'   => array(
                $baseHelper::cLogStatusSuccess => 'Successful', 
                $baseHelper::cLogStatusFailed => 'Failed',
                $baseHelper::cLogStatusUpdate => 'Notice'
            )
        ));

        $this->addColumn('message', array(
            'header'    => Mage::helper('khaosconnect')->__('Information'),
            'align'     =>'left',
            'index'     => 'message',
            'renderer'  => 'khaosconnect/adminhtml_grid_renderer_logmessage'
        ));
        
        $this->addColumn('datetime', array(
            'header'    => Mage::helper('khaosconnect')->__('Time'),
            'type'      => 'datetime',
            'align'     => 'center',
            'index'     => 'timestamp',
            'gmtoffset' => true,
            'width'     => '135'
        ));

        return parent::_prepareColumns();
    }

    public function getRowUrl($row)
    {
        return ''; //$this->getUrl('*/*/edit', array('id' => $row->getId()));
    }
    
    protected function _prepareMassaction()
    {
        $this->setMassactionIdField('entity_id');
        $this->getMassactionBlock()->setFormFieldName('logs');
        
        $this->getMassactionBlock()->addItem('delete', array(
             'label'    => Mage::helper('khaosconnect')->__('Delete'),
             'url'      => $this->getUrl('*/*/massDelete'),
             'confirm'  => Mage::helper('khaosconnect')->__('Are you sure?')
        ));
    }
}