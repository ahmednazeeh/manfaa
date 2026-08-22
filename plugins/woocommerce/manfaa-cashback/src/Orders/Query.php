<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Orders;

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Orders by our own meta, on EITHER datastore. HPOS understands
 * `meta_query`; the legacy posts store refuses it with a doing_it_wrong, but
 * lets a plugin inject one through its query filter — so the same call
 * works whichever storage the merchant has switched on.
 */
final class Query
{
    private const VAR = 'manfaa_meta_query';

    public static function hooks(): void
    {
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', static function (array $query, array $vars): array {
            if (! empty($vars[self::VAR]) && is_array($vars[self::VAR])) {
                $query['meta_query'] = array_merge((array) ($query['meta_query'] ?? []), $vars[self::VAR]); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            }

            return $query;
        }, 10, 2);
    }

    /**
     * @param  list<array{key:string,value:string|list<string>,compare?:string}>  $metaQuery
     * @param  array<string, mixed>  $args  other wc_get_orders arguments
     * @return list<\WC_Order>|list<int>
     */
    public static function byMeta(array $metaQuery, array $args = []): array
    {
        $hpos = class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();

        $args += ['limit' => 50];
        $args[$hpos ? 'meta_query' : self::VAR] = $metaQuery;

        $orders = wc_get_orders($args);

        return is_array($orders) ? array_values($orders) : [];
    }
}
