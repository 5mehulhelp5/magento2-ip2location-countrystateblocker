<?php

namespace Hexasoft\IP2LocationCountryBlocker\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BlockPageType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'default',   'label' => __('Default (403 HTML)')],
            ['value' => 'cms_block', 'label' => __('CMS Block')],
            ['value' => 'redirect',  'label' => __('Redirect URL')],
        ];
    }
}
