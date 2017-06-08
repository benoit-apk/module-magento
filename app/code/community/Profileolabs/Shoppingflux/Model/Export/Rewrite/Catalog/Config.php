<?php

if (Mage::helper('core')->isModuleEnabled('Amasty_Improved')
    && class_exists('Amasty_ImprovedSorting_Model_Rewrite_Config')
) {
    class Profileolabs_Shoppingflux_Model_Export_Rewrite_Catalog_Config_Compatibility extends Amasty_ImprovedSorting_Model_Rewrite_Config
    {
    }
} else {
    class Profileolabs_Shoppingflux_Model_Export_Rewrite_Catalog_Config_Compatibility extends Mage_Catalog_Model_Config
    {
    }
}

class Profileolabs_Shoppingflux_Model_Export_Rewrite_Catalog_Config extends Profileolabs_Shoppingflux_Model_Export_Rewrite_Catalog_Config_Compatibility
{
    public function getAttribute($entityType, $code)
    {
        $attribute = parent::getAttribute($entityType, $code);

        if (is_object($attribute) && ($attribute->getAttributeCode() == '')) {
            $attribute->setAttributeCode($code);
        }

        return $attribute;
    }
}
