<?php
/**
 * The settings screen. Variables from Settings::render().
 *
 * @var bool $connected
 * @var array|null $profile
 * @var \Manfaa\Cashback\Api\RateCard|null $card
 * @var \Manfaa\Cashback\Pricing\CategoryMap $map
 * @var array $o
 * @var array|false $notice
 * @var string $disconnected
 * @var bool $currencyOk
 * @var array $abilities
 * @var bool $keyMismatch
 * @var int $attention
 * @var bool $isHttps
 * @var object|null $available
 * @var string|null $updateUrl
 */

use Manfaa\Cashback\Admin\Settings;
use Manfaa\Cashback\Api\Connect;
use Manfaa\Cashback\Support\Crypto;
use Manfaa\Cashback\Support\Options;
use Manfaa\Cashback\Webhooks\Receiver;

defined('ABSPATH') || exit;

$action = static fn (string $name): string => wp_nonce_url(admin_url('admin-post.php?action='.$name), $name);
$has = static fn (string $ability) => in_array($ability, $abilities, true);
$terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
$terms = is_array($terms) ? $terms : [];
?>
<div class="wrap manfaa-settings">
    <h1><?php esc_html_e('Manfaa Cashback', 'manfaa-cashback'); ?></h1>

    <?php if (is_array($notice)) : ?>
        <div class="notice notice-<?php echo $notice['kind'] === 'error' || $notice['kind'] === 'denied' ? 'error' : ($notice['kind'] === 'update' ? 'warning' : 'success'); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?>
            <?php if ($notice['kind'] === 'update' && $updateUrl !== null) : ?> <a class="button button-primary button-small" href="<?php echo esc_url($updateUrl); ?>"><?php esc_html_e('Update now', 'manfaa-cashback'); ?></a><?php endif; ?>
        </p></div>
    <?php endif; ?>

    <?php if (! $currencyOk) : ?>
        <div class="notice notice-error"><p><?php
            /* translators: %s: currency */
            printf(esc_html__('This store sells in %s. Manfaa cashback is paid in Maldivian rufiyaa only — nothing will be posted and no estimate is shown until the store currency is MVR.', 'manfaa-cashback'), esc_html(get_woocommerce_currency()));
        ?></p></div>
    <?php endif; ?>

    <?php if ($keyMismatch) : ?>
        <div class="notice notice-error"><p><?php esc_html_e('The site\'s security keys changed since Manfaa was connected, so the stored connection cannot be read. Reconnect below.', 'manfaa-cashback'); ?></p></div>
    <?php elseif ($disconnected !== '' && $connected) : ?>
        <div class="notice notice-error"><p><?php
            /* translators: %s: reason */
            printf(esc_html__('Manfaa rejected the connection (%s). Orders are not being posted. Reconnect below, then use Retry on affected orders.', 'manfaa-cashback'), esc_html($disconnected));
        ?></p></div>
    <?php endif; ?>

    <?php if (get_option('manfaa_cashback_store_notice') === 'suspended') : ?>
        <div class="notice notice-warning"><p><?php esc_html_e('Manfaa has suspended this store for an unpaid settlement. Sales are still recorded but earn no cashback until it is settled.', 'manfaa-cashback'); ?></p></div>
    <?php endif; ?>

    <!-- ───────────── Connection ───────────── -->
    <div class="manfaa-card">
        <h2><?php esc_html_e('Connection', 'manfaa-cashback'); ?></h2>

        <?php if ($connected && ! $keyMismatch) : ?>
            <table class="manfaa-kv">
                <tr><th><?php esc_html_e('Store', 'manfaa-cashback'); ?></th><td><strong><?php echo esc_html((string) ($profile['merchant_name'] ?? '—')); ?></strong>
                    <?php if (! empty($profile['merchant_status']) && $profile['merchant_status'] !== 'active') : ?>
                        <span class="manfaa-badge manfaa-badge--warn"><?php echo esc_html((string) $profile['merchant_status']); ?></span>
                    <?php endif; ?></td></tr>
                <tr><th><?php esc_html_e('Connected via', 'manfaa-cashback'); ?></th><td><?php echo ! empty($profile['connected_from']) ? esc_html__('Connect with Manfaa', 'manfaa-cashback').' · '.esc_html((string) $profile['connected_from']) : esc_html__('API token', 'manfaa-cashback'); ?></td></tr>
                <tr><th><?php esc_html_e('Permissions', 'manfaa-cashback'); ?></th><td>
                    <?php foreach (Connect::SCOPES as $scope) : ?>
                        <span class="manfaa-badge <?php echo $has($scope) ? 'manfaa-badge--ok' : 'manfaa-badge--missing'; ?>"><?php echo esc_html($scope); ?></span>
                    <?php endforeach; ?>
                    <?php if (! $has('transactions:reverse')) : ?><p class="manfaa-warn"><?php esc_html_e('Without transactions:reverse, refunds will NOT reverse cashback — you will pay cashback on refunded sales. Reconnect to grant it.', 'manfaa-cashback'); ?></p><?php endif; ?>
                    <?php if (! $has('customers:lookup')) : ?><p class="manfaa-warn"><?php esc_html_e('Without customers:lookup, codes are not confirmed on the cart; a mistyped code fails when the order posts.', 'manfaa-cashback'); ?></p><?php endif; ?>
                </td></tr>
                <tr><th><?php esc_html_e('Rate card', 'manfaa-cashback'); ?></th><td><?php
                if ($card) {
                    /* translators: 1: rate, 2: minimum, 3: when */
                    printf(esc_html__('Standing rate %1$s%% · minimum sale MVR %2$s · synced %3$s', 'manfaa-cashback'), esc_html(Settings::percent($card->rateBp)), esc_html(\Manfaa\Cashback\Money\Laari::toMvr($card->minEligibleLaari)), esc_html(human_time_diff($card->fetchedAt).' '.__('ago', 'manfaa-cashback')));
                    if ($card->hasCategoryOverrides) {
                        /* translators: %d: count */
                        echo ' · '.esc_html(sprintf(_n('%d category', '%d categories', count($card->categories), 'manfaa-cashback'), count($card->categories)));
                    }
                } else {
                    esc_html_e('Not synced yet', 'manfaa-cashback');
                }
                ?>
                    &nbsp;<a class="button button-small" href="<?php echo esc_url($action('manfaa_sync')); ?>"><?php esc_html_e('Sync now', 'manfaa-cashback'); ?></a></td></tr>
                <tr><th><?php esc_html_e('Webhook', 'manfaa-cashback'); ?></th><td><?php
                if (Receiver::registered()) {
                    esc_html_e('Registered — Manfaa tells this site when your rate changes or a sale is reversed.', 'manfaa-cashback');
                } elseif (! $isHttps) {
                    esc_html_e('Not registered: Manfaa only delivers to https:// sites. Rate changes are picked up hourly instead.', 'manfaa-cashback');
                } elseif (! $has('webhooks:manage')) {
                    esc_html_e('Not registered: the connection lacks webhooks:manage.', 'manfaa-cashback');
                } else {
                    esc_html_e('Not registered.', 'manfaa-cashback');
                    echo ' <a class="button button-small" href="'.esc_url($action('manfaa_webhook')).'">'.esc_html__('Register now', 'manfaa-cashback').'</a>';
                }
                ?></td></tr>
            </table>
            <p>
                <a class="button" href="<?php echo esc_url($action('manfaa_test')); ?>"><?php esc_html_e('Test connection', 'manfaa-cashback'); ?></a>
                <a class="button manfaa-danger" href="<?php echo esc_url($action('manfaa_disconnect')); ?>" onclick="return confirm('<?php echo esc_js(__('Disconnect from Manfaa? New orders will not earn cashback until you reconnect.', 'manfaa-cashback')); ?>')"><?php esc_html_e('Disconnect', 'manfaa-cashback'); ?></a>
            </p>
        <?php else : ?>
            <p><?php esc_html_e('Connect this store to your Manfaa merchant account. You will approve the connection on Manfaa and be brought straight back — nothing to copy.', 'manfaa-cashback'); ?></p>
            <?php if (! $isHttps) : ?>
                <p class="manfaa-warn"><?php esc_html_e('Connect with Manfaa needs this site to be served over https://. Use the token option below on a non-https site.', 'manfaa-cashback'); ?></p>
            <?php endif; ?>
            <p><a class="button button-primary button-hero" href="<?php echo esc_url($action('manfaa_connect')); ?>"><?php esc_html_e('Connect with Manfaa', 'manfaa-cashback'); ?></a></p>
            <details class="manfaa-advanced">
                <summary><?php esc_html_e('Use an API token instead', 'manfaa-cashback'); ?></summary>
                <p class="description"><?php
                    printf(
                        /* translators: %s: link */
                        esc_html__('Create a token in the Manfaa merchant panel under Settings › API access (%s), granting every permission, and paste it here. It is stored encrypted and shown nowhere.', 'manfaa-cashback'),
                        '<a href="'.esc_url(rtrim(Options::string('panel_base_url'), '/').'/settings/api-access?partner='.rawurlencode('WooCommerce').'&abilities='.rawurlencode(implode(',', Connect::SCOPES))).'" target="_blank" rel="noopener">'.esc_html__('open the panel', 'manfaa-cashback').'</a>',
                    );
                ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('manfaa_token'); ?>
                    <input type="hidden" name="action" value="manfaa_token" />
                    <input type="password" name="token" class="regular-text" autocomplete="off" placeholder="12|xxxxxxxx…" />
                    <button class="button"><?php esc_html_e('Save token', 'manfaa-cashback'); ?></button>
                </form>
            </details>
        <?php endif; ?>
        <p class="description"><?php
            $source = Crypto::keySource();
            echo esc_html(match ($source) {
                'constant' => __('Connection secrets are encrypted with MANFAA_CASHBACK_KEY from wp-config.php.', 'manfaa-cashback'),
                'salts' => __('Connection secrets are encrypted with this site\'s AUTH_KEY/AUTH_SALT. Define MANFAA_CASHBACK_KEY in wp-config.php to use a key of your own.', 'manfaa-cashback'),
                default => __('Warning: this site\'s security keys live in the database, so the encryption key for the Manfaa connection does too. Define MANFAA_CASHBACK_KEY in wp-config.php.', 'manfaa-cashback'),
            });
        ?></p>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields(Options::OPTION); ?>

        <!-- ───────────── Cashback pricing ───────────── -->
        <div class="manfaa-card">
            <h2><?php esc_html_e('Cashback pricing', 'manfaa-cashback'); ?></h2>
            <fieldset>
                <label class="manfaa-radio"><input type="radio" name="<?php echo esc_attr(Options::OPTION); ?>[pricing_mode]" value="general" <?php checked($o['pricing_mode'], 'general'); ?> />
                    <strong><?php esc_html_e('General rate', 'manfaa-cashback'); ?></strong> — <?php esc_html_e('the whole eligible amount earns the store\'s standing rate.', 'manfaa-cashback'); ?>
                    <?php if ($card && $card->hasCategoryOverrides) : ?><span class="manfaa-warn"><?php esc_html_e('Your Manfaa store has category-specific rates or exclusions. General pays the standing rate on everything, including products you excluded in the panel.', 'manfaa-cashback'); ?></span><?php endif; ?>
                </label>
                <label class="manfaa-radio"><input type="radio" name="<?php echo esc_attr(Options::OPTION); ?>[pricing_mode]" value="per_category" <?php checked($o['pricing_mode'], 'per_category'); ?> />
                    <strong><?php esc_html_e('Per category', 'manfaa-cashback'); ?></strong> — <?php esc_html_e('map your WooCommerce product categories to your Manfaa categories below.', 'manfaa-cashback'); ?>
                </label>
            </fieldset>

            <div class="manfaa-map" data-manfaa-map>
                <?php if (! $card || ! $card->hasCategoryOverrides) : ?>
                    <p class="description"><?php esc_html_e('Your Manfaa store has no category rates yet. Create them in the merchant panel under Settings › Product categories, then press Sync now.', 'manfaa-cashback'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e('Manfaa category', 'manfaa-cashback'); ?></th><th><?php esc_html_e('Rate', 'manfaa-cashback'); ?></th><th><?php esc_html_e('WooCommerce product categories', 'manfaa-cashback'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($card->categories as $category) : $selected = $map->all()[$category['slug']] ?? []; ?>
                            <tr>
                                <td><strong><?php echo esc_html($category['name_en']); ?></strong><?php if (! empty($category['name_dv'])) : ?> <span dir="rtl" class="manfaa-dv"><?php echo esc_html($category['name_dv']); ?></span><?php endif; ?><br /><code><?php echo esc_html($category['slug']); ?></code></td>
                                <td><?php echo $category['mode'] === 'excluded' ? esc_html__('Excluded (0%)', 'manfaa-cashback') : esc_html(Settings::percent((int) $category['rate_bp']).'%'); ?></td>
                                <td>
                                    <select name="<?php echo esc_attr(Options::OPTION); ?>[category_map][<?php echo esc_attr($category['slug']); ?>][]" multiple size="4" class="manfaa-multi">
                                        <?php foreach ($terms as $term) : ?>
                                            <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected(in_array($term->term_id, $selected, true)); ?>><?php echo esc_html($term->name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($map->orphaned() as $slug) : ?>
                            <tr class="manfaa-orphan"><td colspan="3"><?php
                                /* translators: %s: slug */
                                printf(esc_html__('Mapping for "%s" no longer matches a Manfaa category (it was removed or deactivated in the panel). Products in it now earn the general rate.', 'manfaa-cashback'), esc_html($slug));
                            ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e('Products in unmapped categories earn the general rate. A product in two mapped categories is priced by the one listed first. Hold Ctrl/Cmd to select several.', 'manfaa-cashback'); ?></p>
                <?php endif; ?>
            </div>

            <h3><?php esc_html_e('Cashback awarding policy', 'manfaa-cashback'); ?></h3>
            <fieldset>
                <label class="manfaa-radio"><input type="radio" name="<?php echo esc_attr(Options::OPTION); ?>[awarding_policy]" value="items_ex_tax" <?php checked($o['awarding_policy'], 'items_ex_tax'); ?> /> <?php esc_html_e('Items after discounts, excluding shipping and GST (recommended)', 'manfaa-cashback'); ?></label>
                <label class="manfaa-radio"><input type="radio" name="<?php echo esc_attr(Options::OPTION); ?>[awarding_policy]" value="items_inc_tax" <?php checked($o['awarding_policy'], 'items_inc_tax'); ?> /> <?php esc_html_e('Items after discounts, including GST (shipping still excluded)', 'manfaa-cashback'); ?></label>
            </fieldset>
        </div>

        <!-- ───────────── Cart & checkout ───────────── -->
        <div class="manfaa-card">
            <h2><?php esc_html_e('Cart & checkout', 'manfaa-cashback'); ?></h2>
            <p class="description"><?php esc_html_e('The Manfaa code field is shown on the cart and at checkout whenever this plugin is active. Buyers enter the 6-digit code from their Manfaa app.', 'manfaa-cashback'); ?></p>
            <table class="form-table" role="presentation">
                <tr><th><label for="manfaa-label"><?php esc_html_e('Field label', 'manfaa-cashback'); ?></label></th><td><input id="manfaa-label" type="text" class="regular-text" name="<?php echo esc_attr(Options::OPTION); ?>[panel_label]" value="<?php echo esc_attr($o['panel_label']); ?>" placeholder="<?php esc_attr_e('Manfaa code', 'manfaa-cashback'); ?>" /></td></tr>
                <tr><th><?php esc_html_e('Confirm the code live', 'manfaa-cashback'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(Options::OPTION); ?>[confirm_code_live]" value="1" <?php checked($o['confirm_code_live']); ?> /> <?php esc_html_e('Show a tick and the account holder\'s first name as the code is completed (needs customers:lookup).', 'manfaa-cashback'); ?></label></td></tr>
                <tr><th><?php esc_html_e('Accounts that cannot earn', 'manfaa-cashback'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(Options::OPTION); ?>[post_invalid_customer]" value="1" <?php checked($o['post_invalid_customer']); ?> /> <?php esc_html_e('Still post the sale when the code belongs to an account that cannot currently earn (it records with zero cashback).', 'manfaa-cashback'); ?></label></td></tr>
                <tr><th><?php esc_html_e('Billing phone fallback', 'manfaa-cashback'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(Options::OPTION); ?>[phone_fallback]" value="1" <?php checked($o['phone_fallback']); ?> /> <?php esc_html_e('When no code was entered, use the order\'s billing phone (Maldivian mobiles only) to find the Manfaa account.', 'manfaa-cashback'); ?></label>
                    <p class="description manfaa-warn"><?php esc_html_e('A recycled or mistyped phone number credits a stranger. Manfaa recommends leaving this off.', 'manfaa-cashback'); ?></p></td></tr>
            </table>
        </div>

        <!-- ───────────── Display ───────────── -->
        <div class="manfaa-card">
            <h2><?php esc_html_e('Display', 'manfaa-cashback'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><?php esc_html_e('Estimated cashback', 'manfaa-cashback'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(Options::OPTION); ?>[show_estimate]" value="1" <?php checked($o['show_estimate']); ?> /> <?php esc_html_e('Show the buyer an estimate of the cashback this cart earns, in the totals.', 'manfaa-cashback'); ?></label></td></tr>
                <tr><th><label for="manfaa-wording"><?php esc_html_e('Wording', 'manfaa-cashback'); ?></label></th><td><input id="manfaa-wording" type="text" class="regular-text" name="<?php echo esc_attr(Options::OPTION); ?>[estimate_wording]" value="<?php echo esc_attr($o['estimate_wording']); ?>" placeholder="<?php esc_attr_e('Estimated Manfaa cashback', 'manfaa-cashback'); ?>" /></td></tr>
                <tr><th><?php esc_html_e('Product badge', 'manfaa-cashback'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(Options::OPTION); ?>[show_product_badge]" value="1" <?php checked($o['show_product_badge'] ?? false); ?> /> <?php esc_html_e('Show "Earn up to MVR x cashback" under the price on product pages, from the product\'s category rate.', 'manfaa-cashback'); ?></label></td></tr>
            </table>
        </div>

        <!-- ───────────── Posting ───────────── -->
        <div class="manfaa-card">
            <h2><?php esc_html_e('Posting & reversal', 'manfaa-cashback'); ?></h2>
            <table class="form-table" role="presentation">
                <tr><th><label for="manfaa-status"><?php esc_html_e('Post cashback when the order is', 'manfaa-cashback'); ?></label></th><td>
                    <select id="manfaa-status" name="<?php echo esc_attr(Options::OPTION); ?>[post_on_status]">
                        <?php foreach (Settings::postableStatuses() as $slug => $label) : ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($o['post_on_status'], $slug); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('The buyer sees the cashback as pending from this moment. Posting on Completed means a refund before fulfilment never reaches Manfaa at all.', 'manfaa-cashback'); ?></p></td></tr>
                <tr><th><?php esc_html_e('Reverse cashback', 'manfaa-cashback'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(Options::OPTION); ?>[reverse_on_cancel]" value="1" <?php checked($o['reverse_on_cancel']); ?> /> <?php esc_html_e('When a posted order is cancelled, fully refunded or trashed (recommended — you pay cashback on sales you do not reverse).', 'manfaa-cashback'); ?></label></td></tr>
                <tr><th><?php esc_html_e('On a partial refund', 'manfaa-cashback'); ?></th><td>
                    <label class="manfaa-radio"><input type="radio" name="<?php echo esc_attr(Options::OPTION); ?>[partial_refund_policy]" value="amend" <?php checked($o['partial_refund_policy'], 'amend'); ?> /> <?php esc_html_e('Reduce the cashback to what the buyer kept (recommended)', 'manfaa-cashback'); ?>
                        <span class="description"><?php esc_html_e('Works while the sale is still pending on Manfaa; once it has confirmed, the cashback stays and the order gets a note.', 'manfaa-cashback'); ?></span></label>
                    <label class="manfaa-radio"><input type="radio" name="<?php echo esc_attr(Options::OPTION); ?>[partial_refund_policy]" value="nothing" <?php checked($o['partial_refund_policy'], 'nothing'); ?> /> <?php esc_html_e('Do nothing — the buyer keeps the full cashback', 'manfaa-cashback'); ?></label>
                    <label class="manfaa-radio"><input type="radio" name="<?php echo esc_attr(Options::OPTION); ?>[partial_refund_policy]" value="reverse_all" <?php checked($o['partial_refund_policy'], 'reverse_all'); ?> /> <?php esc_html_e('Reverse the whole sale', 'manfaa-cashback'); ?></label></td></tr>
                <tr><th><?php esc_html_e('Older orders', 'manfaa-cashback'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(Options::OPTION); ?>[only_after_activation]" value="1" <?php checked($o['only_after_activation']); ?> /> <?php esc_html_e('Only post orders placed after this plugin was activated.', 'manfaa-cashback'); ?></label></td></tr>
                <tr><th><label for="manfaa-prefix"><?php esc_html_e('Invoice prefix', 'manfaa-cashback'); ?></label></th><td><input id="manfaa-prefix" type="text" class="small-text" name="<?php echo esc_attr(Options::OPTION); ?>[invoice_prefix]" value="<?php echo esc_attr($o['invoice_prefix']); ?>" placeholder="<?php echo esc_attr(rtrim(Options::invoicePrefix(), '-')); ?>" />
                    <p class="description"><?php esc_html_e('Sent to Manfaa as the invoice number together with the order number. Leave blank for a code derived from this site\'s address. Do not change it once orders have been posted.', 'manfaa-cashback'); ?></p></td></tr>
            </table>
        </div>

        <?php submit_button(); ?>
    </form>

    <!-- ───────────── Status ───────────── -->
    <div class="manfaa-card">
        <h2><?php esc_html_e('Status', 'manfaa-cashback'); ?></h2>
        <table class="manfaa-kv">
            <tr><th><?php esc_html_e('Plugin version', 'manfaa-cashback'); ?></th><td>
                <?php echo esc_html(MANFAA_CASHBACK_VERSION); ?>
                <?php if ($available !== null && $updateUrl !== null) : ?>
                    <span class="manfaa-badge manfaa-badge--warn"><?php
                        /* translators: %s: version */
                        printf(esc_html__('%s available', 'manfaa-cashback'), esc_html((string) $available->new_version));
                    ?></span>
                    <a class="button button-primary button-small" href="<?php echo esc_url($updateUrl); ?>"><?php esc_html_e('Update now', 'manfaa-cashback'); ?></a>
                <?php endif; ?>
                &nbsp;<a class="button button-small" href="<?php echo esc_url($action('manfaa_check_updates')); ?>"><?php esc_html_e('Check for updates', 'manfaa-cashback'); ?></a>
            </td></tr>
            <tr><th><?php esc_html_e('Needs attention', 'manfaa-cashback'); ?></th><td><?php echo $attention > 0 ? '<a href="'.esc_url(admin_url('admin.php?page=wc-orders&manfaa_state=needs_attention')).'">'.esc_html((string) $attention).'</a>' : '0'; ?></td></tr>
            <tr><th><?php esc_html_e('Log', 'manfaa-cashback'); ?></th><td><a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs&source=manfaa-cashback')); ?>"><?php esc_html_e('WooCommerce › Status › Logs › manfaa-cashback', 'manfaa-cashback'); ?></a></td></tr>
            <tr><th><?php esc_html_e('Callback URL', 'manfaa-cashback'); ?></th><td><code><?php echo esc_html(Connect::callbackUrl()); ?></code></td></tr>
            <tr><th><?php esc_html_e('Webhook URL', 'manfaa-cashback'); ?></th><td><code><?php echo esc_html(Receiver::url()); ?></code></td></tr>
        </table>
    </div>
</div>
