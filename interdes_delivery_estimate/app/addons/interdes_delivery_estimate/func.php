<?php

use Tygh\Registry;
use Tygh\Shippings\Shippings;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

/**
 * Returns an add-on setting value.
 *
 * @param string $name    Setting identifier
 * @param mixed  $default Value to use when the setting is empty
 *
 * @return mixed
 */
function fn_interdes_delivery_estimate_get_setting($name, $default = '')
{
    $value = Registry::get('addons.interdes_delivery_estimate.' . $name);

    return ($value === null || $value === '') ? $default : $value;
}

/**
 * Returns the list of shipping method IDs the delivery estimate block is limited to.
 *
 * Returns null when the "Show all shipping methods" setting is on, meaning "no
 * restriction" -- every shipping method available for the product is shown. Returns
 * an array (possibly empty, meaning "show nothing") when specific methods are picked.
 * The final display order is not taken from here — it always follows the position
 * the shipping methods have in Shipping methods management.
 *
 * @return array<int>|null
 */
function fn_interdes_delivery_estimate_get_shipping_ids()
{
    if (fn_interdes_delivery_estimate_get_setting('show_all_shipping_methods', 'Y') === 'Y') {
        return null;
    }

    $setting = fn_interdes_delivery_estimate_get_setting('shipping_ids', []);
    $shipping_ids = array_filter(array_map('intval', array_keys((array) $setting)));

    return array_values(array_unique($shipping_ids));
}

/**
 * Returns the list of shipping methods available for the "Shipping methods to show"
 * setting (settings variants handler, see Tygh\Settings::getVariants()).
 *
 * @return array<int, string>
 */
function fn_settings_variants_addons_interdes_delivery_estimate_shipping_ids()
{
    $shippings = fn_get_available_shippings(fn_get_runtime_company_id(), false);

    return array_column($shippings, 'shipping', 'shipping_id');
}

/**
 * Returns the destination location used to calculate the delivery estimate.
 *
 * Falls back to the default city. If the current customer is logged in
 * and has a city in their profile, it is used instead.
 *
 * @return array{country: string, state: string, city: string, zipcode: string}
 */
function fn_interdes_delivery_estimate_get_location()
{
    $location = [
        'country' => 'RU',
        'state'   => '',
        'city'    => fn_interdes_delivery_estimate_get_setting('default_city', 'Москва'),
        'zipcode' => '',
    ];

    if (!empty($_SESSION['auth']['user_id'])) {
        $user_info = fn_get_user_info($_SESSION['auth']['user_id']);

        if (!empty($user_info['s_city'])) {
            $location['city'] = $user_info['s_city'];
            $location['country'] = !empty($user_info['s_country']) ? $user_info['s_country'] : $location['country'];
            $location['state'] = !empty($user_info['s_state']) ? $user_info['s_state'] : '';
            $location['zipcode'] = !empty($user_info['s_zipcode']) ? $user_info['s_zipcode'] : '';
        }
    }

    /**
     * Allows to override the customer location used by the delivery estimate block
     * on the product page (e.g. to add a user-selectable delivery city later).
     *
     * @param array $location Location data: country, state, city, zipcode
     */
    fn_set_hook('interdes_delivery_estimate_get_location', $location);

    return $location;
}

/**
 * Returns the delivery city currently used for the estimate block (Smarty modifier helper).
 *
 * @param mixed $product_id Unused, allows calling this as a Smarty modifier on $product.product_id
 *
 * @return string
 */
function fn_interdes_delivery_estimate_get_city($product_id = null)
{
    $location = fn_interdes_delivery_estimate_get_location();

    return $location['city'];
}

/**
 * Builds the cache key for a calculated estimate.
 *
 * Deliberately not product-specific, so the cache is shared across the whole catalog
 * (a visitor browsing many products keeps hitting the same handful of cache entries
 * for their city/usergroup/shipping methods instead of missing on every new product).
 * The trade-off: products with different weight/dimensions that would otherwise get a
 * different price from the carrier will show the same cached price/time.
 *
 * Includes the current usergroup IDs, since shipping methods can be restricted by
 * usergroup (see the area 'C' call in fn_interdes_delivery_estimate_get_estimate()) --
 * without it, a result computed for one usergroup would be served to every other
 * usergroup (and to guests) until the cache entry expires.
 *
 * @param array $location      Destination location
 * @param array $shipping_ids  Shipping method identifiers
 * @param array $usergroup_ids Current visitor's usergroup identifiers
 *
 * @return string
 */
function fn_interdes_delivery_estimate_get_cache_key(array $location, array $shipping_ids, array $usergroup_ids)
{
    sort($shipping_ids);
    sort($usergroup_ids);

    return md5(implode('|', [
        $location['country'],
        $location['state'],
        $location['city'],
        $location['zipcode'],
        implode(',', $shipping_ids),
        implode(',', $usergroup_ids),
        CART_LANGUAGE,
        CART_SECONDARY_CURRENCY,
    ]));
}

/**
 * Reads a cached estimate.
 *
 * @param string $cache_key Cache key
 *
 * @return array|null Cached rows, or null when there is no valid cache entry
 */
function fn_interdes_delivery_estimate_get_cache($cache_key)
{
    $data = db_get_field(
        'SELECT data FROM ?:interdes_delivery_estimate_cache WHERE cache_key = ?s AND expires_at > ?i',
        $cache_key,
        TIME
    );

    if ($data === null || $data === '') {
        return null;
    }

    $rows = json_decode($data, true);

    return is_array($rows) ? $rows : null;
}

/**
 * Stores a calculated estimate in the cache and drops expired entries.
 *
 * @param string $cache_key Cache key
 * @param array  $rows      Calculated estimate rows
 *
 * @return void
 */
