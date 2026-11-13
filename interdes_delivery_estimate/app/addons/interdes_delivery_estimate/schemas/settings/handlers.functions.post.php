<?php

defined('BOOTSTRAP') or die('Access denied');

/**
 * Adds a stable CSS class to the "Show all shipping methods" checkbox so the
 * shipping_ids_toggle.tpl script can find it without relying on the
 * auto-generated (object_id-based) element id.
 *
 * @return array<string, array<string, string>>
 */
function fn_settings_handlers_interdes_delivery_estimate_general_show_all_shipping_methods()
{
    return [
        'input_attributes' => [
            'class' => 'interdes-de-show-all-shipping-methods',
        ],
    ];
}