function fn_interdes_delivery_estimate_set_cache($cache_key, array $rows)
{
    db_query('DELETE FROM ?:interdes_delivery_estimate_cache WHERE expires_at <= ?i', TIME);

    $ttl = (int) fn_interdes_delivery_estimate_get_setting('cache_ttl', 86400);

    db_replace_into('interdes_delivery_estimate_cache', [
        'cache_key'  => $cache_key,
        'data'       => json_encode($rows, JSON_UNESCAPED_UNICODE),
        'expires_at' => TIME + $ttl,
    ]);
}

/**
 * Calculates delivery cost and time for a product using the actual configured
 * shipping methods (real shipping module calculation, not a custom estimate).
 *
 * Results are cached for the lifetime set in the add-on settings, since every
 * cache miss means a request to the carrier's API.
 *
 * @param int $product_id Product ID
 *
 * @return array<int, array{shipping_id: int, name: string, price: float, delivery_time: string}>
 */
function fn_interdes_delivery_estimate_get_estimate($product_id)
{
    static $runtime_cache = [];

    $product_id = (int) $product_id;

    if (empty($product_id)) {
        return [];
    }

    if (isset($runtime_cache[$product_id])) {
        return $runtime_cache[$product_id];
    }

    // null means "no restriction" (the "Show all shipping methods" setting is on);
    // an empty array means specific methods are picked and none of them are selected.
    $shipping_ids = fn_interdes_delivery_estimate_get_shipping_ids();

    if (is_array($shipping_ids) && empty($shipping_ids)) {
        return $runtime_cache[$product_id] = [];
    }

    $auth = $_SESSION['auth'];
    $location = fn_interdes_delivery_estimate_get_location();
    $cache_key = fn_interdes_delivery_estimate_get_cache_key(
        $location,
        (array) $shipping_ids,
        !empty($auth['usergroup_ids']) ? (array) $auth['usergroup_ids'] : []
    );

    $cached = fn_interdes_delivery_estimate_get_cache($cache_key);
    if ($cached !== null) {
        return $runtime_cache[$product_id] = $cached;
    }

    $product_data = fn_get_product_data($product_id, $auth, CART_LANGUAGE, '', false, false, false, false, false, false);

    if (empty($product_data) || ($product_data['is_edp'] === 'Y' && $product_data['edp_shipping'] !== 'Y')) {
        return $runtime_cache[$product_id] = [];
    }

    $product_data['amount'] = 1;
    $product_data['product_id'] = $product_id;

    $product_groups = Shippings::groupProductsList([$product_id => $product_data], $location);

    $result = [];
    $ordered_shipping_ids = [];

    foreach ($product_groups as $group_key => $group) {
        if (!empty($group['shipping_no_required'])) {
            continue;
        }

        // Realtime carriers read the cart from the session at checkout; this flag tells them
        // to build the package from package_info instead, since there is no cart here.
        $group['package_info']['is_not_checkout'] = true;
        $group['package_info_full']['is_not_checkout'] = true;

        // Area 'C' (storefront) so the shipping method's own usergroup restriction is applied,
        // same as at checkout. Storefront is still passed explicitly, since it is only resolved
        // automatically for area 'C' when the storefront_id param is left out.
        $shippings_list = Shippings::getShippingsList($group, CART_LANGUAGE, 'C', [
            'get_images'    => false,
            'storefront_id' => Tygh::$app['storefront']->storefront_id,
        ]);

        if ($shipping_ids !== null) {
            $shippings_list = array_intersect_key($shippings_list, array_flip($shipping_ids));
        }

        if (empty($shippings_list)) {
            continue;
        }

        // $shippings_list is ordered by the shipping method's own position (see
        // Shippings::getShippingsList()); keep that order for the final result.
        foreach (array_keys($shippings_list) as $shipping_id) {
            if (!in_array($shipping_id, $ordered_shipping_ids, true)) {
                $ordered_shipping_ids[] = $shipping_id;
            }
        }

        $shippings = [];
        foreach ($shippings_list as $shipping_id => $shipping) {
            $shippings[] = array_merge($shipping, [
                'package_info'      => $group['package_info'],
                'package_info_full' => $group['package_info_full'],
                'keys'              => [
                    'group_key'   => $group_key,
                    'shipping_id' => $shipping_id,
                    'company_id'  => $group['company_id'],
                ],
            ]);
        }

        $rates = Shippings::calculateRates($shippings);

        foreach ($rates as $rate) {
            if (empty($rate['keys']['shipping_id']) || $rate['price'] === false) {
                continue;
            }

            $shipping_id = $rate['keys']['shipping_id'];

            $result[$shipping_id] = [
                'shipping_id'   => $shipping_id,
                'name'          => $shippings_list[$shipping_id]['shipping'],
                'price'         => $rate['price'],
                'delivery_time' => !empty($rate['service_delivery_time'])
                    ? $rate['service_delivery_time']
                    : (!empty($rate['delivery_time']) ? $rate['delivery_time'] : ''),
            ];
        }
    }

    // Keep the order in which the shipping methods are listed in Shipping methods management
    $rows = [];
    foreach ($ordered_shipping_ids as $shipping_id) {
        if (isset($result[$shipping_id])) {
            $rows[] = $result[$shipping_id];
        }
    }

    /**
     * Allows to modify the calculated delivery estimate shown on the product page.
     *
     * @param int   $product_id Product identifier
     * @param array $location   Location used for the calculation
     * @param array $rows       Calculated estimate rows
     */
    fn_set_hook('interdes_delivery_estimate_get_estimate_post', $product_id, $location, $rows);

    fn_interdes_delivery_estimate_set_cache($cache_key, $rows);

    return $runtime_cache[$product_id] = $rows;
}
