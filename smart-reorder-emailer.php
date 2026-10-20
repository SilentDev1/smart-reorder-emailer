<?php
/**
 * Plugin Name: Smart Reorder Emailer Pro
 * Plugin URI:  https://cao-tech.com/smart-reorder-emailer
 * Description: AI-powered reorder reminders, customer segmentation, predictive analytics, discount codes, and A/B testing for WooCommerce.
 * Version:     5.3.3
 * Author:      Cao-Tech LLC
 * Author URI:  https://cao-tech.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-reorder-emailer
 * Requires at least: 5.8
 * Tested up to: 6.7
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 10.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

define( 'SRE_VERSION', '5.3.3' );
define( 'SRE_FILE', __FILE__ );

function sre_activate() {
    sre_create_tables();
    sre_set_defaults();
    update_option( 'sre_needs_scan', 1 );
    if ( ! wp_next_scheduled( 'sre_daily_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'sre_daily_cron' );
    }
}
register_activation_hook( __FILE__, 'sre_activate' );

function sre_deactivate() {
    wp_clear_scheduled_hook( 'sre_daily_cron' );
    sre_deactivate_license();
}
register_deactivation_hook( __FILE__, 'sre_deactivate' );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sre_action_links' );
function sre_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder&tab=settings' ) ) . '">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

add_action( 'plugins_loaded', 'sre_boot', 20 );
function sre_boot() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Smart Reorder Emailer</strong> requires WooCommerce.</p></div>';
        } );
        return;
    }

    $stored_version = get_option( 'sre_plugin_version', '0' );
    if ( version_compare( $stored_version, SRE_VERSION, '<' ) ) {
        sre_create_tables();
        sre_set_defaults();
        update_option( 'sre_plugin_version', SRE_VERSION );
    }

    add_action( 'admin_menu',    'sre_register_menus' );
    add_action( 'wp_dashboard_setup', 'sre_register_dashboard_widget' );
    add_action( 'admin_init',    'sre_register_settings' );
    add_action( 'sre_daily_cron', 'sre_daily_job' );
    add_action( 'init',          'sre_maybe_schedule' );

    add_action( 'wp_ajax_sre_analyze',           'sre_ajax_analyze' );
    add_action( 'wp_ajax_sre_send_now',          'sre_ajax_send_now' );
    add_action( 'wp_ajax_sre_send_selected',     'sre_ajax_send_selected' );
    add_action( 'wp_ajax_sre_test_email',        'sre_ajax_test_email' );
    add_action( 'wp_ajax_sre_run_segmentation',  'sre_ajax_run_segmentation' );
    add_action( 'wp_ajax_sre_generate_ai_email', 'sre_ajax_generate_ai_email' );
    add_action( 'wp_ajax_sre_create_discount',   'sre_ajax_create_discount' );
    add_action( 'wp_ajax_sre_create_ab_test',    'sre_ajax_create_ab_test' );
    add_action( 'wp_ajax_sre_predict_reorder',   'sre_ajax_predict_reorder' );
    add_action( 'wp_ajax_sre_preview_email',     'sre_ajax_preview_email' );
    add_action( 'wp_ajax_sre_send_previewed',    'sre_ajax_send_previewed' );
    add_action( 'wp_ajax_sre_predict_send',      'sre_ajax_predict_send' );
    add_action( 'wp_ajax_sre_bulk_ready_send',   'sre_ajax_bulk_ready_send' );

    add_action( 'admin_head', 'sre_admin_head_css' );
    add_action( 'template_redirect', 'sre_handle_tracking' );
    add_action( 'template_redirect', 'sre_handle_unsubscribe' );
    add_action( 'wp_ajax_sre_unsub_add',    'sre_ajax_unsub_add' );
    add_action( 'wp_ajax_sre_unsub_remove', 'sre_ajax_unsub_remove' );
    // 5.2.4: reorder-email + loyalty status now lives in a proper SIDE
    // meta box (was previously rendered inline via
    // woocommerce_admin_order_data_after_order_details, which nested it
    // INSIDE the General data column → looked crammed and out of place).
    // Conditional registration: meta box only appears when the order has
    // SRE attribution.
    add_action( 'add_meta_boxes', 'sre_register_order_meta_box', 35, 2 );
    // 5.2.2: same indicator on the orders LIST screen as a "Source" column.
    // Both legacy (posts-based) and HPOS (custom orders table) list table
    // hooks registered so the column appears regardless of which storage
    // mode the store is on. Self-skips on orders that have no SRE source.
    add_filter( 'manage_edit-shop_order_columns', 'sre_register_orders_list_column' );
    add_action( 'manage_shop_order_posts_custom_column', 'sre_render_orders_list_column_legacy', 10, 2 );
    add_filter( 'woocommerce_shop_order_list_table_columns', 'sre_register_orders_list_column' );
    add_action( 'woocommerce_shop_order_list_table_custom_column', 'sre_render_orders_list_column_hpos', 10, 2 );
    // 5.2.4: Source filter dropdown on the orders list — both legacy and
    // HPOS hooks. Filter applied via pre_get_posts (legacy) and the HPOS
    // query-args filter.
    add_action( 'restrict_manage_posts', 'sre_render_orders_list_filter_legacy' );
    add_action( 'woocommerce_order_list_table_restrict_manage_orders', 'sre_render_orders_list_filter_hpos' );
    add_action( 'pre_get_posts', 'sre_apply_orders_list_filter_legacy' );
    add_filter( 'woocommerce_order_list_table_prepare_items_query_args', 'sre_apply_orders_list_filter_hpos' );
    // 5.2.5: clicking the Source badge pops up the actual email the customer
    // received. Modal markup + JS injected on the orders-list screen only.
    add_action( 'admin_footer', 'sre_orders_list_email_viewer_modal' );
    add_action( 'wp_ajax_sre_view_email', 'sre_ajax_view_email' );
    add_action( 'woocommerce_order_status_completed', 'sre_attribute_revenue' );
    add_action( 'woocommerce_order_status_completed', 'sre_schedule_followup', 20 );
    add_action( 'woocommerce_order_status_completed', 'sre_award_loyalty_points', 30 );
    // 5.1.0: real-time un-churn. When a customer in 'churned' or 'at_risk'
    // places a new order, we shouldn't have to wait for tomorrow's daily
    // cron to fix their segment — they should be moved out of churn the
    // moment their order completes. Also clears them from the churn-alert
    // sent log so the next monthly digest will not include a customer who
    // just bought.
    add_action( 'woocommerce_order_status_completed', 'sre_resegment_customer_on_order', 40 );
    add_action( 'sre_send_followup', 'sre_do_followup', 10, 2 );
    add_action( 'woocommerce_account_dashboard', 'sre_loyalty_widget' );

    add_action( 'wp_ajax_sre_create_winback',  'sre_ajax_create_winback' );
    add_action( 'wp_ajax_sre_toggle_winback',  'sre_ajax_toggle_winback' );
    add_action( 'wp_ajax_sre_customer_detail', 'sre_ajax_customer_detail' );

    add_filter( 'wp_mail_from',      'sre_mail_from' );
    add_filter( 'wp_mail_from_name', 'sre_mail_from_name' );
}

/**
 * Multi-currency-safe money formatter. Returns wc_price() HTML when Woo is
 * loaded (which respects the store's configured currency, decimal/thousand
 * separators, and price format). Falls back to a hardcoded $X.XX only when
 * Woo is absent — should not happen on a normally configured store, but we
 * keep the fallback so admin pages do not blank out on partial bootstraps.
 *
 * 5.1.0: replaces ~13 sites that hardcoded '$' . number_format( ..., N ).
 */
function sre_money( $amount, $decimals = 2 ) {
    if ( function_exists( 'wc_price' ) ) {
        return wc_price( (float) $amount, array( 'decimals' => (int) $decimals ) );
    }
    return '$' . number_format( (float) $amount, (int) $decimals );
}

/**
 * Send an email after validating the recipient. Returns the wp_mail() return
 * value, or false if the recipient fails is_email() (in which case nothing
 * is sent and the failure is logged to error_log so the admin has a trail —
 * before 5.1.0 invalid addresses silently failed and the log row was just
 * deleted with no audit signal).
 *
 * 5.3.1: also short-circuits when the recipient is on the unsubscribe list,
 * personalises the {{SRE_UNSUB_URL}} placeholder injected by sre_email_wrap,
 * and attaches the RFC 8058 List-Unsubscribe + List-Unsubscribe-Post headers
 * required by Gmail / Yahoo bulk-sender rules.
 */
function sre_safe_mail( $to, $subject, $body, $context = '' ) {
    if ( ! is_email( $to ) ) {
        error_log( sprintf(
            '[smart-reorder-emailer] Invalid recipient skipped (%s): %s',
            $context !== '' ? $context : 'unknown',
            is_scalar( $to ) ? (string) $to : '[non-scalar]'
        ) );
        return false;
    }
    if ( sre_is_unsubscribed( $to ) ) {
        return false;
    }
    $unsub_url = sre_unsub_url( $to );
    $body      = str_replace( '{{SRE_UNSUB_URL}}', esc_url( $unsub_url ), $body );

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'List-Unsubscribe: <' . $unsub_url . '>, <mailto:' . sre_unsub_mailto_address() . '?subject=unsubscribe&body=' . rawurlencode( $to ) . '>',
        'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
    );
    return wp_mail( $to, $subject, $body, $headers );
}

// ----------------------------------------------------------------
// FEATURE: UNSUBSCRIBE (5.3.1)
// ----------------------------------------------------------------

/**
 * True when the given address is on the suppression list. Email is normalised
 * to lowercase so case-mismatched re-imports do not bypass the gate.
 */
function sre_is_unsubscribed( $email ) {
    $email = strtolower( trim( (string) $email ) );
    if ( $email === '' || ! is_email( $email ) ) {
        return false;
    }
    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_unsubscribes';
    $hit = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$tbl} WHERE customer_email=%s LIMIT 1",
        $email
    ) );
    return $hit ? true : false;
}

/**
 * Insert (or update) a suppression row. Source records who originated the
 * opt-out — 'link' (customer clicked the email footer), 'admin' (manual
 * removal from the backend), 'import' (bulk paste), 'mailto' (reply-to
 * unsubscribe), or 'bounce' (future). Returns true on insert/update.
 */
function sre_add_unsubscribe( $email, $source = 'link', $reason = '' ) {
    $email = strtolower( trim( (string) $email ) );
    if ( $email === '' || ! is_email( $email ) ) {
        return false;
    }
    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_unsubscribes';
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$tbl} WHERE customer_email=%s LIMIT 1",
        $email
    ) );
    if ( $existing ) {
        return true;
    }
    return false !== $wpdb->insert(
        $tbl,
        array(
            'customer_email' => $email,
            'source'         => substr( sanitize_key( $source ), 0, 20 ),
            'reason'         => substr( sanitize_text_field( $reason ), 0, 255 ),
            'created_at'     => current_time( 'mysql' ),
        ),
        array( '%s', '%s', '%s', '%s' )
    );
}

/**
 * Remove a suppression row so the customer can receive emails again.
 */
function sre_remove_unsubscribe( $email ) {
    $email = strtolower( trim( (string) $email ) );
    if ( $email === '' ) {
        return false;
    }
    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_unsubscribes';
    return false !== $wpdb->delete( $tbl, array( 'customer_email' => $email ), array( '%s' ) );
}

/**
 * HMAC signature for the unsubscribe link. Anchored to wp_salt('auth') so it
 * survives plugin reinstalls but cannot be forged off-site. We hash the
 * lowercased email so a flipped-case query string still validates.
 */
function sre_unsub_signature( $email ) {
    $email = strtolower( trim( (string) $email ) );
    return hash_hmac( 'sha256', 'unsub:' . $email, wp_salt( 'auth' ) );
}

/**
 * Build the one-click unsubscribe URL for an address. Pairs with
 * sre_handle_unsubscribe() on template_redirect.
 */
function sre_unsub_url( $email ) {
    $email = strtolower( trim( (string) $email ) );
    return add_query_arg( array(
        'sre_unsub' => '1',
        'e'         => rawurlencode( $email ),
        'sig'       => sre_unsub_signature( $email ),
    ), home_url( '/' ) );
}

/**
 * Address customers can email to opt out manually — used in the mailto
 * variant of the List-Unsubscribe header so Gmail's "unsubscribe" affordance
 * has a fallback when the one-click POST round-trip fails. Defaults to the
 * configured From address, falling back to admin_email.
 */
function sre_unsub_mailto_address() {
    $from = trim( (string) get_option( 'sre_from_email', '' ) );
    if ( $from !== '' && is_email( $from ) ) {
        return $from;
    }
    return (string) get_option( 'admin_email', '' );
}

function sre_mail_from( $email ) {
    $custom = get_option( 'sre_from_email', '' );
    return $custom ? sanitize_email( $custom ) : $email;
}

function sre_mail_from_name( $name ) {
    $custom = get_option( 'sre_from_name', '' );
    return $custom ? sanitize_text_field( $custom ) : $name;
}

function sre_maybe_schedule() {
    if ( ! wp_next_scheduled( 'sre_daily_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'sre_daily_cron' );
    }
}

function sre_daily_job() {
    sre_validate_license();
    if ( ! get_option( 'sre_enabled', 1 ) ) {
        return;
    }
    if ( ! function_exists( 'wc_get_orders' ) ) {
        return;
    }
    sre_run_analysis();
    if ( sre_is_pro() ) {
        sre_run_segmentation();
        sre_run_predictions();
        sre_check_ab_tests();
        sre_process_winback_campaigns();
        sre_build_cross_sells();
        sre_check_churn_alerts();
    }
    sre_process_and_send();
}

// ----------------------------------------------------------------
// LICENCE
// ----------------------------------------------------------------
function sre_license_api_url() {
    return 'https://cao-tech.com';
}

function sre_is_pro() {
    return get_option( 'sre_license_status' ) === 'pro';
}

function sre_license_is_expired() {
    $expires = get_option( 'sre_license_expires', '' );
    return $expires && strtotime( $expires ) < time();
}

function sre_check_key( $key ) {
    $key = strtoupper( trim( $key ) );
    if ( empty( $key ) ) return false;
    $response = wp_remote_post( sre_license_api_url() . '/wp-json/sre-license/v1/activate', array(
        'timeout' => 15,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'license_key' => $key,
            'site_url'    => home_url(),
            'product'     => 'smart-reorder-emailer-pro',
        ) ),
    ) );
    if ( is_wp_error( $response ) ) return false;
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! empty( $body['success'] ) ) {
        update_option( 'sre_license_status', 'pro' );
        update_option( 'sre_license_key', $key );
        update_option( 'sre_license_plan', sanitize_text_field( $body['plan'] ?? 'single' ) );
        update_option( 'sre_license_max_sites', intval( $body['max_sites'] ?? 1 ) );
        if ( ! empty( $body['expires_at'] ) ) {
            update_option( 'sre_license_expires', sanitize_text_field( $body['expires_at'] ) );
        }
        delete_option( 'sre_license_reason' );
        delete_option( 'sre_license_status_message' );
        return true;
    }
    update_option( 'sre_license_status', 'free' );
    if ( ! empty( $body['reason'] ) ) {
        update_option( 'sre_license_reason', sanitize_text_field( $body['reason'] ) );
    }
    if ( ! empty( $body['status_message'] ) ) {
        update_option( 'sre_license_status_message', sanitize_text_field( $body['status_message'] ) );
    } else {
        delete_option( 'sre_license_status_message' );
    }
    return false;
}

function sre_validate_license() {
    $key = get_option( 'sre_license_key', '' );
    if ( empty( $key ) ) {
        update_option( 'sre_license_status', 'free' );
        delete_option( 'sre_license_status_message' );
        delete_option( 'sre_license_validate_failures' );
        return;
    }
    $response = wp_remote_post( sre_license_api_url() . '/wp-json/sre-license/v1/validate', array(
        'timeout' => 15,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'license_key' => $key,
            'site_url'    => home_url(),
            'product'     => 'smart-reorder-emailer-pro',
        ) ),
    ) );
    if ( is_wp_error( $response ) ) {
        // Network blip — keep current status, do not punish the customer.
        return;
    }
    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $body ) ) {
        $body = array();
    }

    // 5.1.0: hardened against transient license-server hiccups.
    // Previously ANY non-success body (5xx with HTML, malformed JSON, empty
    // response) flipped the customer to 'free' and disabled Pro features.
    // Now we treat the result as one of three states:
    //   - explicit pass: body['valid'] === true → mark Pro, clear failure counter
    //   - explicit fail: HTTP 200/401/403 AND body['valid'] === false → downgrade
    //   - ambiguous: anything else (5xx, parse failure, schema change) →
    //     keep current status, increment failure counter, only downgrade
    //     after 3 consecutive ambiguous responses.
    if ( ! empty( $body['valid'] ) ) {
        update_option( 'sre_license_status', 'pro' );
        delete_option( 'sre_license_status_message' );
        delete_option( 'sre_license_reason' );
        delete_option( 'sre_license_validate_failures' );
        return;
    }

    $explicit_fail = in_array( $code, array( 200, 401, 403 ), true ) && array_key_exists( 'valid', $body ) && $body['valid'] === false;

    if ( $explicit_fail ) {
        update_option( 'sre_license_status', 'free' );
        $reason = ! empty( $body['reason'] ) ? sanitize_text_field( $body['reason'] ) : '';
        if ( $reason ) {
            update_option( 'sre_license_reason', $reason );
        } else {
            delete_option( 'sre_license_reason' );
        }
        if ( ! empty( $body['status_message'] ) ) {
            update_option( 'sre_license_status_message', sanitize_text_field( $body['status_message'] ) );
        } else {
            delete_option( 'sre_license_status_message' );
        }
        delete_option( 'sre_license_validate_failures' );
        return;
    }

    // Ambiguous — increment failure counter, only downgrade after 3 in a row.
    $failures = (int) get_option( 'sre_license_validate_failures', 0 ) + 1;
    update_option( 'sre_license_validate_failures', $failures, false );
    if ( $failures >= 3 ) {
        update_option( 'sre_license_status', 'free' );
        update_option( 'sre_license_status_message', 'License server unreachable for 3 consecutive checks. Please verify your license key.' );
        delete_option( 'sre_license_validate_failures' );
    }
}

function sre_deactivate_license() {
    $key = get_option( 'sre_license_key', '' );
    if ( empty( $key ) ) return;
    wp_remote_post( sre_license_api_url() . '/wp-json/sre-license/v1/deactivate', array(
        'timeout' => 15,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'license_key' => $key,
            'site_url'    => home_url(),
            'product'     => 'smart-reorder-emailer-pro',
        ) ),
    ) );
    update_option( 'sre_license_status', 'free' );
    delete_option( 'sre_license_reason' );
    delete_option( 'sre_license_status_message' );
}

// ----------------------------------------------------------------
// UPDATE CHECKER — cao-tech.com SRE License Manager
// ----------------------------------------------------------------
function sre_update_checker_init() {
    add_filter( 'pre_set_site_transient_update_plugins', 'sre_check_for_plugin_update' );
    add_filter( 'plugins_api', 'sre_plugin_update_info', 10, 3 );
    add_action( 'upgrader_process_complete', 'sre_clear_update_cache', 10, 0 );
}
add_action( 'init', 'sre_update_checker_init' );

function sre_fetch_update_data() {
    $cached = get_transient( 'sre_plugin_update_check' );
    if ( is_array( $cached ) ) {
        return $cached;
    }
    $url = add_query_arg( array(
        'slug'        => 'smart-reorder-emailer-pro',
        'version'     => SRE_VERSION,
        'license_key' => get_option( 'sre_license_key', '' ),
        'site_url'    => home_url(),
    ), sre_license_api_url() . '/wp-json/sre-license/v1/update-check' );

    $response = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => true ) );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $body ) || ! isset( $body['slug'] ) ) {
        return null;
    }
    set_transient( 'sre_plugin_update_check', $body, 12 * HOUR_IN_SECONDS );
    return $body;
}

function sre_check_for_plugin_update( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }
    $remote = sre_fetch_update_data();
    if ( ! $remote || empty( $remote['update'] ) ) {
        return $transient;
    }
    $plugin_basename = plugin_basename( SRE_FILE );
    $transient->response[ $plugin_basename ] = (object) array(
        'slug'         => 'smart-reorder-emailer-pro',
        'plugin'       => $plugin_basename,
        'new_version'  => $remote['new_version'],
        'url'          => $remote['url'] ?? 'https://cao-tech.com',
        'package'      => $remote['package'] ?? '',
        'tested'       => $remote['tested'] ?? '',
        'requires'     => $remote['requires'] ?? '',
        'requires_php' => $remote['requires_php'] ?? '',
    );
    return $transient;
}

function sre_plugin_update_info( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== 'smart-reorder-emailer-pro' ) {
        return $result;
    }
    $remote = sre_fetch_update_data();
    if ( ! $remote ) {
        return $result;
    }
    return (object) array(
        'name'          => $remote['name'] ?? 'Smart Reorder Emailer Pro',
        'slug'          => 'smart-reorder-emailer-pro',
        'version'       => $remote['new_version'],
        'author'        => '<a href="https://cao-tech.com">Cao-Tech LLC</a>',
        'homepage'      => $remote['url'] ?? 'https://cao-tech.com',
        'requires'      => $remote['requires'] ?? '',
        'requires_php'  => $remote['requires_php'] ?? '',
        'tested'        => $remote['tested'] ?? '',
        'download_link' => $remote['package'] ?? '',
        'sections'      => array(
            'changelog'   => $remote['changelog'] ?? '<p>See <a href="https://cao-tech.com">cao-tech.com</a> for details.</p>',
            'description' => '<p>AI-powered reorder reminders, customer segmentation, predictive analytics, and dynamic discounts for WooCommerce.</p>',
        ),
    );
}

function sre_clear_update_cache() {
    delete_transient( 'sre_plugin_update_check' );
}

// ----------------------------------------------------------------
// DATABASE
// ----------------------------------------------------------------
function sre_create_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_patterns (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_email  VARCHAR(200)        NOT NULL DEFAULT '',
        product_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        category_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        purchase_count  INT(11)             NOT NULL DEFAULT 1,
        last_order_date DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        avg_days        INT(11)             NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY email_product (customer_email(100), product_id),
        KEY by_email (customer_email(100)),
        KEY by_last  (last_order_date)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_email_log (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_email  VARCHAR(200)        NOT NULL DEFAULT '',
        product_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        email_type      VARCHAR(30)         NOT NULL DEFAULT 'reorder',
        sent_date       DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        ab_test_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        ab_variant      VARCHAR(10)         NOT NULL DEFAULT '',
        discount_code   VARCHAR(50)         NOT NULL DEFAULT '',
        opened          TINYINT(1)          NOT NULL DEFAULT 0,
        clicked         TINYINT(1)          NOT NULL DEFAULT 0,
        converted       TINYINT(1)          NOT NULL DEFAULT 0,
        revenue         DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
        subject         VARCHAR(255)        NOT NULL DEFAULT '',
        body            LONGTEXT            NULL,
        PRIMARY KEY  (id),
        KEY by_email (customer_email(100)),
        KEY by_date  (sent_date),
        KEY by_ab    (ab_test_id)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_segments (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_email  VARCHAR(200)        NOT NULL DEFAULT '',
        segment         VARCHAR(30)         NOT NULL DEFAULT 'new',
        total_orders    INT(11)             NOT NULL DEFAULT 0,
        total_revenue   DECIMAL(12,2)       NOT NULL DEFAULT 0.00,
        avg_order_value DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
        first_order     DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        last_order      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        days_since_last INT(11)             NOT NULL DEFAULT 0,
        order_frequency DECIMAL(8,2)        NOT NULL DEFAULT 0.00,
        predicted_next  DATETIME            NULL,
        risk_score      INT(3)              NOT NULL DEFAULT 0,
        lifetime_value  DECIMAL(12,2)       NOT NULL DEFAULT 0.00,
        updated_at      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        PRIMARY KEY  (id),
        UNIQUE KEY email_unique (customer_email(100)),
        KEY by_segment (segment),
        KEY by_risk    (risk_score)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_ab_tests (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        test_name       VARCHAR(200)        NOT NULL DEFAULT '',
        subject_a       VARCHAR(250)        NOT NULL DEFAULT '',
        subject_b       VARCHAR(250)        NOT NULL DEFAULT '',
        body_a          TEXT                NOT NULL,
        body_b          TEXT                NOT NULL,
        segment_filter  VARCHAR(30)         NOT NULL DEFAULT 'all',
        status          VARCHAR(20)         NOT NULL DEFAULT 'running',
        winner          VARCHAR(10)         NOT NULL DEFAULT '',
        sent_a          INT(11)             NOT NULL DEFAULT 0,
        sent_b          INT(11)             NOT NULL DEFAULT 0,
        opens_a         INT(11)             NOT NULL DEFAULT 0,
        opens_b         INT(11)             NOT NULL DEFAULT 0,
        clicks_a        INT(11)             NOT NULL DEFAULT 0,
        clicks_b        INT(11)             NOT NULL DEFAULT 0,
        conversions_a   INT(11)             NOT NULL DEFAULT 0,
        conversions_b   INT(11)             NOT NULL DEFAULT 0,
        revenue_a       DECIMAL(12,2)       NOT NULL DEFAULT 0.00,
        revenue_b       DECIMAL(12,2)       NOT NULL DEFAULT 0.00,
        created_at      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        ended_at        DATETIME            NULL,
        PRIMARY KEY  (id),
        KEY by_status (status)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_discounts (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        code            VARCHAR(50)         NOT NULL DEFAULT '',
        customer_email  VARCHAR(200)        NOT NULL DEFAULT '',
        segment         VARCHAR(30)         NOT NULL DEFAULT '',
        discount_type   VARCHAR(20)         NOT NULL DEFAULT 'percent',
        amount          DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
        min_spend       DECIMAL(10,2)       NOT NULL DEFAULT 0.00,
        max_uses        INT(11)             NOT NULL DEFAULT 1,
        used_count      INT(11)             NOT NULL DEFAULT 0,
        expires_at      DATETIME            NULL,
        created_at      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        wc_coupon_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY code_unique (code),
        KEY by_email   (customer_email(100)),
        KEY by_segment (segment)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_ai_logs (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        action_type     VARCHAR(50)         NOT NULL DEFAULT '',
        customer_email  VARCHAR(200)        NOT NULL DEFAULT '',
        prompt_summary  TEXT                NOT NULL,
        result_summary  TEXT                NOT NULL,
        tokens_used     INT(11)             NOT NULL DEFAULT 0,
        created_at      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        PRIMARY KEY  (id),
        KEY by_date (created_at)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_email_tracking (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        email_log_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        event_type      VARCHAR(20)         NOT NULL DEFAULT 'open',
        url             VARCHAR(500)        NOT NULL DEFAULT '',
        ip_address      VARCHAR(45)         NOT NULL DEFAULT '',
        user_agent      VARCHAR(500)        NOT NULL DEFAULT '',
        created_at      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        PRIMARY KEY  (id),
        KEY by_log (email_log_id),
        KEY by_event (event_type)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_winback_campaigns (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        campaign_name   VARCHAR(200)        NOT NULL DEFAULT '',
        target_segment  VARCHAR(30)         NOT NULL DEFAULT 'churned',
        step1_days      INT                 NOT NULL DEFAULT 0,
        step1_subject   VARCHAR(250)        NOT NULL DEFAULT '',
        step1_discount  INT                 NOT NULL DEFAULT 0,
        step2_days      INT                 NOT NULL DEFAULT 7,
        step2_subject   VARCHAR(250)        NOT NULL DEFAULT '',
        step2_discount  INT                 NOT NULL DEFAULT 0,
        step3_days      INT                 NOT NULL DEFAULT 14,
        step3_subject   VARCHAR(250)        NOT NULL DEFAULT '',
        step3_discount  INT                 NOT NULL DEFAULT 0,
        status          VARCHAR(20)         NOT NULL DEFAULT 'active',
        emails_sent     INT                 NOT NULL DEFAULT 0,
        conversions     INT                 NOT NULL DEFAULT 0,
        revenue         DECIMAL(12,2)       NOT NULL DEFAULT 0.00,
        created_at      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        PRIMARY KEY  (id),
        KEY by_status (status)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_winback_queue (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        campaign_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        customer_email  VARCHAR(200)        NOT NULL DEFAULT '',
        current_step    INT                 NOT NULL DEFAULT 1,
        next_send_date  DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        status          VARCHAR(20)         NOT NULL DEFAULT 'pending',
        created_at      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        PRIMARY KEY  (id),
        KEY by_campaign (campaign_id),
        KEY by_email (customer_email(100)),
        KEY by_next (next_send_date)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_cross_sells (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        recommended_id  BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        score           INT                 NOT NULL DEFAULT 0,
        updated_at      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        PRIMARY KEY  (id),
        UNIQUE KEY product_pair (product_id, recommended_id),
        KEY by_product (product_id)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_loyalty (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_email  VARCHAR(200)        NOT NULL DEFAULT '',
        tier            VARCHAR(20)         NOT NULL DEFAULT 'bronze',
        points          INT                 NOT NULL DEFAULT 0,
        total_spent     DECIMAL(12,2)       NOT NULL DEFAULT 0.00,
        total_orders    INT                 NOT NULL DEFAULT 0,
        tier_updated_at DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        PRIMARY KEY  (id),
        UNIQUE KEY email_unique (customer_email(100)),
        KEY by_tier (tier),
        KEY by_points (points)
    ) {$c};" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}sre_unsubscribes (
        id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_email  VARCHAR(200)        NOT NULL DEFAULT '',
        source          VARCHAR(20)         NOT NULL DEFAULT 'link',
        reason          VARCHAR(255)        NOT NULL DEFAULT '',
        created_at      DATETIME            NOT NULL DEFAULT '2000-01-01 00:00:00',
        PRIMARY KEY  (id),
        UNIQUE KEY email_unique (customer_email(100)),
        KEY by_source (source)
    ) {$c};" );
}

function sre_set_defaults() {
    $d = array(
        'sre_enabled'         => 1,
        'sre_days'            => 30,
        'sre_min_orders'      => 1,
        'sre_cooldown'        => 7,
        'sre_subject_reorder' => 'Time to restock {product_name}!',
        'sre_subject_suggest' => 'New {category_name} products for you, {customer_name}!',
        'sre_exclude_cats'    => array(),
        'sre_license_key'     => '',
        'sre_license_status'  => 'free',
        'sre_openai_key'      => '',
        'sre_ai_model'        => 'gpt-4o-mini',
        'sre_ai_tone'         => 'friendly',
        'sre_ai_enabled'      => 0,
        'sre_discount_vip'    => 15,
        'sre_discount_loyal'  => 10,
        'sre_discount_atrisk' => 20,
        'sre_discount_churned'=> 25,
        'sre_discount_new'    => 10,
        'sre_followup_enabled'          => 1,
        'sre_followup_delay'            => 7,
        'sre_review_delay'              => 14,
        'sre_followup_subject'          => 'Thank you for your purchase, {customer_name}!',
        'sre_review_subject'            => 'How are you enjoying {product_name}, {customer_name}?',
        'sre_loyalty_enabled'           => 1,
        'sre_loyalty_points_per_dollar' => 1,
        'sre_loyalty_silver_threshold'  => 500,
        'sre_loyalty_gold_threshold'    => 1500,
        'sre_loyalty_platinum_threshold'=> 5000,
        'sre_loyalty_silver_discount'   => 5,
        'sre_loyalty_gold_discount'     => 10,
        'sre_loyalty_platinum_discount' => 15,
        'sre_churn_alerts_enabled'      => 1,
        'sre_churn_alert_email'         => '',
        'sre_churn_alert_threshold'     => 70,
        'sre_churn_alert_min_revenue'   => 100,
    );
    foreach ( $d as $k => $v ) {
        if ( false === get_option( $k ) ) {
            update_option( $k, $v );
        }
    }
}

// ----------------------------------------------------------------
// ANALYSIS
// ----------------------------------------------------------------
function sre_run_analysis() {
    global $wpdb;
    $page  = 1;
    $limit = 200;
    $map   = array();
    $cats  = array();
    // 5.1.0: distinct order tracking. Previously each line item pushed a
    // timestamp into $map[email][pid][], so a customer who bought qty>1 or
    // had multiple line items for the same product on one order produced
    // an inflated purchase_count, and SUM(purchase_count) in segmentation
    // multiplied a single order across N products. We now add at most one
    // timestamp per (email, product, order) tuple, and stash a per-customer
    // distinct-order count for segmentation to consume.
    $seen          = array(); // [email][pid:order_id] => true
    $order_ids_per = array(); // [email] => [order_id => true]

    do {
        $orders = wc_get_orders( array(
            'status'   => array( 'wc-completed', 'completed' ),
            'type'     => 'shop_order',
            'limit'    => $limit,
            'page'     => $page,
            'return'   => 'objects',
            'paginate' => false,
        ) );
        if ( empty( $orders ) ) {
            break;
        }
        $batch_size = count( $orders );
        foreach ( $orders as $order ) {
            $email = strtolower( trim( (string) $order->get_billing_email() ) );
            if ( ! $email ) {
                continue;
            }
            $date = $order->get_date_completed() ? $order->get_date_completed() : $order->get_date_created();
            if ( ! $date ) {
                continue;
            }
            $order_id = (int) $order->get_id();
            $order_ids_per[ $email ][ $order_id ] = true;
            $ts = $date->getTimestamp();
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $pid = (int) $item->get_product_id();
                if ( $pid <= 0 ) {
                    continue;
                }
                // Skip if this (product, order) pair was already counted —
                // protects against multiple line items / qty>1 for the same
                // product on one order.
                $dedup_key = $pid . ':' . $order_id;
                if ( isset( $seen[ $email ][ $dedup_key ] ) ) {
                    continue;
                }
                $seen[ $email ][ $dedup_key ] = true;

                $map[ $email ][ $pid ][] = $ts;
                if ( ! isset( $cats[ $pid ] ) ) {
                    $terms = get_the_terms( $pid, 'product_cat' );
                    $cats[ $pid ] = ( $terms && ! is_wp_error( $terms ) ) ? (int) $terms[0]->term_id : 0;
                }
            }
        }
        $page++;
        // Bail when the API returned a partial page — saves one extra empty
        // round-trip when total orders are an exact multiple of $limit.
    } while ( $batch_size === $limit );

    // Cache distinct-order counts for sre_run_segmentation. Transient is fine
    // because the daily cron runs analysis → segmentation in the same pass.
    $totals = array();
    foreach ( $order_ids_per as $em => $oids ) {
        $totals[ $em ] = count( $oids );
    }
    set_transient( 'sre_customer_order_counts', $totals, DAY_IN_SECONDS );

    if ( empty( $map ) ) {
        return array( 'customers' => 0, 'patterns' => 0 );
    }

    $tbl      = $wpdb->prefix . 'sre_patterns';
    $patterns = 0;
    foreach ( $map as $email => $products ) {
        foreach ( $products as $pid => $ts_list ) {
            sort( $ts_list );
            $count    = count( $ts_list );
            $last_ts  = max( $ts_list );
            $avg_days = 0;
            if ( $count >= 2 ) {
                $gap = 0;
                for ( $i = 1; $i < $count; $i++ ) {
                    $gap += ( $ts_list[ $i ] - $ts_list[ $i - 1 ] ) / 86400;
                }
                $avg_days = (int) round( $gap / ( $count - 1 ) );
            }
            $cat_id    = isset( $cats[ $pid ] ) ? $cats[ $pid ] : 0;
            $last_date = gmdate( 'Y-m-d H:i:s', $last_ts );
            $existing  = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$tbl} WHERE customer_email=%s AND product_id=%d LIMIT 1",
                $email, $pid
            ) );
            if ( $existing ) {
                $wpdb->update( $tbl,
                    array( 'purchase_count' => $count, 'last_order_date' => $last_date, 'avg_days' => $avg_days, 'category_id' => $cat_id ),
                    array( 'id' => (int) $existing ),
                    array( '%d', '%s', '%d', '%d' ),
                    array( '%d' )
                );
            } else {
                $wpdb->insert( $tbl,
                    array( 'customer_email' => $email, 'product_id' => $pid, 'category_id' => $cat_id, 'purchase_count' => $count, 'last_order_date' => $last_date, 'avg_days' => $avg_days ),
                    array( '%s', '%d', '%d', '%d', '%s', '%d' )
                );
            }
            $patterns++;
        }
    }
    return array( 'customers' => count( $map ), 'patterns' => $patterns );
}

// ----------------------------------------------------------------
// PRO: AI CUSTOMER SEGMENTATION
// ----------------------------------------------------------------
function sre_run_segmentation() {
    if ( ! sre_is_pro() ) return;
    global $wpdb;

    // 5.1.0: dropped SUM(purchase_count) — that summed across products and
    // multiplied a single multi-product order into N orders. Now we read the
    // distinct-order count cached by sre_run_analysis() into the
    // sre_customer_order_counts transient. SUM(purchase_count) is kept as a
    // fallback for installs where analysis has not run yet.
    $customers = $wpdb->get_results(
        "SELECT customer_email,
                COUNT(DISTINCT product_id) AS product_count,
                SUM(purchase_count) AS legacy_total_orders,
                MIN(last_order_date) AS first_order,
                MAX(last_order_date) AS last_order,
                AVG(avg_days) AS avg_frequency
         FROM {$wpdb->prefix}sre_patterns
         GROUP BY customer_email"
    );

    $cached_counts = get_transient( 'sre_customer_order_counts' );
    if ( ! is_array( $cached_counts ) ) {
        $cached_counts = array();
    }

    $seg_tbl = $wpdb->prefix . 'sre_segments';

    foreach ( $customers as $c ) {
        $days_since   = max( 0, (int) round( ( time() - strtotime( $c->last_order ) ) / 86400 ) );
        $total_orders = isset( $cached_counts[ $c->customer_email ] )
            ? (int) $cached_counts[ $c->customer_email ]
            : (int) $c->legacy_total_orders;
        $avg_freq = (float) $c->avg_frequency;
        $days_active = max( 1, (int) round( ( strtotime( $c->last_order ) - strtotime( $c->first_order ) ) / 86400 ) );

        $revenue = 0;
        if ( function_exists( 'wc_get_orders' ) ) {
            $orders = wc_get_orders( array(
                'customer' => $c->customer_email,
                'status'   => array( 'wc-completed', 'completed' ),
                'limit'    => -1,
                'return'   => 'objects',
            ) );
            foreach ( $orders as $o ) {
                $revenue += (float) $o->get_total();
            }
        }

        $aov = $total_orders > 0 ? $revenue / $total_orders : 0;

        $segment = sre_calculate_segment( $total_orders, $days_since, $avg_freq, $revenue, $days_active );
        $risk    = sre_calculate_risk( $days_since, $avg_freq, $total_orders );

        $predicted_next = null;
        if ( $avg_freq > 0 && $segment !== 'churned' ) {
            $next_ts = strtotime( $c->last_order ) + ( $avg_freq * 86400 );
            if ( $next_ts >= time() ) {
                $predicted_next = date( 'Y-m-d H:i:s', $next_ts );
            }
        }

        $ltv = $revenue;
        if ( $total_orders >= 2 && $days_active >= 30 ) {
            $orders_per_year = min( $total_orders / ( $days_active / 365 ), 52 );
            $ltv = $aov * $orders_per_year * 2;
            $ltv = max( $ltv, $revenue );
        }

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$seg_tbl} WHERE customer_email=%s LIMIT 1",
            $c->customer_email
        ) );

        $data = array(
            'customer_email'  => $c->customer_email,
            'segment'         => $segment,
            'total_orders'    => $total_orders,
            'total_revenue'   => $revenue,
            'avg_order_value' => round( $aov, 2 ),
            'first_order'     => $c->first_order,
            'last_order'      => $c->last_order,
            'days_since_last' => $days_since,
            'order_frequency' => round( $avg_freq, 2 ),
            'predicted_next'  => $predicted_next,
            'risk_score'      => $risk,
            'lifetime_value'  => round( $ltv, 2 ),
            'updated_at'      => current_time( 'mysql' ),
        );

        if ( $existing ) {
            $wpdb->update( $seg_tbl, $data, array( 'id' => (int) $existing ) );
        } else {
            $wpdb->insert( $seg_tbl, $data );
        }
    }

    return count( $customers );
}

/**
 * 5.1.0: real-time un-churn. Hooked to woocommerce_order_status_completed.
 *
 * When a churned or at-risk customer places a new order, we move them out
 * of churn immediately rather than waiting for the next daily cron tick.
 * Also clears them from the churn-alert sent log so a fresh churn months
 * later will surface in the digest again.
 *
 * Bails silently when:
 *   - Pro is off (segments table is not maintained for free users)
 *   - Order or email is invalid
 *   - The customer has no segments row yet (the daily cron will pick them up
 *     on the next pass; we do not want to half-create rows from one order)
 */
function sre_resegment_customer_on_order( $order_id ) {
    if ( ! sre_is_pro() || ! function_exists( 'wc_get_order' ) ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    $email = strtolower( trim( (string) $order->get_billing_email() ) );
    if ( ! is_email( $email ) ) {
        return;
    }

    global $wpdb;
    $seg_tbl = $wpdb->prefix . 'sre_segments';
    $seg = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$seg_tbl} WHERE customer_email=%s LIMIT 1",
        $email
    ) );
    if ( ! $seg ) {
        return;
    }

    $now      = current_time( 'mysql' );
    $orders   = max( 1, (int) $seg->total_orders + 1 );
    $revenue  = (float) $seg->total_revenue + (float) $order->get_total();
    $aov      = $orders > 0 ? $revenue / $orders : 0;
    $first    = ( $seg->first_order && $seg->first_order !== '2000-01-01 00:00:00' ) ? $seg->first_order : $now;
    $days_active = max( 1, (int) round( ( strtotime( $now ) - strtotime( $first ) ) / 86400 ) );
    $avg_freq = (float) $seg->order_frequency;

    $new_segment = sre_calculate_segment( $orders, 0, $avg_freq, $revenue, $days_active );
    $new_risk    = sre_calculate_risk( 0, $avg_freq, $orders );

    $wpdb->update( $seg_tbl, array(
        'segment'         => $new_segment,
        'total_orders'    => $orders,
        'total_revenue'   => $revenue,
        'avg_order_value' => round( $aov, 2 ),
        'last_order'      => $now,
        'days_since_last' => 0,
        'risk_score'      => $new_risk,
        'updated_at'      => $now,
    ), array( 'id' => $seg->id ) );

    // Drop them from the churn-alert sent log — a re-engaged customer
    // should not count against their next-churn digest suppression.
    $sent_log = get_option( 'sre_churn_alert_sent_log', array() );
    if ( is_array( $sent_log ) && isset( $sent_log[ $email ] ) ) {
        unset( $sent_log[ $email ] );
        update_option( 'sre_churn_alert_sent_log', $sent_log, false );
    }
}

function sre_calculate_segment( $total_orders, $days_since, $avg_freq, $revenue, $days_active ) {
    if ( $total_orders <= 1 && $days_since < 90 ) {
        return 'new';
    }
    if ( $days_since > 180 || ( $avg_freq > 0 && $days_since > $avg_freq * 3 ) ) {
        return 'churned';
    }
    if ( $days_since > 90 || ( $avg_freq > 0 && $days_since > $avg_freq * 2 ) ) {
        return 'at_risk';
    }
    if ( $total_orders >= 10 || $revenue >= 1000 ) {
        return 'vip';
    }
    if ( $total_orders >= 3 ) {
        return 'loyal';
    }
    return 'new';
}

function sre_calculate_risk( $days_since, $avg_freq, $total_orders ) {
    $risk = 0;
    if ( $avg_freq > 0 ) {
        $overdue_ratio = $days_since / $avg_freq;
        if ( $overdue_ratio > 3 ) $risk = 100;
        elseif ( $overdue_ratio > 2 ) $risk = 80;
        elseif ( $overdue_ratio > 1.5 ) $risk = 60;
        elseif ( $overdue_ratio > 1 ) $risk = 40;
        else $risk = max( 0, (int) ( $overdue_ratio * 25 ) );
    } else {
        if ( $days_since > 180 ) $risk = 90;
        elseif ( $days_since > 90 ) $risk = 60;
        elseif ( $days_since > 60 ) $risk = 40;
        elseif ( $days_since > 30 ) $risk = 20;
    }
    if ( $total_orders <= 1 ) {
        $risk = min( 100, $risk + 15 );
    }
    return min( 100, max( 0, $risk ) );
}

// ----------------------------------------------------------------
// PRO: PREDICTIVE REORDER DATES
// ----------------------------------------------------------------
function sre_run_predictions() {
    if ( ! sre_is_pro() ) return;
    global $wpdb;
    $seg_tbl = $wpdb->prefix . 'sre_segments';

    $rows = $wpdb->get_results(
        "SELECT id, customer_email, last_order, order_frequency, days_since_last
         FROM {$seg_tbl}
         WHERE order_frequency > 0 AND segment NOT IN ('churned')"
    );

    foreach ( $rows as $r ) {
        $freq = (float) $r->order_frequency;
        if ( $freq <= 0 ) continue;

        $last_ts  = strtotime( $r->last_order );
        $next_ts  = $last_ts + ( $freq * 86400 );

        if ( $next_ts < time() ) {
            $days_overdue = (int) round( ( time() - $next_ts ) / 86400 );
            if ( $days_overdue > $freq * 2 ) {
                $wpdb->update( $seg_tbl,
                    array( 'predicted_next' => null ),
                    array( 'id' => (int) $r->id )
                );
                continue;
            }
            $cycles   = ceil( ( time() - $next_ts ) / ( $freq * 86400 ) );
            $next_ts  = $next_ts + ( $cycles * $freq * 86400 );
        }

        $predicted = date( 'Y-m-d H:i:s', $next_ts );

        $wpdb->update( $seg_tbl,
            array( 'predicted_next' => $predicted ),
            array( 'id' => (int) $r->id )
        );
    }
}

// ----------------------------------------------------------------
// PRO: OPENAI API KEY ENCRYPTION
// ----------------------------------------------------------------
function sre_cipher_key() {
    if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
        return substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY ), 0, 32 );
    }
    return substr( hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) ), 0, 32 );
}

function sre_encrypt_key( $plain ) {
    if ( empty( $plain ) ) {
        return '';
    }
    if ( ! function_exists( 'openssl_encrypt' ) ) {
        return base64_encode( $plain );
    }
    $iv  = openssl_random_pseudo_bytes( 16 );
    $enc = openssl_encrypt( $plain, 'AES-256-CBC', sre_cipher_key(), 0, $iv );
    return base64_encode( $iv . $enc );
}

function sre_decrypt_key( $stored ) {
    if ( empty( $stored ) ) {
        return '';
    }
    if ( ! function_exists( 'openssl_decrypt' ) ) {
        return base64_decode( $stored );
    }
    $raw = base64_decode( $stored );
    if ( strlen( $raw ) < 16 ) {
        return $stored;
    }
    $iv  = substr( $raw, 0, 16 );
    $enc = substr( $raw, 16 );
    $dec = openssl_decrypt( $enc, 'AES-256-CBC', sre_cipher_key(), 0, $iv );
    return $dec !== false ? $dec : $stored;
}

// ----------------------------------------------------------------
// PRO: OPENAI INTEGRATION
// ----------------------------------------------------------------
function sre_openai_request( $messages, $max_tokens = 500 ) {
    $api_key = sre_decrypt_key( get_option( 'sre_openai_key', '' ) );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_key', 'OpenAI API key not configured. Go to Settings to add it.' );
    }

    $model = get_option( 'sre_ai_model', 'gpt-4o-mini' );

    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode( array(
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $max_tokens,
            'temperature'=> 0.7,
        ) ),
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( isset( $body['error'] ) ) {
        return new WP_Error( 'openai_error', $body['error']['message'] );
    }

    $tokens = isset( $body['usage']['total_tokens'] ) ? (int) $body['usage']['total_tokens'] : 0;
    $content = isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '';

    return array( 'content' => $content, 'tokens' => $tokens );
}

function sre_generate_ai_email( $customer_email, $product_name, $segment, $days_ago, $avg_days, $customer_name, $discount_code = '' ) {
    $tone   = get_option( 'sre_ai_tone', 'friendly' );
    $store  = get_bloginfo( 'name' );

    $discount_info = '';
    if ( $discount_code ) {
        $discount_info = "\nInclude this discount code naturally: {$discount_code}";
    }

    $segment_hints = array(
        'vip'     => 'This is a VIP customer. Make them feel valued and exclusive. Mention their loyalty.',
        'loyal'   => 'This is a loyal customer. Thank them for continuing to shop. Be warm and appreciative.',
        'at_risk' => 'This customer is at risk of leaving. Create urgency. Show you miss them. Offer value.',
        'churned' => 'This customer has not purchased in a long time. Win them back. Be inviting, not pushy.',
        'new'     => 'This is a new customer. Welcome them. Be enthusiastic. Encourage their second purchase.',
    );

    $hint = isset( $segment_hints[ $segment ] ) ? $segment_hints[ $segment ] : '';

    $messages = array(
        array( 'role' => 'system', 'content' => "You are an expert email copywriter for \"{$store}\", an e-commerce store. Write {$tone}, concise reorder reminder emails. Output ONLY the email body HTML (no subject line, no doctype, no wrapping). Use <h2>, <p>, and inline styles. Keep it under 150 words." ),
        array( 'role' => 'user', 'content' => "Write a reorder reminder email for:
- Customer name: {$customer_name}
- Product: {$product_name}
- Customer segment: {$segment}
- Days since last order: {$days_ago}
- Average reorder cycle: {$avg_days} days
- {$hint}
{$discount_info}

Make it personal and compelling. Include a clear call to action." ),
    );

    $result = sre_openai_request( $messages, 600 );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'sre_ai_logs', array(
        'action_type'    => 'email_generation',
        'customer_email' => $customer_email,
        'prompt_summary' => "Email for {$customer_name}, product: {$product_name}, segment: {$segment}",
        'result_summary' => substr( $result['content'], 0, 500 ),
        'tokens_used'    => $result['tokens'],
        'created_at'     => current_time( 'mysql' ),
    ) );

    return $result['content'];
}

function sre_ai_generate_subject_lines( $product_name, $segment, $count = 2 ) {
    $store = get_bloginfo( 'name' );
    $messages = array(
        array( 'role' => 'system', 'content' => "You write compelling email subject lines for \"{$store}\". Output exactly {$count} subject lines, one per line, no numbering or bullets." ),
        array( 'role' => 'user', 'content' => "Generate {$count} reorder reminder subject lines for product \"{$product_name}\" targeting {$segment} customers. Keep each under 60 characters. Make them compelling and personal using {customer_name} and {product_name} placeholders." ),
    );

    $result = sre_openai_request( $messages, 200 );
    if ( is_wp_error( $result ) ) {
        return array( "Time to restock {product_name}!", "We miss you, {customer_name}!" );
    }

    $lines = array_filter( array_map( 'trim', explode( "\n", $result['content'] ) ) );
    return array_slice( $lines, 0, $count );
}

// ----------------------------------------------------------------
// PRO: DYNAMIC DISCOUNT CODES
// ----------------------------------------------------------------
function sre_generate_discount( $customer_email, $segment = '' ) {
    if ( ! sre_is_pro() ) return null;
    global $wpdb;

    if ( empty( $segment ) ) {
        $segment = $wpdb->get_var( $wpdb->prepare(
            "SELECT segment FROM {$wpdb->prefix}sre_segments WHERE customer_email=%s LIMIT 1",
            $customer_email
        ) );
        if ( ! $segment ) $segment = 'new';
    }

    $amounts = array(
        'vip'     => (int) get_option( 'sre_discount_vip', 15 ),
        'loyal'   => (int) get_option( 'sre_discount_loyal', 10 ),
        'at_risk' => (int) get_option( 'sre_discount_atrisk', 20 ),
        'churned' => (int) get_option( 'sre_discount_churned', 25 ),
        'new'     => (int) get_option( 'sre_discount_new', 10 ),
    );

    $amount = isset( $amounts[ $segment ] ) ? $amounts[ $segment ] : 10;

    // 5.1.0: collision-safe code generation. The probability of two
    // wp_generate_password(6) values colliding is ~1 in 36^6, but if it
    // happens $coupon->save() throws and the code below silently writes
    // an orphan row to sre_discounts. We retry up to 5 times against
    // wc_get_coupon_id_by_code(), then give up gracefully.
    $prefix = strtoupper( substr( $segment, 0, 3 ) );
    $code   = '';
    for ( $attempt = 0; $attempt < 5; $attempt++ ) {
        $candidate = 'SRE-' . $prefix . '-' . strtoupper( wp_generate_password( 6, false ) );
        if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || (int) wc_get_coupon_id_by_code( $candidate ) === 0 ) {
            $code = $candidate;
            break;
        }
    }
    if ( $code === '' ) {
        return null; // 5 collisions in a row — give up; caller treats null as "no discount available".
    }

    $expires = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

    $coupon = new WC_Coupon();
    $coupon->set_code( $code );
    $coupon->set_discount_type( 'percent' );
    $coupon->set_amount( $amount );
    $coupon->set_individual_use( true );
    $coupon->set_usage_limit( 1 );
    $coupon->set_email_restrictions( array( $customer_email ) );
    $coupon->set_date_expires( strtotime( '+30 days' ) );
    $coupon->set_description( 'Smart Reorder Pro - Auto-generated for ' . $segment . ' segment' );
    $coupon->save();

    $wpdb->insert( $wpdb->prefix . 'sre_discounts', array(
        'code'           => $code,
        'customer_email' => $customer_email,
        'segment'        => $segment,
        'discount_type'  => 'percent',
        'amount'         => $amount,
        'min_spend'      => 0,
        'max_uses'       => 1,
        'expires_at'     => $expires,
        'created_at'     => current_time( 'mysql' ),
        'wc_coupon_id'   => $coupon->get_id(),
    ) );

    return array(
        'code'    => $code,
        'amount'  => $amount,
        'type'    => 'percent',
        'expires' => $expires,
    );
}

// ----------------------------------------------------------------
// PRO: A/B TESTING
// ----------------------------------------------------------------
function sre_create_ab_test( $name, $subject_a, $subject_b, $body_a = '', $body_b = '', $segment_filter = 'all' ) {
    if ( ! sre_is_pro() ) return false;
    global $wpdb;

    $wpdb->insert( $wpdb->prefix . 'sre_ab_tests', array(
        'test_name'      => $name,
        'subject_a'      => $subject_a,
        'subject_b'      => $subject_b,
        'body_a'         => $body_a,
        'body_b'         => $body_b,
        'segment_filter' => $segment_filter,
        'status'         => 'running',
        'created_at'     => current_time( 'mysql' ),
    ) );

    return $wpdb->insert_id;
}

function sre_get_ab_variant( $test_id, $customer_email = '' ) {
    global $wpdb;
    $test = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}sre_ab_tests WHERE id=%d AND status='running' LIMIT 1",
        $test_id
    ) );
    if ( ! $test ) return null;

    // 5.1.0: sticky assignment. If this customer has already received either
    // variant of this test, return the same one — without this, re-sends
    // after the cooldown drift between A and B and bias both variant counts
    // and the customer experience. Round-robin only applies to brand-new
    // recipients.
    if ( $customer_email !== '' ) {
        $prior = $wpdb->get_var( $wpdb->prepare(
            "SELECT ab_variant FROM {$wpdb->prefix}sre_email_log WHERE customer_email=%s AND ab_test_id=%d AND ab_variant IN ('A','B') ORDER BY sent_date DESC LIMIT 1",
            $customer_email, $test_id
        ) );
        if ( $prior === 'A' || $prior === 'B' ) {
            return $prior;
        }
    }

    return ( $test->sent_a <= $test->sent_b ) ? 'A' : 'B';
}

function sre_record_ab_send( $test_id, $variant ) {
    global $wpdb;
    $col = $variant === 'A' ? 'sent_a' : 'sent_b';
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}sre_ab_tests SET {$col} = {$col} + 1 WHERE id=%d",
        $test_id
    ) );
}

function sre_check_ab_tests() {
    global $wpdb;
    $tests = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}sre_ab_tests WHERE status='running' AND (sent_a + sent_b) >= 100"
    );

    foreach ( $tests as $t ) {
        $min_sends = 50;
        if ( $t->sent_a < $min_sends || $t->sent_b < $min_sends ) continue;

        $rate_a = $t->sent_a > 0 ? ( $t->clicks_a / $t->sent_a ) : 0;
        $rate_b = $t->sent_b > 0 ? ( $t->clicks_b / $t->sent_b ) : 0;

        $diff = abs( $rate_a - $rate_b );
        if ( $diff < 0.02 && ( $t->sent_a + $t->sent_b ) < 500 ) continue;

        $winner = $rate_a >= $rate_b ? 'A' : 'B';

        $wpdb->update( $wpdb->prefix . 'sre_ab_tests',
            array( 'status' => 'completed', 'winner' => $winner, 'ended_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $t->id )
        );
    }
}

// ----------------------------------------------------------------
// SEND EMAILS (Enhanced for Pro)
// ----------------------------------------------------------------
function sre_process_and_send() {
    global $wpdb;
    $pt       = $wpdb->prefix . 'sre_patterns';
    $lt       = $wpdb->prefix . 'sre_email_log';
    $days     = max( 1, (int) get_option( 'sre_days', 30 ) );
    $min      = max( 1, (int) get_option( 'sre_min_orders', 1 ) );
    $cool     = max( 1, (int) get_option( 'sre_cooldown', 7 ) );
    $cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    $cool_cut = gmdate( 'Y-m-d H:i:s', strtotime( "-{$cool} days" ) );
    $excl     = array_map( 'intval', (array) get_option( 'sre_exclude_cats', array() ) );
    $sent     = 0;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.* FROM {$pt} p WHERE p.purchase_count >= %d AND p.last_order_date <= %s
         AND NOT EXISTS (SELECT 1 FROM {$lt} l WHERE l.customer_email=p.customer_email AND l.product_id=p.product_id AND l.sent_date >= %s)
         ORDER BY p.purchase_count DESC, p.last_order_date ASC LIMIT 500",
        $min, $cutoff, $cool_cut
    ) );

    $active_test = null;
    if ( sre_is_pro() ) {
        $active_test = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}sre_ab_tests WHERE status='running' ORDER BY created_at DESC LIMIT 1"
        );
    }

    foreach ( $rows as $row ) {
        if ( ! empty( $excl ) && in_array( (int) $row->category_id, $excl, true ) ) {
            continue;
        }
        $product = wc_get_product( $row->product_id );
        if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            continue;
        }
        // 5.3.1: short-circuit suppressed recipients early so we do not burn
        // OpenAI tokens generating a body that sre_safe_mail will refuse.
        if ( sre_is_unsubscribed( $row->customer_email ) ) {
            continue;
        }
        $name     = sre_get_name( $row->customer_email );
        $days_ago = (int) round( ( time() - strtotime( $row->last_order_date ) ) / 86400 );

        $ab_test_id = 0;
        $ab_variant = '';
        $discount_code = '';

        if ( sre_is_pro() && get_option( 'sre_ai_enabled' ) ) {
            $segment = $wpdb->get_var( $wpdb->prepare(
                "SELECT segment FROM {$wpdb->prefix}sre_segments WHERE customer_email=%s LIMIT 1",
                $row->customer_email
            ) );
            if ( ! $segment ) $segment = 'new';

            $discount = sre_generate_discount( $row->customer_email, $segment );
            if ( $discount ) {
                $discount_code = $discount['code'];
            }

            if ( $active_test ) {
                $variant = sre_get_ab_variant( $active_test->id, $row->customer_email );
                $ab_test_id = (int) $active_test->id;
                $ab_variant = $variant;

                $subject = ( $variant === 'A' ) ? $active_test->subject_a : $active_test->subject_b;
                $subject = str_replace(
                    array( '{product_name}', '{customer_name}', '{days}' ),
                    array( $product->get_name(), $name, $days_ago ),
                    $subject
                );
                sre_record_ab_send( $active_test->id, $variant );
            } else {
                $subject = str_replace(
                    array( '{product_name}', '{customer_name}', '{days}' ),
                    array( $product->get_name(), $name, $days_ago ),
                    get_option( 'sre_subject_reorder', 'Time to restock {product_name}!' )
                );
            }

            $ai_body = sre_generate_ai_email( $row->customer_email, $product->get_name(), $segment, $days_ago, (int) $row->avg_days, $name, $discount_code );
            if ( ! is_wp_error( $ai_body ) ) {
                // 5.1.0: sanitize AI-generated HTML before concatenating with
                // our trusted discount-card / product-card markup. The AI is
                // generally well-behaved but a future model returning
                // <script> tags would otherwise be sent through to the inbox.
                $ai_body = wp_kses_post( $ai_body );
                if ( $discount_code ) {
                    $ai_body .= '<div style="background:#eff6ff;border:2px dashed #2563eb;border-radius:8px;padding:16px;margin:18px 0;text-align:center">';
                    $ai_body .= '<p style="margin:0 0 6px;font-size:13px;color:#1e40af;font-weight:600">Your exclusive discount</p>';
                    $ai_body .= '<p style="margin:0;font-size:24px;font-weight:800;color:#2563eb;letter-spacing:2px">' . esc_html( $discount_code ) . '</p>';
                    $ai_body .= '<p style="margin:6px 0 0;font-size:12px;color:#6b7280">' . (int) $discount['amount'] . '% off - Expires in 30 days</p>';
                    $ai_body .= '</div>';
                }
                $ai_body .= '<hr>' . sre_product_card_html( $product );
                $ai_body .= sre_cross_sell_html( $product->get_id() );
                $body = sre_email_wrap( $ai_body );
            } else {
                $body = sre_email_reorder( $name, $product, $days_ago, (int) $row->avg_days );
            }
        } else {
            $subject = str_replace(
                array( '{product_name}', '{customer_name}', '{days}' ),
                array( $product->get_name(), $name, $days_ago ),
                get_option( 'sre_subject_reorder', 'Time to restock {product_name}!' )
            );
            $body = sre_email_reorder( $name, $product, $days_ago, (int) $row->avg_days );
        }

        $wpdb->insert( $lt,
            array(
                'customer_email' => $row->customer_email,
                'product_id'     => (int) $row->product_id,
                'email_type'     => 'reorder',
                'sent_date'      => current_time( 'mysql' ),
                'ab_test_id'     => $ab_test_id,
                'ab_variant'     => $ab_variant,
                'discount_code'  => $discount_code,
            ),
            array( '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
        );
        $log_id = $wpdb->insert_id;
        $body = sre_add_tracking( $log_id, $body );
        sre_save_email_content( $log_id, $subject, $body );

        if ( sre_safe_mail( $row->customer_email, $subject, $body, 'reorder' ) ) {
            $sent++;
        } else {
            $wpdb->delete( $lt, array( 'id' => $log_id ), array( '%d' ) );
        }
    }
    return $sent;
}

function sre_send_one( $email, $pid ) {
    global $wpdb;
    $pt  = $wpdb->prefix . 'sre_patterns';
    $lt  = $wpdb->prefix . 'sre_email_log';

    $product = wc_get_product( $pid );
    if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
        return false;
    }
    // 5.3.1: refuse single-send to suppressed recipients before any DB churn.
    if ( sre_is_unsubscribed( $email ) ) {
        return false;
    }
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$pt} WHERE customer_email=%s AND product_id=%d LIMIT 1",
        $email, $pid
    ) );
    if ( ! $row ) {
        return false;
    }
    $name     = sre_get_name( $email );
    $days_ago = (int) round( ( time() - strtotime( $row->last_order_date ) ) / 86400 );
    $subject  = str_replace(
        array( '{product_name}', '{customer_name}', '{days}' ),
        array( $product->get_name(), $name, $days_ago ),
        get_option( 'sre_subject_reorder', 'Time to restock {product_name}!' )
    );
    $body = sre_email_reorder( $name, $product, $days_ago, (int) $row->avg_days );

    $wpdb->insert( $lt,
        array( 'customer_email' => $email, 'product_id' => $pid, 'email_type' => 'reorder', 'sent_date' => current_time( 'mysql' ) ),
        array( '%s', '%d', '%s', '%s' )
    );
    $log_id = $wpdb->insert_id;
    $body = sre_add_tracking( $log_id, $body );
    sre_save_email_content( $log_id, $subject, $body );

    if ( sre_safe_mail( $email, $subject, $body, 'send_one' ) ) {
        return true;
    }
    $wpdb->delete( $lt, array( 'id' => $log_id ), array( '%d' ) );
    return false;
}

function sre_get_name( $email ) {
    $user = get_user_by( 'email', $email );
    if ( $user && ! empty( $user->first_name ) ) {
        return $user->first_name;
    }
    $orders = wc_get_orders( array( 'customer' => $email, 'limit' => 1, 'status' => array( 'wc-completed', 'wc-processing' ) ) );
    if ( ! empty( $orders ) ) {
        $n = $orders[0]->get_billing_first_name();
        if ( $n ) {
            return $n;
        }
    }
    return 'there';
}

// ----------------------------------------------------------------
// EMAIL BUILDERS
// ----------------------------------------------------------------
function sre_email_wrap( $content ) {
    $site = esc_html( get_bloginfo( 'name' ) );
    $url  = esc_url( home_url() );
    $out  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $out .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
    $out .= '<style>';
    $out .= 'body{margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#374151}';
    $out .= '.w{max-width:600px;margin:28px auto}';
    $out .= '.h{background:#1e3a5f;padding:26px 36px;border-radius:8px 8px 0 0;text-align:center}';
    $out .= '.h h1{margin:0;color:#ffffff;font-size:20px;font-weight:700}';
    $out .= '.b{background:#ffffff;padding:32px 36px}';
    $out .= '.b h2{margin:0 0 14px;color:#111827;font-size:21px}';
    $out .= '.b p{margin:0 0 14px;font-size:15px;line-height:1.7;color:#374151}';
    $out .= '.note{background:#eff6ff;border-left:4px solid #2563eb;padding:11px 15px;border-radius:4px;margin:0 0 20px;font-size:13px;color:#1e40af}';
    $out .= '.card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:18px;margin-bottom:18px;overflow:hidden}';
    $out .= '.card img{width:110px;height:110px;object-fit:cover;border-radius:6px;float:left;margin:0 16px 8px 0;display:block}';
    $out .= '.pname{margin:0 0 6px;font-size:17px;font-weight:700;color:#111827}';
    $out .= '.price{margin:0 0 10px;font-size:19px;font-weight:700;color:#2563eb}';
    $out .= '.desc{margin:0 0 10px;font-size:13px;color:#6b7280;line-height:1.5}';
    $out .= '.stk-y{margin:0 0 10px;font-size:12px;color:#16a34a;font-weight:700}';
    $out .= '.stk-n{margin:0 0 10px;font-size:12px;color:#dc2626}';
    $out .= '.btn{display:inline-block;padding:11px 24px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:700}';
    $out .= '.btn2{display:inline-block;padding:11px 24px;border:2px solid #2563eb;color:#2563eb;text-decoration:none;border-radius:6px;font-size:14px;font-weight:700}';
    $out .= '.f{background:#f9fafb;padding:20px 36px;border-radius:0 0 8px 8px;border-top:1px solid #e5e7eb;text-align:center;font-size:12px;color:#9ca3af;line-height:1.8}';
    $out .= '.f a{color:#2563eb}';
    $out .= 'hr{border:none;border-top:1px solid #e5e7eb;margin:22px 0}';
    $out .= '</style></head><body>';
    $out .= '<div class="w">';
    $out .= '<div class="h"><h1>' . $site . '</h1></div>';
    $out .= '<div class="b">' . $content . '</div>';
    $out .= '<div class="f">You are receiving this as a customer of <strong>' . $site . '</strong>.<br>';
    $out .= '<a href="' . $url . '">' . $site . '</a>';
    // 5.3.1: per-recipient unsubscribe link. sre_safe_mail() rewrites the
    // {{SRE_UNSUB_URL}} placeholder with the HMAC-signed URL for the actual
    // recipient right before wp_mail() runs, so the same wrapped body can be
    // reused across customers without leaking another customer's token.
    $out .= '<br><span style="font-size:11px;color:#9ca3af">Don&rsquo;t want these emails? <a href="{{SRE_UNSUB_URL}}" style="color:#9ca3af;text-decoration:underline">Unsubscribe</a></span>';
    $out .= '</div>';
    $out .= '</div></body></html>';
    return $out;
}

function sre_product_card_html( $product ) {
    $url  = esc_url( get_permalink( $product->get_id() ) );
    $img  = wp_get_attachment_image_url( $product->get_image_id(), 'medium' );
    $desc = wp_trim_words( strip_tags( $product->get_short_description() ), 20, '...' );
    $stk  = $product->is_in_stock() ? '<p class="stk-y">&#10003; In Stock</p>' : '<p class="stk-n">Out of stock</p>';
    $img_html = $img ? '<img src="' . esc_url( $img ) . '" alt="">' : '';
    $card  = '<div class="card">' . $img_html;
    $card .= '<p class="pname">' . esc_html( $product->get_name() ) . '</p>';
    $card .= ( $desc ? '<p class="desc">' . esc_html( $desc ) . '</p>' : '' );
    $card .= '<p class="price">' . $product->get_price_html() . '</p>';
    $card .= $stk;
    $card .= '<a href="' . $url . '" class="btn">Reorder Now</a>';
    $card .= '</div>';
    return $card;
}

function sre_email_reorder( $name, $product, $days_ago, $avg_days ) {
    $note = '';
    if ( $avg_days > 0 ) {
        $note = '<div class="note">You typically reorder every <strong>' . (int) $avg_days . ' days</strong>. It has been <strong>' . (int) $days_ago . ' days</strong> since your last order.</div>';
    }
    $content  = '<h2>Hi ' . esc_html( $name ) . '!</h2>';
    $content .= '<p>It has been <strong>' . (int) $days_ago . ' day' . ( $days_ago !== 1 ? 's' : '' ) . '</strong> since you ordered <strong>' . esc_html( $product->get_name() ) . '</strong>. You might be running low!</p>';
    $content .= $note;
    $content .= sre_product_card_html( $product );
    $content .= sre_cross_sell_html( $product->get_id() );
    $content .= '<hr><p style="text-align:center;font-size:13px;color:#6b7280"><a href="' . esc_url( wc_get_account_endpoint_url( 'orders' ) ) . '">View your order history</a></p>';
    return sre_email_wrap( $content );
}

function sre_email_suggest( $name, $category, $products ) {
    $content  = '<h2>Hi ' . esc_html( $name ) . '!</h2>';
    $content .= '<p>Since you love our <strong>' . esc_html( $category->name ) . '</strong> range, here are some products you have not tried yet:</p>';
    foreach ( $products as $p ) {
        $url      = esc_url( get_permalink( $p->get_id() ) );
        $img      = wp_get_attachment_image_url( $p->get_image_id(), 'medium' );
        $desc     = wp_trim_words( strip_tags( $p->get_short_description() ), 18, '...' );
        $img_html = $img ? '<img src="' . esc_url( $img ) . '" alt="">' : '';
        $content .= '<div class="card">' . $img_html;
        $content .= '<p class="pname"><a href="' . $url . '" style="color:#111827;text-decoration:none">' . esc_html( $p->get_name() ) . '</a></p>';
        $content .= ( $desc ? '<p class="desc">' . esc_html( $desc ) . '</p>' : '' );
        $content .= '<p class="price">' . $p->get_price_html() . '</p>';
        $content .= '<a href="' . $url . '" class="btn">View Product</a></div>';
    }
    $content .= '<hr><p style="text-align:center"><a href="' . esc_url( get_term_link( $category ) ) . '" class="btn2">Browse All ' . esc_html( $category->name ) . '</a></p>';
    return sre_email_wrap( $content );
}

// ----------------------------------------------------------------
// AJAX HANDLERS
// ----------------------------------------------------------------
function sre_ajax_analyze() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    if ( ! function_exists( 'wc_get_orders' ) ) {
        wp_send_json_error( 'WooCommerce not ready. Please try again.' );
    }
    $r = sre_run_analysis();
    wp_send_json_success( 'Scan complete: ' . $r['customers'] . ' customers, ' . $r['patterns'] . ' patterns saved.' );
}

function sre_ajax_send_now() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $n = sre_process_and_send();
    if ( $n > 0 ) {
        wp_send_json_success( 'Sent ' . $n . ' email' . ( $n > 1 ? 's' : '' ) . ' (in-stock products only).' );
    } else {
        wp_send_json_success( 'No eligible customers right now. Lower Reorder After Days in Settings or run Re-scan first.' );
    }
}

function sre_ajax_send_selected() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $raw   = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
    $items = json_decode( $raw, true );
    if ( empty( $items ) || ! is_array( $items ) ) {
        wp_send_json_error( 'No customers selected.' );
    }
    $sent = 0;
    $skip = 0;
    foreach ( $items as $item ) {
        $email = sanitize_email( isset( $item['email'] ) ? $item['email'] : '' );
        $pid   = (int) ( isset( $item['product'] ) ? $item['product'] : 0 );
        if ( ! $email || ! $pid ) {
            continue;
        }
        if ( sre_send_one( $email, $pid ) ) {
            $sent++;
        } else {
            $skip++;
        }
    }
    $msg = 'Sent ' . $sent . ' email' . ( $sent !== 1 ? 's' : '' ) . '.';
    if ( $skip > 0 ) {
        $msg .= ' ' . $skip . ' skipped (out of stock or not found).';
    }
    if ( $sent > 0 ) {
        wp_send_json_success( $msg );
    } else {
        wp_send_json_error( $msg );
    }
}

function sre_ajax_test_email() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $products = wc_get_products( array( 'limit' => 1, 'status' => 'publish' ) );
    if ( empty( $products ) ) {
        wp_send_json_error( 'No products found.' );
    }
    $body = sre_email_reorder( 'Test Customer', $products[0], 30, 28 );
    // 5.3.1: replace the unsub placeholder with a harmless link for the test
    // copy — admin_email going through sre_safe_mail would attach a real
    // List-Unsubscribe header for the admin, which is undesirable for a test.
    $body = str_replace( '{{SRE_UNSUB_URL}}', esc_url( home_url( '/' ) ), $body );
    $ok   = wp_mail( get_option( 'admin_email' ), '[TEST] Smart Reorder Email', $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    if ( $ok ) {
        wp_send_json_success( 'Test sent to ' . get_option( 'admin_email' ) );
    } else {
        wp_send_json_error( 'Could not send. Check your WordPress email/SMTP settings.' );
    }
}

function sre_ajax_run_segmentation() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) || ! sre_is_pro() ) {
        wp_send_json_error( 'Permission denied or Pro not active.' );
    }
    $count = sre_run_segmentation();
    sre_run_predictions();
    wp_send_json_success( 'Segmentation complete: ' . (int) $count . ' customers analyzed and segmented.' );
}

function sre_ajax_generate_ai_email() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) || ! sre_is_pro() ) {
        wp_send_json_error( 'Permission denied or Pro not active.' );
    }
    $email   = sanitize_email( isset( $_POST['email'] ) ? $_POST['email'] : '' );
    $product = sanitize_text_field( isset( $_POST['product_name'] ) ? $_POST['product_name'] : 'Product' );
    $segment = sanitize_text_field( isset( $_POST['segment'] ) ? $_POST['segment'] : 'new' );

    $result = sre_generate_ai_email( $email, $product, $segment, 30, 28, sre_get_name( $email ) );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    $wrapped = sre_email_wrap( $result );
    // 5.3.1: preview is rendered inline in the admin, not sent — substitute
    // a real per-recipient unsub URL so the admin can verify the link works.
    $wrapped = str_replace( '{{SRE_UNSUB_URL}}', esc_url( sre_unsub_url( $email ) ), $wrapped );
    wp_send_json_success( array( 'html' => $wrapped ) );
}

function sre_ajax_create_discount() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) || ! sre_is_pro() ) {
        wp_send_json_error( 'Permission denied or Pro not active.' );
    }
    $email   = sanitize_email( isset( $_POST['email'] ) ? $_POST['email'] : '' );
    $segment = sanitize_text_field( isset( $_POST['segment'] ) ? $_POST['segment'] : '' );

    if ( ! $email ) {
        wp_send_json_error( 'Email is required.' );
    }

    $result = sre_generate_discount( $email, $segment );
    if ( ! $result ) {
        wp_send_json_error( 'Failed to create discount.' );
    }
    wp_send_json_success( $result );
}

function sre_ajax_create_ab_test() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) || ! sre_is_pro() ) {
        wp_send_json_error( 'Permission denied or Pro not active.' );
    }
    $name      = sanitize_text_field( isset( $_POST['test_name'] ) ? $_POST['test_name'] : '' );
    $subject_a = sanitize_text_field( isset( $_POST['subject_a'] ) ? $_POST['subject_a'] : '' );
    $subject_b = sanitize_text_field( isset( $_POST['subject_b'] ) ? $_POST['subject_b'] : '' );
    $segment   = sanitize_text_field( isset( $_POST['segment_filter'] ) ? $_POST['segment_filter'] : 'all' );

    if ( ! $name || ! $subject_a || ! $subject_b ) {
        wp_send_json_error( 'All fields are required.' );
    }

    $id = sre_create_ab_test( $name, $subject_a, $subject_b, '', '', $segment );
    if ( $id ) {
        wp_send_json_success( 'A/B test created successfully (ID: ' . $id . ').' );
    } else {
        wp_send_json_error( 'Failed to create test.' );
    }
}

function sre_ajax_predict_reorder() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) || ! sre_is_pro() ) {
        wp_send_json_error( 'Permission denied or Pro not active.' );
    }
    sre_run_predictions();
    wp_send_json_success( 'Predictions updated successfully.' );
}

function sre_ajax_predict_send() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) || ! sre_is_pro() ) {
        wp_send_json_error( 'Permission denied.' );
    }

    global $wpdb;
    $pt      = $wpdb->prefix . 'sre_patterns';
    $seg_tbl = $wpdb->prefix . 'sre_segments';
    $lt      = $wpdb->prefix . 'sre_email_log';
    $mode    = sanitize_text_field( isset( $_POST['mode'] ) ? $_POST['mode'] : 'ai' );

    $emails_raw = isset( $_POST['emails'] ) ? $_POST['emails'] : '';
    if ( is_array( $emails_raw ) ) {
        $email_list = array_map( 'sanitize_email', $emails_raw );
    } else {
        $email_list = array( sanitize_email( $emails_raw ) );
    }
    $email_list = array_filter( $email_list );

    if ( empty( $email_list ) ) {
        wp_send_json_error( 'No valid emails provided.' );
    }

    $sent   = 0;
    $failed = 0;
    $errors = array();

    foreach ( $email_list as $email ) {
        $pattern = $wpdb->get_row( $wpdb->prepare(
            "SELECT product_id, last_order_date, avg_days FROM {$pt}
             WHERE customer_email=%s ORDER BY last_order_date DESC LIMIT 1",
            $email
        ) );

        if ( ! $pattern ) {
            $failed++;
            $errors[] = $email . ': no purchase pattern found';
            continue;
        }

        $product = wc_get_product( (int) $pattern->product_id );
        if ( ! $product ) {
            $failed++;
            $errors[] = $email . ': product not found';
            continue;
        }

        $name     = sre_get_name( $email );
        $days_ago = (int) round( ( time() - strtotime( $pattern->last_order_date ) ) / 86400 );
        $avg_days = (int) $pattern->avg_days;

        $seg_row = $wpdb->get_row( $wpdb->prepare( "SELECT segment FROM {$seg_tbl} WHERE customer_email=%s LIMIT 1", $email ) );
        $segment = $seg_row ? $seg_row->segment : 'new';

        $discount_code = '';
        if ( $mode === 'ai' ) {
            $subject = "Re: Your " . $product->get_name() . " from " . get_bloginfo( 'name' );

            $discount = sre_generate_discount( $email, $segment );
            if ( $discount ) {
                $discount_code = $discount['code'];
            }

            $ai_body = sre_generate_ai_email( $email, $product->get_name(), $segment, $days_ago, $avg_days, $name, $discount_code );
            if ( ! is_wp_error( $ai_body ) ) {
                $ai_body = wp_kses_post( $ai_body ); // 5.1.0: sanitize AI HTML before concat.
                if ( $discount_code ) {
                    $ai_body .= '<div style="background:#eff6ff;border:2px dashed #2563eb;border-radius:8px;padding:16px;margin:18px 0;text-align:center">';
                    $ai_body .= '<p style="margin:0 0 6px;font-size:13px;color:#1e40af;font-weight:600">Your exclusive discount</p>';
                    $ai_body .= '<p style="margin:0;font-size:24px;font-weight:800;color:#2563eb;letter-spacing:2px">' . esc_html( $discount_code ) . '</p>';
                    $ai_body .= '<p style="margin:6px 0 0;font-size:12px;color:#6b7280">' . (int) $discount['amount'] . '% off - Expires in 30 days</p>';
                    $ai_body .= '</div>';
                }
                $ai_body .= '<hr>' . sre_product_card_html( $product );
                $body = sre_email_wrap( $ai_body );
            } else {
                $subject = str_replace(
                    array( '{product_name}', '{customer_name}', '{days}' ),
                    array( $product->get_name(), $name, $days_ago ),
                    get_option( 'sre_subject_reorder', 'Time to restock {product_name}!' )
                );
                $body = sre_email_reorder( $name, $product, $days_ago, $avg_days );
            }
        } else {
            $subject = str_replace(
                array( '{product_name}', '{customer_name}', '{days}' ),
                array( $product->get_name(), $name, $days_ago ),
                get_option( 'sre_subject_reorder', 'Time to restock {product_name}!' )
            );
            $body = sre_email_reorder( $name, $product, $days_ago, $avg_days );
        }

        if ( sre_safe_mail( $email, $subject, $body, 'queued' ) ) {
            $wpdb->insert( $lt,
                array(
                    'customer_email' => $email,
                    'product_id'     => (int) $pattern->product_id,
                    'email_type'     => $mode === 'ai' ? 'ai_reorder' : 'reorder',
                    'sent_date'      => current_time( 'mysql' ),
                    'discount_code'  => $discount_code,
                ),
                array( '%s', '%d', '%s', '%s', '%s' )
            );
            sre_save_email_content( (int) $wpdb->insert_id, $subject, $body );
            $sent++;
        } else {
            $failed++;
            $errors[] = $email . ': wp_mail failed';
        }
    }

    $msg = $sent . ' email' . ( $sent !== 1 ? 's' : '' ) . ' sent successfully.';
    if ( $failed > 0 ) {
        $msg .= ' ' . $failed . ' failed.';
    }
    wp_send_json_success( $msg );
}

function sre_ajax_bulk_ready_send() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }

    global $wpdb;
    $pt      = $wpdb->prefix . 'sre_patterns';
    $lt      = $wpdb->prefix . 'sre_email_log';
    $seg_tbl = $wpdb->prefix . 'sre_segments';
    $days    = max( 1, (int) get_option( 'sre_days', 30 ) );
    $cool    = max( 1, (int) get_option( 'sre_cooldown', 7 ) );
    $cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    $cool_cut = gmdate( 'Y-m-d H:i:s', strtotime( "-{$cool} days" ) );
    $mode    = sanitize_text_field( isset( $_POST['mode'] ) ? $_POST['mode'] : 'ai' );
    $excl    = array_map( 'intval', (array) get_option( 'sre_exclude_cats', array() ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.customer_email, p.product_id, p.last_order_date, p.avg_days, p.category_id
         FROM {$pt} p
         WHERE p.last_order_date <= %s
         AND NOT EXISTS (SELECT 1 FROM {$lt} l WHERE l.customer_email=p.customer_email AND l.product_id=p.product_id AND l.sent_date >= %s)
         ORDER BY p.purchase_count DESC
         LIMIT 200",
        $cutoff, $cool_cut
    ) );

    if ( empty( $rows ) ) {
        wp_send_json_error( 'No customers ready for email (all may have been emailed recently).' );
    }

    $sent   = 0;
    $failed = 0;
    $done_customers = array();

    foreach ( $rows as $r ) {
        if ( isset( $done_customers[ $r->customer_email ] ) ) continue;
        if ( ! empty( $excl ) && in_array( (int) $r->category_id, $excl, true ) ) continue;

        $product = wc_get_product( (int) $r->product_id );
        if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) continue;

        $name     = sre_get_name( $r->customer_email );
        $days_ago = (int) round( ( time() - strtotime( $r->last_order_date ) ) / 86400 );
        $avg_days = (int) $r->avg_days;
        $seg_row  = $wpdb->get_row( $wpdb->prepare( "SELECT segment FROM {$seg_tbl} WHERE customer_email=%s LIMIT 1", $r->customer_email ) );
        $segment  = $seg_row ? $seg_row->segment : 'new';

        $discount_code = '';
        if ( $mode === 'ai' && sre_is_pro() ) {
            $subject = "Re: Your " . $product->get_name() . " from " . get_bloginfo( 'name' );
            $discount = sre_generate_discount( $r->customer_email, $segment );
            if ( $discount ) {
                $discount_code = $discount['code'];
            }
            $ai_body = sre_generate_ai_email( $r->customer_email, $product->get_name(), $segment, $days_ago, $avg_days, $name, $discount_code );
            if ( ! is_wp_error( $ai_body ) ) {
                $ai_body = wp_kses_post( $ai_body ); // 5.1.0: sanitize AI HTML before concat.
                if ( $discount_code ) {
                    $ai_body .= '<div style="background:#eff6ff;border:2px dashed #2563eb;border-radius:8px;padding:16px;margin:18px 0;text-align:center">';
                    $ai_body .= '<p style="margin:0 0 6px;font-size:13px;color:#1e40af;font-weight:600">Your exclusive discount</p>';
                    $ai_body .= '<p style="margin:0;font-size:24px;font-weight:800;color:#2563eb;letter-spacing:2px">' . esc_html( $discount_code ) . '</p>';
                    $ai_body .= '<p style="margin:6px 0 0;font-size:12px;color:#6b7280">' . (int) $discount['amount'] . '% off - Expires in 30 days</p>';
                    $ai_body .= '</div>';
                }
                $ai_body .= '<hr>' . sre_product_card_html( $product );
                $body = sre_email_wrap( $ai_body );
            } else {
                $subject = str_replace( array( '{product_name}', '{customer_name}', '{days}' ), array( $product->get_name(), $name, $days_ago ), get_option( 'sre_subject_reorder', 'Time to restock {product_name}!' ) );
                $body = sre_email_reorder( $name, $product, $days_ago, $avg_days );
            }
        } else {
            $subject = str_replace( array( '{product_name}', '{customer_name}', '{days}' ), array( $product->get_name(), $name, $days_ago ), get_option( 'sre_subject_reorder', 'Time to restock {product_name}!' ) );
            $body = sre_email_reorder( $name, $product, $days_ago, $avg_days );
        }

        $wpdb->insert( $lt, array( 'customer_email' => $r->customer_email, 'product_id' => (int) $r->product_id, 'email_type' => $mode === 'ai' ? 'ai_reorder' : 'reorder', 'sent_date' => current_time( 'mysql' ), 'discount_code' => $discount_code ), array( '%s', '%d', '%s', '%s', '%s' ) );
        $log_id = $wpdb->insert_id;
        $body = sre_add_tracking( $log_id, $body );
        sre_save_email_content( $log_id, $subject, $body );

        if ( sre_safe_mail( $r->customer_email, $subject, $body, 'send_now' ) ) {
            $sent++;
        } else {
            $wpdb->delete( $lt, array( 'id' => $log_id ), array( '%d' ) );
            $failed++;
        }
        $done_customers[ $r->customer_email ] = true;
    }

    $msg = $sent . ' email' . ( $sent !== 1 ? 's' : '' ) . ' sent successfully.';
    if ( $failed > 0 ) $msg .= ' ' . $failed . ' failed.';
    if ( count( $rows ) >= 200 ) $msg .= ' (Limited to first 200 — run again for more.)';
    wp_send_json_success( $msg );
}

function sre_ajax_preview_email() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $email = sanitize_email( isset( $_POST['email'] ) ? $_POST['email'] : '' );
    $pid   = (int) ( isset( $_POST['product_id'] ) ? $_POST['product_id'] : 0 );
    $mode  = sanitize_text_field( isset( $_POST['mode'] ) ? $_POST['mode'] : 'standard' );

    if ( ! $email || ! $pid ) {
        wp_send_json_error( 'Missing email or product.' );
    }

    global $wpdb;
    $pt = $wpdb->prefix . 'sre_patterns';
    $product = wc_get_product( $pid );
    if ( ! $product ) {
        wp_send_json_error( 'Product not found.' );
    }

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$pt} WHERE customer_email=%s AND product_id=%d LIMIT 1",
        $email, $pid
    ) );
    $name     = sre_get_name( $email );
    $days_ago = $row ? (int) round( ( time() - strtotime( $row->last_order_date ) ) / 86400 ) : 0;
    $avg_days = $row ? (int) $row->avg_days : 0;

    $subject = str_replace(
        array( '{product_name}', '{customer_name}', '{days}' ),
        array( $product->get_name(), $name, $days_ago ),
        get_option( 'sre_subject_reorder', 'Time to restock {product_name}!' )
    );

    if ( $mode === 'ai' && sre_is_pro() ) {
        $seg_tbl = $wpdb->prefix . 'sre_segments';
        $seg_row = $wpdb->get_row( $wpdb->prepare( "SELECT segment FROM {$seg_tbl} WHERE customer_email=%s LIMIT 1", $email ) );
        $segment = $seg_row ? $seg_row->segment : 'new';

        $ai_body = sre_generate_ai_email( $email, $product->get_name(), $segment, $days_ago, $avg_days, $name );
        if ( is_wp_error( $ai_body ) ) {
            $html = sre_email_reorder( $name, $product, $days_ago, $avg_days );
            $mode = 'standard_fallback';
        } else {
            $ai_body = wp_kses_post( $ai_body );
            $ai_body .= '<hr>' . sre_product_card_html( $product );
            $html = sre_email_wrap( $ai_body );
            $subject = "Re: Your " . $product->get_name() . " from " . get_bloginfo( 'name' );
        }
    } else {
        $html = sre_email_reorder( $name, $product, $days_ago, $avg_days );
    }

    // 5.3.1: substitute the unsub placeholder so the preview iframe renders
    // a real link the admin can click to verify the unsubscribe flow.
    $html = str_replace( '{{SRE_UNSUB_URL}}', esc_url( sre_unsub_url( $email ) ), $html );

    wp_send_json_success( array(
        'subject' => $subject,
        'html'    => $html,
        'email'   => $email,
        'product' => $product->get_name(),
        'product_id' => $pid,
    ) );
}

function sre_ajax_send_previewed() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $email   = sanitize_email( isset( $_POST['email'] ) ? $_POST['email'] : '' );
    $subject = sanitize_text_field( isset( $_POST['subject'] ) ? wp_unslash( $_POST['subject'] ) : '' );
    $html    = isset( $_POST['html'] ) ? wp_kses_post( wp_unslash( $_POST['html'] ) ) : '';
    $pid     = (int) ( isset( $_POST['product_id'] ) ? $_POST['product_id'] : 0 );

    if ( ! $email || ! $subject || ! $html ) {
        wp_send_json_error( 'Missing required fields.' );
    }

    if ( sre_safe_mail( $email, $subject, $html, 'preview_send' ) ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'sre_email_log',
            array( 'customer_email' => $email, 'product_id' => $pid, 'email_type' => 'reorder', 'sent_date' => current_time( 'mysql' ) ),
            array( '%s', '%d', '%s', '%s' )
        );
        sre_save_email_content( (int) $wpdb->insert_id, $subject, $html );
        wp_send_json_success( 'Email sent to ' . $email . '!' );
    } else {
        wp_send_json_error( 'Failed to send email. Check your WordPress mail configuration.' );
    }
}

// ----------------------------------------------------------------
// ADMIN MENUS + SETTINGS
// ----------------------------------------------------------------
function sre_register_menus() {
    add_menu_page( 'Smart Reorder', 'AI Reorder Pro', 'manage_woocommerce', 'smart-reorder', 'sre_page_dashboard', 'dashicons-superhero-alt', 56 );
    add_submenu_page( 'smart-reorder', 'Dashboard',  'Dashboard',  'manage_woocommerce', 'smart-reorder',            'sre_page_dashboard' );
    add_submenu_page( 'smart-reorder', 'Customers',  'Customers',  'manage_woocommerce', 'smart-reorder-customers',  'sre_page_customers_tabbed' );
    add_submenu_page( 'smart-reorder', 'Send Emails','Send Emails','manage_woocommerce', 'smart-reorder-send',       'sre_page_send' );

    if ( sre_is_pro() ) {
        add_submenu_page( 'smart-reorder', 'Campaigns', 'Campaigns', 'manage_woocommerce', 'smart-reorder-campaigns', 'sre_page_campaigns_tabbed' );
        add_submenu_page( 'smart-reorder', 'Analytics', 'Analytics', 'manage_woocommerce', 'smart-reorder-analytics-hub', 'sre_page_analytics_tabbed' );
        add_submenu_page( 'smart-reorder', 'Loyalty',   'Loyalty',   'manage_woocommerce', 'smart-reorder-loyalty',   'sre_page_loyalty' );
    }
    add_submenu_page( 'smart-reorder', 'Pro License', sre_is_pro() ? '✓ Pro License' : 'Pro License', 'manage_woocommerce', 'smart-reorder-upgrade', 'sre_page_upgrade' );

    add_submenu_page( 'smart-reorder', 'Unsubscribes', 'Unsubscribes', 'manage_woocommerce', 'smart-reorder-unsubscribes', 'sre_page_unsubscribes' );
    add_submenu_page( 'smart-reorder', 'Settings', 'Settings', 'manage_woocommerce', 'smart-reorder-settings', 'sre_page_settings' );
}

function sre_render_tabs( $tabs, $current, $page_slug ) {
    echo '<div style="margin:-6px 0 18px;border-bottom:2px solid #e5e7eb;display:flex;gap:0">';
    foreach ( $tabs as $key => $label ) {
        $is_disabled = ! sre_is_pro() && in_array( $key, array( 'segments', 'predictions' ), true );
        $active = $current === $key;
        if ( $is_disabled ) {
            $url   = admin_url( 'admin.php?page=' . $page_slug . '&tab=' . $key );
            $tab_active = $current === $key;
            $style = $tab_active
                ? 'padding:10px 18px;font-size:13px;font-weight:600;color:#7c3aed;border-bottom:2px solid #7c3aed;margin-bottom:-2px;text-decoration:none;background:transparent'
                : 'padding:10px 18px;font-size:13px;font-weight:500;color:#9ca3af;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;background:transparent';
            echo '<a href="' . esc_url( $url ) . '" style="' . $style . '">' . esc_html( $label ) . ' <span style="font-size:10px;padding:1px 6px;border-radius:10px;background:#7c3aed;color:#fff;vertical-align:middle;font-weight:700">PRO</span></a>';
        } else {
            $url   = admin_url( 'admin.php?page=' . $page_slug . '&tab=' . $key );
            $style = $active
                ? 'padding:10px 18px;font-size:13px;font-weight:600;color:#2563eb;border-bottom:2px solid #2563eb;margin-bottom:-2px;text-decoration:none;background:transparent'
                : 'padding:10px 18px;font-size:13px;font-weight:500;color:#6b7280;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;background:transparent';
            echo '<a href="' . esc_url( $url ) . '" style="' . $style . '">' . esc_html( $label ) . '</a>';
        }
    }
    echo '</div>';
}

function sre_page_customers_tabbed() {
    global $sre_active_tabs;
    $tabs = array( 'segments' => 'AI Segments', 'predictions' => 'Predictions', 'patterns' => 'Customer Patterns' );
    if ( ! sre_is_pro() ) {
        $tabs = array(
            'patterns'    => 'Customer Patterns',
            'segments'    => 'AI Segments',
            'predictions' => 'Predictions',
        );
    }
    $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : ( sre_is_pro() ? 'segments' : 'patterns' );
    if ( ! isset( $tabs[ $tab ] ) ) $tab = array_key_first( $tabs );
    $sre_active_tabs = array( 'tabs' => $tabs, 'current' => $tab, 'slug' => 'smart-reorder-customers' );

    if ( $tab === 'patterns' ) {
        sre_page_patterns();
    } elseif ( $tab === 'segments' ) {
        if ( sre_is_pro() ) { sre_page_segments(); }
        else { sre_pro_upgrade_gate( 'AI Segments', 'Upgrade to Pro to use AI-powered customer segmentation. Automatically classify customers as VIP, Loyal, At Risk, Churned, or New.' ); }
    } elseif ( $tab === 'predictions' ) {
        if ( sre_is_pro() ) { sre_page_predictions(); }
        else { sre_pro_upgrade_gate( 'Predictions', 'Upgrade to Pro to use AI-driven reorder predictions. Know exactly when each customer is likely to reorder next.' ); }
    }
    $sre_active_tabs = null;
}

function sre_page_campaigns_tabbed() {
    global $sre_active_tabs;
    if ( ! sre_is_pro() ) { wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ); exit; }
    $tabs = array( 'winback' => 'Win-Back Campaigns', 'discounts' => 'Discount Codes', 'abtests' => 'A/B Tests' );
    $tab  = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'winback';
    if ( ! isset( $tabs[ $tab ] ) ) $tab = 'winback';
    $sre_active_tabs = array( 'tabs' => $tabs, 'current' => $tab, 'slug' => 'smart-reorder-campaigns' );

    if ( $tab === 'winback' ) {
        sre_page_winback();
    } elseif ( $tab === 'discounts' ) {
        sre_page_discounts();
    } elseif ( $tab === 'abtests' ) {
        sre_page_abtests();
    }
    $sre_active_tabs = null;
}

function sre_page_analytics_tabbed() {
    global $sre_active_tabs;
    if ( ! sre_is_pro() ) { wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ); exit; }
    $tabs = array( 'overview' => 'Overview', 'email-log' => 'Email Log', 'ai-log' => 'AI Log' );
    $tab  = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'overview';
    if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';
    $sre_active_tabs = array( 'tabs' => $tabs, 'current' => $tab, 'slug' => 'smart-reorder-analytics-hub' );

    if ( $tab === 'overview' ) {
        sre_page_analytics();
    } elseif ( $tab === 'email-log' ) {
        sre_page_log();
    } elseif ( $tab === 'ai-log' ) {
        sre_page_ailog();
    }
    $sre_active_tabs = null;
}

function sre_register_settings() {
    $fields = array( 'sre_enabled','sre_days','sre_min_orders','sre_cooldown','sre_subject_reorder','sre_subject_suggest','sre_exclude_cats','sre_from_name','sre_from_email' );
    if ( sre_is_pro() ) {
        $fields = array_merge( $fields, array( 'sre_openai_key','sre_ai_model','sre_ai_tone','sre_ai_enabled','sre_discount_vip','sre_discount_loyal','sre_discount_atrisk','sre_discount_churned','sre_discount_new','sre_followup_enabled','sre_followup_delay','sre_review_delay','sre_followup_subject','sre_review_subject','sre_loyalty_enabled','sre_loyalty_points_per_dollar','sre_loyalty_silver_threshold','sre_loyalty_gold_threshold','sre_loyalty_platinum_threshold','sre_loyalty_silver_discount','sre_loyalty_gold_discount','sre_loyalty_platinum_discount','sre_churn_alerts_enabled','sre_churn_alert_email','sre_churn_alert_threshold','sre_churn_alert_min_revenue' ) );
    }
    foreach ( $fields as $k ) {
        if ( $k === 'sre_openai_key' ) {
            register_setting( 'sre_settings', $k, array( 'sanitize_callback' => 'sre_sanitize_openai_key' ) );
        } else {
            register_setting( 'sre_settings', $k );
        }
    }
}

function sre_sanitize_openai_key( $new_value ) {
    if ( isset( $_POST['sre_key_action'] ) && $_POST['sre_key_action'] === 'remove' ) {
        return '';
    }
    $plain = sanitize_text_field( $new_value );
    if ( '' === $plain ) {
        return get_option( 'sre_openai_key', '' );
    }
    if ( strpos( $plain, "\xe2\x80\xa2" ) !== false ) {
        return get_option( 'sre_openai_key', '' );
    }
    return sre_encrypt_key( $plain );
}

// ----------------------------------------------------------------
// HELPER: Page Header + Segment Badge
// ----------------------------------------------------------------
// ----------------------------------------------------------------
// 5.2.0 — UI/UX HELPERS (tooltips, stat cards, sections, page intros)
// ----------------------------------------------------------------

/**
 * Tooltip badge. Renders a small "?" icon that reveals an explanation card
 * on hover or keyboard focus. Use everywhere we show a stat or technical
 * term so admins are not guessing what a number means.
 *
 *   echo sre_tip( 'How many customers are eligible to receive a reorder email today.' );
 */
function sre_tip( $text ) {
    if ( $text === '' ) return '';
    $safe = esc_attr( $text );
    return '<span class="sre-tip" tabindex="0" aria-label="' . $safe . '" data-tip="' . $safe . '">?</span>';
}

/**
 * Standardized stat card with optional tooltip. Pre-5.2.0 every page built
 * its own card markup inline; this consolidates layout + adds the tooltip
 * affordance without changing the visual shape of existing cards.
 *
 * @param string $label   The metric name shown at the top of the card.
 * @param mixed  $value   Pre-formatted display value (string) or numeric (int|float).
 * @param string $color   Hex color for the left accent stripe + value digits.
 * @param string $tooltip Plain-text explanation surfaced via sre_tip().
 * @param string $unit    Optional suffix appended to numeric values (e.g. '%').
 */
function sre_stat_card( $label, $value, $color = '#2563eb', $tooltip = '', $unit = '' ) {
    $display = is_numeric( $value ) ? number_format( (float) $value ) . $unit : (string) $value;
    $tip     = $tooltip !== '' ? ' ' . sre_tip( $tooltip ) : '';
    echo '<div class="sre-stat" style="border-left-color:' . esc_attr( $color ) . '">'
        . '<p class="sre-stat-label">' . esc_html( $label ) . $tip . '</p>'
        . '<p class="sre-stat-value" style="color:' . esc_attr( $color ) . '">' . $display . '</p>'
        . '</div>';
}

/**
 * Section header — H3 + optional one-line description + optional tooltip.
 * Use to break a page into logical groups instead of dumping every stat
 * and control into a flat wall.
 */
function sre_section( $title, $description = '', $tooltip = '' ) {
    echo '<div class="sre-section-head">';
    echo '<h2 class="sre-section-title">' . esc_html( $title );
    if ( $tooltip !== '' ) {
        echo ' ' . sre_tip( $tooltip );
    }
    echo '</h2>';
    if ( $description !== '' ) {
        echo '<p class="sre-section-desc">' . esc_html( $description ) . '</p>';
    }
    echo '</div>';
}

/**
 * Page intro paragraph — appears once below the page header, explains in
 * plain language what the page is for and how the admin should use it.
 */
function sre_page_intro( $text ) {
    if ( $text === '' ) return;
    echo '<p class="sre-page-intro">' . esc_html( $text ) . '</p>';
}

/**
 * Inject the shared admin CSS once per page load. Hooked from sre_boot()
 * via admin_head. Lives in PHP rather than a separate .css file so the
 * plugin remains a single deployable file.
 */
function sre_admin_head_css() {
    // 5.2.1: dropped the screen-ID restriction. The CSS is small (~4KB) and
    // every selector is .sre-* prefixed so it cannot conflict with anything
    // outside this plugin. Loading globally guarantees tooltips work even
    // on tabbed sub-pages where get_current_screen() returns an ID we did
    // not anticipate.
    if ( ! is_admin() ) {
        return;
    }
    echo '<style>
        .sre-page-intro {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 12px 16px;
            margin: 0 0 18px;
            font-size: 13px;
            color: #1e3a8a;
            line-height: 1.55;
            border-radius: 0 6px 6px 0;
            max-width: 980px;
        }
        .sre-section-head {
            margin: 22px 0 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        .sre-section-title {
            margin: 0 0 4px;
            font-size: 15px;
            font-weight: 600;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .sre-section-desc {
            margin: 0;
            font-size: 12px;
            color: #6b7280;
            line-height: 1.5;
        }
        .sre-stat {
            background: #fff;
            border-radius: 8px;
            padding: 16px 18px;
            border-left: 4px solid #2563eb;
            box-shadow: 0 1px 4px rgba(0,0,0,.07);
            position: relative;
            transition: box-shadow .15s;
        }
        .sre-stat:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,.10);
        }
        .sre-stat-label {
            margin: 0 0 4px;
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .sre-stat-value {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
            color: #2563eb;
            line-height: 1.15;
        }
        .sre-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin: 0 0 18px;
        }

        /* Tooltip badge */
        .sre-tip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: #cbd5e1;
            color: #475569;
            font-size: 10px;
            font-weight: 700;
            cursor: help;
            position: relative;
            user-select: none;
            transition: background .12s, color .12s;
            line-height: 1;
        }
        .sre-tip:hover,
        .sre-tip:focus-visible {
            background: #2563eb;
            color: #fff;
            outline: none;
        }
        /* 5.2.1: tooltips now appear BELOW the badge (was above). The
         * previous "above" placement got hidden behind WP admin bar
         * (z-index 99999) for any tooltip in the top ~80px of the viewport.
         * z-index bumped to 100001 to sit ABOVE the admin bar in cases where
         * the tooltip extends upward past it. */
        .sre-tip::before {
            content: attr(data-tip);
            position: absolute;
            top: calc(100% + 10px);
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            color: #f8fafc;
            padding: 9px 13px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.5;
            text-transform: none;
            letter-spacing: 0;
            white-space: normal;
            width: max-content;
            max-width: 320px;
            min-width: 200px;
            opacity: 0;
            visibility: hidden;
            transition: opacity .15s;
            pointer-events: none;
            z-index: 100001;
            box-shadow: 0 6px 18px rgba(0,0,0,.22);
        }
        .sre-tip::after {
            content: "";
            position: absolute;
            top: calc(100% + 4px);
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-bottom-color: #0f172a;
            opacity: 0;
            visibility: hidden;
            transition: opacity .15s;
            z-index: 100001;
        }
        .sre-tip:hover::before,
        .sre-tip:hover::after,
        .sre-tip:focus-visible::before,
        .sre-tip:focus-visible::after {
            opacity: 1;
            visibility: visible;
        }
        /* Right-edge cards: flip tooltip to anchor on the right of the badge
         * so it extends LEFT into the page rather than clipping at viewport. */
        .sre-stat-grid > .sre-stat:nth-last-child(-n+2) .sre-tip::before {
            left: auto;
            right: 0;
            transform: none;
        }
        /* Last column of any wide table (Actions / Risk / Revenue cells):
         * same right-anchor flip so the tooltip card does not run off-screen. */
        th:nth-last-child(-n+3) .sre-tip::before,
        td:nth-last-child(-n+3) .sre-tip::before {
            left: auto;
            right: 0;
            transform: none;
        }

        /* Toolbar group — for filter buttons / actions row */
        .sre-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            background: #fff;
            border-radius: 8px;
            padding: 12px 14px;
            box-shadow: 0 1px 4px rgba(0,0,0,.07);
            margin: 0 0 14px;
        }
        .sre-toolbar-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-right: 4px;
        }
        .sre-toolbar-divider {
            width: 1px;
            height: 22px;
            background: #d1d5db;
            margin: 0 4px;
        }

        /* Explainer / "How this works" callout */
        .sre-explainer {
            background: #fefce8;
            border: 1px solid #fde68a;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
            padding: 14px 16px;
            margin: 0 0 18px;
            font-size: 13px;
            color: #78350f;
            line-height: 1.55;
            max-width: 980px;
        }
        .sre-explainer-title {
            margin: 0 0 4px;
            font-size: 13px;
            font-weight: 700;
            color: #78350f;
        }
        .sre-explainer-body {
            margin: 0;
        }
        .sre-explainer-body strong { color: #78350f; }

        /* Table column header tooltip alignment */
        th .sre-tip {
            margin-left: 4px;
            vertical-align: middle;
        }
    </style>';
}

/**
 * Yellow callout box for "How this works" explainers. Used at the top of
 * pages where the metric or feature is non-obvious (e.g. Predictions).
 *
 * @param string $title HTML-safe heading.
 * @param string $body  HTML-safe body — bold tags allowed via wp_kses.
 */
function sre_explainer( $title, $body ) {
    echo '<div class="sre-explainer">';
    echo '<p class="sre-explainer-title">' . esc_html( $title ) . '</p>';
    echo '<p class="sre-explainer-body">' . wp_kses( $body, array(
        'strong' => array(),
        'em'     => array(),
        'code'   => array(),
        'br'     => array(),
    ) ) . '</p>';
    echo '</div>';
}

function sre_page_header( $title ) {
    global $sre_active_tabs;
    $is_pro = sre_is_pro();
    $pro_badge = $is_pro
        ? '<span style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700"><span class="dashicons dashicons-yes-alt" style="font-size:13px;width:13px;height:13px;line-height:13px"></span>PRO</span>'
        : '<span style="display:inline-flex;align-items:center;gap:4px;background:#e5e7eb;color:#374151;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700">FREE</span>';
    $version_badge = '<span style="background:#e0e7ff;color:#3730a3;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600">v' . SRE_VERSION . '</span>';

    echo '<div class="wrap">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:12px">';
    echo '<div style="display:flex;align-items:center;gap:14px">';
    echo '<div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:flex;align-items:center;justify-content:center"><span class="dashicons dashicons-superhero-alt" style="color:#fff;font-size:24px;width:24px;height:24px"></span></div>';
    echo '<div>';
    echo '<h1 style="margin:0;font-size:22px;line-height:1.3;display:flex;align-items:center;gap:8px">' . $title . ' ' . $version_badge . ' ' . $pro_badge . '</h1>';
    echo '<p style="margin:2px 0 0;color:#6b7280;font-size:13px">AI-Powered Reorder & Customer Retention</p>';
    echo '</div>';
    echo '</div>';
    echo '<span style="color:#9ca3af;font-size:13px">by <a href="https://cao-tech.com" target="_blank" style="color:#6b7280;text-decoration:none;font-weight:500">Cao-Tech LLC</a></span>';
    echo '</div>';

    if ( ! $is_pro ) {
        echo '<div style="margin-bottom:12px"><a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ) . '" style="font-size:12px;color:#7c3aed;font-weight:500;text-decoration:none">&rarr; Upgrade to Pro</a></div>';
    }

    if ( ! empty( $sre_active_tabs ) ) {
        sre_render_tabs( $sre_active_tabs['tabs'], $sre_active_tabs['current'], $sre_active_tabs['slug'] );
    }
}

function sre_pro_upgrade_gate( $title, $description ) {
    $license_url = admin_url( 'admin.php?page=smart-reorder-upgrade' );
    echo '<div style="border:2px dashed #7c3aed;border-radius:12px;padding:40px 30px;text-align:center;background:linear-gradient(135deg,#faf5ff 0%,#f3e8ff 100%);margin:20px 0">';
    echo '<div style="font-size:36px;margin-bottom:12px">🔒</div>';
    echo '<h3 style="margin:0 0 8px;font-size:18px;color:#1f2937">' . esc_html( $title ) . ' <span style="font-size:10px;padding:2px 8px;border-radius:10px;background:#7c3aed;color:#fff;font-weight:700;vertical-align:middle">PRO</span></h3>';
    echo '<p style="color:#6b7280;margin:0 0 16px;max-width:500px;display:inline-block">' . esc_html( $description ) . '</p><br>';
    echo '<a href="' . esc_url( $license_url ) . '" style="display:inline-block;padding:10px 24px;background:#7c3aed;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px">Upgrade to Pro</a>';
    echo '</div>';
}

function sre_segment_badge( $segment ) {
    $colors = array(
        'vip'     => array( '#7c3aed', '#ede9fe', 'VIP' ),
        'loyal'   => array( '#059669', '#d1fae5', 'Loyal' ),
        'at_risk' => array( '#d97706', '#fef3c7', 'At Risk' ),
        'churned' => array( '#dc2626', '#fee2e2', 'Churned' ),
        'new'     => array( '#2563eb', '#dbeafe', 'New' ),
    );
    $c = isset( $colors[ $segment ] ) ? $colors[ $segment ] : array( '#6b7280', '#f3f4f6', ucfirst( $segment ) );
    return '<span style="display:inline-block;font-size:11px;font-weight:700;padding:2px 10px;border-radius:12px;background:' . $c[1] . ';color:' . $c[0] . '">' . $c[2] . '</span>';
}

function sre_risk_bar( $score ) {
    $color = '#16a34a';
    if ( $score >= 70 ) $color = '#dc2626';
    elseif ( $score >= 40 ) $color = '#d97706';
    return '<div style="display:flex;align-items:center;gap:6px"><div style="width:60px;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden"><div style="height:100%;width:' . min( 100, $score ) . '%;background:' . $color . ';border-radius:3px"></div></div><span style="font-size:11px;font-weight:600;color:' . $color . '">' . (int) $score . '</span></div>';
}

function sre_segment_action( $segment, $days_since, $total_orders, $risk_score ) {
    $actions = array(
        'vip' => array(
            'icon'  => '&#9733;',
            'color' => '#7c3aed',
            'bg'    => '#ede9fe',
            'text'  => 'Send exclusive loyalty reward',
            'tip'   => 'VIP customers respond best to exclusivity. Offer early access or VIP-only discounts.',
        ),
        'loyal' => array(
            'icon'  => '&#10084;',
            'color' => '#059669',
            'bg'    => '#d1fae5',
            'text'  => 'Send thank-you with small perk',
            'tip'   => 'Keep loyal customers engaged. A simple thank you + 10% off keeps them coming back.',
        ),
        'at_risk' => array(
            'icon'  => '&#9888;',
            'color' => '#d97706',
            'bg'    => '#fef3c7',
            'text'  => 'Send win-back email with 15% off',
            'tip'   => 'Act now before they churn. Urgency + a meaningful discount can win them back.',
        ),
        'churned' => array(
            'icon'  => '&#128680;',
            'color' => '#dc2626',
            'bg'    => '#fee2e2',
            'text'  => 'Send aggressive re-engagement (25% off)',
            'tip'   => 'Last chance to recover. Offer biggest discount + remind them what they loved.',
        ),
        'new' => array(
            'icon'  => '&#127381;',
            'color' => '#2563eb',
            'bg'    => '#dbeafe',
            'text'  => 'Send welcome follow-up with 10% off',
            'tip'   => 'Encourage their 2nd purchase. Welcome emails + small incentive converts new to loyal.',
        ),
    );

    $a = isset( $actions[ $segment ] ) ? $actions[ $segment ] : $actions['new'];

    if ( $segment === 'at_risk' && $days_since > 120 ) {
        $a['text'] = 'Urgent: Send 20% off before they churn';
        $a['color'] = '#dc2626';
        $a['bg']    = '#fee2e2';
    }
    if ( $segment === 'loyal' && $total_orders >= 8 ) {
        $a['text'] = 'Upgrade to VIP: send premium reward';
    }
    if ( $segment === 'new' && $days_since > 60 ) {
        $a['text'] = 'Nudge now: remind about first reorder';
        $a['color'] = '#d97706';
        $a['bg']    = '#fef3c7';
    }

    return '<div style="font-size:11px;line-height:1.4" title="' . esc_attr( $a['tip'] ) . '">'
        . '<span style="display:inline-block;padding:2px 8px;border-radius:6px;background:' . esc_attr( $a['bg'] ) . ';color:' . esc_attr( $a['color'] ) . ';font-weight:600">'
        . $a['icon'] . ' ' . esc_html( $a['text'] ) . '</span></div>';
}

// ----------------------------------------------------------------
// PAGE: DASHBOARD
// ----------------------------------------------------------------
function sre_page_dashboard() {
    global $wpdb;
    $pt = $wpdb->prefix . 'sre_patterns';
    $lt = $wpdb->prefix . 'sre_email_log';

    $total_customers = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT customer_email) FROM {$pt}" );
    $total_patterns  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pt}" );
    $emails_total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt}" );
    $emails_week     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$lt} WHERE sent_date >= %s", gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ) ) );
    $days            = max( 1, (int) get_option( 'sre_days', 30 ) );
    $min             = max( 1, (int) get_option( 'sre_min_orders', 1 ) );
    $cutoff          = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    $ready_count     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pt} WHERE last_order_date <= %s AND purchase_count >= %d", $cutoff, $min ) );
    $nonce           = wp_create_nonce( 'sre_nonce' );

    sre_page_header( 'Smart Reorder Emailer' );

    sre_page_intro( 'At-a-glance view of your reorder program — how much customer data has been imported, how many emails have gone out, and (Pro) how your customer base breaks down by segment. Use the action cards below to send the next batch.' );

    $sre_has_key     = ! empty( get_option( 'sre_openai_key', '' ) );
    $sre_has_scanned = $total_patterns > 0;
    $sre_has_sent    = $emails_total > 0;
    $sre_all_done    = $sre_has_scanned && $sre_has_sent;

    if ( ! $sre_all_done ) {
        echo '<div style="background:#fff;border-radius:10px;padding:0;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden">';

        $step_style_done   = 'background:#f0fdf4;border-left:4px solid #16a34a;';
        $step_style_active = 'background:#fff;border-left:4px solid #7c3aed;';
        $step_style_wait   = 'background:#f9fafb;border-left:4px solid #e5e7eb;opacity:.7;';
        $icon_done   = '<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#16a34a;color:#fff;font-size:14px;flex-shrink:0"><span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;margin-top:1px"></span></span>';
        $icon_num    = function( $n ) { return '<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#7c3aed;color:#fff;font-size:13px;font-weight:700;flex-shrink:0">' . $n . '</span>'; };
        $icon_wait   = function( $n ) { return '<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#e5e7eb;color:#9ca3af;font-size:13px;font-weight:700;flex-shrink:0">' . $n . '</span>'; };

        echo '<div style="padding:14px 20px;' . ( $sre_has_scanned ? $step_style_done : $step_style_active ) . 'display:flex;align-items:center;gap:14px">';
        echo $sre_has_scanned ? $icon_done : $icon_num( 1 );
        echo '<div style="flex:1"><strong style="font-size:14px">Scan Orders</strong><br><span style="font-size:12px;color:#6b7280">';
        echo $sre_has_scanned
            ? esc_html( number_format( $total_customers ) ) . ' customers &amp; ' . esc_html( number_format( $total_patterns ) ) . ' patterns found'
            : 'Click "Re-scan All Orders" below to import your WooCommerce order data and build customer purchase patterns.';
        echo '</span></div>';
        if ( ! $sre_has_scanned ) {
            echo '<button class="button button-primary button-small" id="sre-setup-scan" style="flex-shrink:0">Re-scan Now</button>';
        }
        echo '</div>';

        echo '<div style="padding:14px 20px;' . ( $sre_has_sent ? $step_style_done : ( $sre_has_scanned ? $step_style_active : $step_style_wait ) ) . 'display:flex;align-items:center;gap:14px">';
        echo $sre_has_sent ? $icon_done : ( $sre_has_scanned ? $icon_num( 2 ) : $icon_wait( 2 ) );
        echo '<div style="flex:1"><strong style="font-size:14px">Review &amp; Send Emails</strong><br><span style="font-size:12px;color:#6b7280">';
        echo $sre_has_sent
            ? esc_html( number_format( $emails_total ) ) . ' emails sent'
            : 'Review customers who are ready for reorder reminders and send your first batch of emails.';
        echo '</span></div>';
        if ( ! $sre_has_sent && $sre_has_scanned ) {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-send' ) ) . '" class="button button-primary button-small" style="flex-shrink:0">Review &amp; Send</a>';
        }
        echo '</div>';

        if ( sre_is_pro() && ! $sre_has_key ) {
            echo '<div style="padding:14px 20px;' . $step_style_active . 'display:flex;align-items:center;gap:14px">';
            echo $icon_num( 3 );
            echo '<div style="flex:1"><strong style="font-size:14px">Connect OpenAI <span style="font-size:10px;padding:2px 8px;border-radius:10px;background:#7c3aed;color:#fff;font-weight:700;vertical-align:middle">PRO</span></strong><br><span style="font-size:12px;color:#6b7280">Add your OpenAI API key in Settings to enable AI-powered email generation and customer segmentation.</span></div>';
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-settings' ) ) . '" class="button button-small" style="flex-shrink:0">Go to Settings</a>';
            echo '</div>';
        }

        echo '</div>';
    }

    sre_section( 'Activity', 'Customer base, recent sends, and how many are due for a reminder.' );
    echo '<div class="sre-stat-grid">';
    sre_stat_card(
        'Customers', $total_customers, '#2563eb',
        'Distinct customer email addresses imported from completed WooCommerce orders.'
    );
    sre_stat_card(
        'Patterns', $total_patterns, '#0891b2',
        'Each pattern is one (customer, product) pair the plugin has learned. Drives the "this customer reorders product X every N days" prediction.'
    );
    sre_stat_card(
        'Ready', $ready_count, '#dc2626',
        'Customers whose reorder window has opened (last order older than your "Days Between Orders" setting AND meets the minimum-orders threshold). These are eligible to receive a reorder email right now.'
    );
    sre_stat_card(
        'Sent Total', $emails_total, '#7c3aed',
        'Total reorder emails sent since you activated the plugin. Includes both AI-generated and standard-template sends.'
    );
    sre_stat_card(
        'This Week', $emails_week, '#d97706',
        'Reorder emails sent in the last 7 days. Use to see if your daily cron is running.'
    );
    echo '</div>';

    if ( sre_is_pro() ) {
        $seg_tbl = $wpdb->prefix . 'sre_segments';
        $vip_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$seg_tbl} WHERE segment='vip'" );
        $atrisk_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$seg_tbl} WHERE segment='at_risk'" );
        $disc_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sre_discounts" );

        sre_section( 'AI segmentation', 'How customers break down once segmented. Run AI Segmentation in Quick Actions to refresh.' );
        echo '<div class="sre-stat-grid">';
        sre_stat_card(
            'VIP Customers', $vip_count, '#7c3aed',
            'Customers with 10+ completed orders OR $1000+ lifetime revenue. They get the smallest discount because they already buy often — the email is more about staying top-of-mind.'
        );
        sre_stat_card(
            'At Risk', $atrisk_count, '#ef4444',
            'Customers whose days-since-last-order has crossed 90 days OR exceeded 2x their normal frequency. They are slipping toward churn — worth a stronger discount to win back.'
        );
        sre_stat_card(
            'Discounts', $disc_count, '#059669',
            'Total dynamic discount codes the plugin has issued. Each is single-use, expires after 30 days, and is restricted to the recipient\'s email address.'
        );
        echo '</div>';
    }

    echo '<div style="background:#fff;border-radius:8px;padding:22px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h2 style="margin:0 0 14px;font-size:16px">Quick Actions ' . sre_tip( 'Manual triggers for the same jobs that run automatically on the daily cron. Use after big imports or when testing.' ) . '</h2>';
    echo '<button class="button button-primary" id="sre-scan">Re-scan All Orders</button> ';
    echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-send' ) ) . '" class="button button-primary">Review &amp; Send Emails</a> ';
    echo '<button class="button" id="sre-test">Send Test Email</button>';
    if ( sre_is_pro() ) {
        echo ' <button class="button button-primary" id="sre-segment" style="background:#7c3aed;border-color:#7c3aed">Run AI Segmentation</button>';
    }
    echo '<div id="sre-msg" style="margin-top:14px"></div></div>';

    if ( sre_is_pro() ) {
        $seg_tbl = $wpdb->prefix . 'sre_segments';
        $segments = $wpdb->get_results( "SELECT segment, COUNT(*) as cnt FROM {$seg_tbl} GROUP BY segment ORDER BY cnt DESC" );
        if ( ! empty( $segments ) ) {
            echo '<div style="background:#fff;border-radius:8px;padding:22px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
            echo '<h2 style="margin:0 0 14px;font-size:16px">Customer Segments Overview</h2>';
            echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
            $total_seg = 0;
            foreach ( $segments as $s ) $total_seg += (int) $s->cnt;
            foreach ( $segments as $s ) {
                $pct = $total_seg > 0 ? round( ( $s->cnt / $total_seg ) * 100 ) : 0;
                echo '<div style="flex:1;min-width:130px;background:#f9fafb;border-radius:8px;padding:14px;text-align:center">';
                echo sre_segment_badge( $s->segment );
                echo '<p style="margin:8px 0 0;font-size:24px;font-weight:700">' . number_format( $s->cnt ) . '</p>';
                echo '<p style="margin:2px 0 0;font-size:12px;color:#6b7280">' . $pct . '%</p>';
                echo '</div>';
            }
            echo '</div></div>';
        }

        $dash_now    = date( 'Y-m-d H:i:s' );
        $dash_future = date( 'Y-m-d H:i:s', strtotime( '+7 days' ) );
        $upcoming = $wpdb->get_results( $wpdb->prepare(
            "SELECT customer_email, segment, predicted_next, days_since_last
             FROM {$seg_tbl}
             WHERE predicted_next IS NOT NULL AND predicted_next >= %s AND predicted_next <= %s
             ORDER BY predicted_next ASC LIMIT 5",
            $dash_now, $dash_future
        ) );
        if ( ! empty( $upcoming ) ) {
            echo '<div style="background:#fff;border-radius:8px;padding:22px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
            echo '<h2 style="margin:0 0 14px;font-size:16px">Predicted Reorders - Next 7 Days</h2>';
            echo '<table class="wp-list-table widefat" style="font-size:13px"><thead><tr style="background:#f9fafb"><th style="padding:10px">Customer</th><th>Segment</th><th>Predicted Date</th><th>Days Since Last</th></tr></thead><tbody>';
            foreach ( $upcoming as $u ) {
                echo '<tr><td style="padding:8px 10px">' . esc_html( $u->customer_email ) . '</td>';
                echo '<td>' . sre_segment_badge( $u->segment ) . '</td>';
                echo '<td>' . esc_html( wp_date( 'M j, Y', strtotime( $u->predicted_next ) ) ) . '</td>';
                echo '<td>' . (int) $u->days_since_last . ' days</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    if ( $total_patterns === 0 ) {
        echo '<div style="background:#fff;border-radius:8px;padding:28px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2>Click Re-scan to load your order data!</h2>';
        echo '</div>';
    } else {
        echo '<div style="background:#fff;border-radius:8px;padding:20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">';
        echo '<div><strong>' . number_format( $ready_count ) . ' customers</strong> ready for email (not ordered in ' . (int) $days . '+ days)</div>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-send' ) ) . '" class="button button-primary">Review &amp; Send</a>';
        echo '</div></div>';
    }

    if ( ! sre_is_pro() ) {
        echo '<div style="background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;padding:22px 26px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">';
        echo '<div><strong style="font-size:16px">Unlock Pro</strong><br><span style="opacity:.9;font-size:13px">AI emails, analytics, segments, discount codes, A/B tests</span></div>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ) . '" style="background:#f59e0b;color:#fff;padding:10px 20px;border-radius:6px;font-weight:700;font-size:14px;text-decoration:none">Enter Code</a>';
        echo '</div>';
    }

    echo '</div>';
    echo '<script>jQuery(function($){';
    echo 'var n="' . esc_js( $nonce ) . '";';
    echo 'function sre_do(a,t,b){var o=b.text();b.prop("disabled",true).text(t);$("#sre-msg").html("<p style=\"color:#6b7280\">Please wait...</p>");';
    echo '$.post(ajaxurl,{action:"sre_"+a,nonce:n},function(r){';
    echo 'var ok=r.success,bg=ok?"#dcfce7":"#fee2e2",c=ok?"#166534":"#991b1b";';
    echo '$("#sre-msg").html("<div style=\"padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;background:"+bg+";color:"+c+"\">"+(ok?"&#10003; ":"&#10007; ")+r.data+"</div>");';
    echo 'b.prop("disabled",false).text(o);';
    echo 'if(ok&&a!=="test_email"){setTimeout(function(){location.reload();},2500);}';
    echo '});}';
    echo '$("#sre-scan,#sre-setup-scan").on("click",function(){sre_do("analyze","Scanning...",$(this));});';
    echo '$("#sre-test").on("click",function(){sre_do("test_email","Sending...",$(this));});';
    echo '$("#sre-segment").on("click",function(){sre_do("run_segmentation","Analyzing...",$(this));});';
    echo '});</script>';
}

// ----------------------------------------------------------------
// PAGE: SEND EMAILS (preserved from v4.1)
// ----------------------------------------------------------------
function sre_page_send() {
    global $wpdb;
    $pt    = $wpdb->prefix . 'sre_patterns';
    $lt    = $wpdb->prefix . 'sre_email_log';
    $nonce = wp_create_nonce( 'sre_nonce' );

    $days     = max( 1, (int) get_option( 'sre_days', 30 ) );
    $min_def  = max( 1, (int) get_option( 'sre_min_orders', 1 ) );
    $cool     = max( 1, (int) get_option( 'sre_cooldown', 7 ) );
    $cool_cut = gmdate( 'Y-m-d H:i:s', strtotime( "-{$cool} days" ) );

    $f_days_min = isset( $_GET['days_min'] ) ? max( 1, (int) $_GET['days_min'] ) : $days;
    $f_days_max = isset( $_GET['days_max'] ) && (int) $_GET['days_max'] > 0 ? (int) $_GET['days_max'] : 0;
    $f_cat      = isset( $_GET['filter_cat'] ) && strlen( $_GET['filter_cat'] ) ? (int) $_GET['filter_cat'] : 0;
    $f_stock    = isset( $_GET['filter_stock'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_stock'] ) ) : 'instock';
    $f_orders   = isset( $_GET['filter_orders'] ) ? max( 1, (int) $_GET['filter_orders'] ) : $min_def;
    $f_sort     = isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( $_GET['sort'] ) ) : 'relevance';
    $cur_page   = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
    $per_page   = 50;

    $cutoff_min = gmdate( 'Y-m-d H:i:s', strtotime( "-{$f_days_min} days" ) );
    $where      = $wpdb->prepare( 'p.purchase_count >= %d AND p.last_order_date <= %s', $f_orders, $cutoff_min );
    if ( $f_days_max > 0 ) {
        $where .= $wpdb->prepare( ' AND p.last_order_date >= %s', gmdate( 'Y-m-d H:i:s', strtotime( "-{$f_days_max} days" ) ) );
    }
    if ( $f_cat > 0 ) {
        $where .= $wpdb->prepare( ' AND p.category_id = %d', $f_cat );
    }
    $where .= $wpdb->prepare( ' AND NOT EXISTS (SELECT 1 FROM ' . $lt . ' l WHERE l.customer_email=p.customer_email AND l.product_id=p.product_id AND l.sent_date >= %s)', $cool_cut );

    if ( $f_sort === 'days_desc' ) {
        $order_sql = 'p.last_order_date ASC';
    } elseif ( $f_sort === 'days_asc' ) {
        $order_sql = 'p.last_order_date DESC';
    } elseif ( $f_sort === 'purchases' ) {
        $order_sql = 'p.purchase_count DESC, p.last_order_date ASC';
    } else {
        $order_sql = 'p.purchase_count DESC, p.avg_days DESC, p.last_order_date ASC';
    }

    $all_rows    = $wpdb->get_results( "SELECT p.* FROM {$pt} p WHERE {$where} ORDER BY {$order_sql} LIMIT 2000" );
    $stock_cache = array();
    $filtered    = array();
    foreach ( $all_rows as $r ) {
        $pid = (int) $r->product_id;
        if ( ! isset( $stock_cache[ $pid ] ) ) {
            $p = wc_get_product( $pid );
            if ( ! $p ) {
                $stock_cache[ $pid ] = 'deleted';
            } elseif ( $p->is_in_stock() ) {
                $stock_cache[ $pid ] = 'instock';
            } else {
                $stock_cache[ $pid ] = 'outofstock';
            }
        }
        if ( $stock_cache[ $pid ] === 'deleted' ) {
            continue;
        }
        if ( $f_stock === 'instock' && $stock_cache[ $pid ] !== 'instock' ) {
            continue;
        }
        if ( $f_stock === 'outofstock' && $stock_cache[ $pid ] !== 'outofstock' ) {
            continue;
        }
        $r->stock_status = $stock_cache[ $pid ];
        $filtered[] = $r;
    }

    $total_f   = count( $filtered );
    $instock_n = 0;
    foreach ( $filtered as $r ) {
        if ( $r->stock_status === 'instock' ) {
            $instock_n++;
        }
    }
    $paged_rows = array_slice( $filtered, ( $cur_page - 1 ) * $per_page, $per_page );
    $cats       = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );

    sre_page_header( 'Send Emails' );
    sre_page_intro( 'Customers who are due for a reorder reminder right now. The list is filtered by your "Days Between Orders" and "Min Orders" settings — adjust those to widen or tighten the queue.' );
    echo '<p style="color:#6b7280;margin-bottom:16px">Out-of-stock products are blocked. Filter, select customers, then send.</p>';

    echo '<form method="get" style="background:#fff;border-radius:8px;padding:16px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<input type="hidden" name="page" value="smart-reorder-send">';
    echo '<div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">';

    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Days Since Order</label>';
    echo '<div style="display:flex;align-items:center;gap:6px">';
    echo '<input type="number" name="days_min" value="' . (int) $f_days_min . '" min="1" style="width:65px;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px"> to ';
    echo '<input type="number" name="days_max" value="' . ( $f_days_max > 0 ? (int) $f_days_max : '' ) . '" placeholder="any" min="0" style="width:65px;padding:6px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">';
    echo '</div></div>';

    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Category</label>';
    echo '<select name="filter_cat" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px"><option value="">All</option>';
    if ( ! is_wp_error( $cats ) ) {
        foreach ( $cats as $cat ) {
            echo '<option value="' . (int) $cat->term_id . '"' . selected( $f_cat, $cat->term_id, false ) . '>' . esc_html( $cat->name ) . '</option>';
        }
    }
    echo '</select></div>';

    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Stock</label>';
    echo '<select name="filter_stock" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">';
    echo '<option value="instock"' . selected( $f_stock, 'instock', false ) . '>In Stock Only</option>';
    echo '<option value="all"' . selected( $f_stock, 'all', false ) . '>All</option>';
    echo '<option value="outofstock"' . selected( $f_stock, 'outofstock', false ) . '>Out of Stock</option>';
    echo '</select></div>';

    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Min Orders</label>';
    echo '<input type="number" name="filter_orders" value="' . (int) $f_orders . '" min="1" style="width:64px;padding:7px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:13px"></div>';

    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Sort By</label>';
    echo '<select name="sort" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">';
    echo '<option value="relevance"' . selected( $f_sort, 'relevance', false ) . '>Most Relevant</option>';
    echo '<option value="days_desc"' . selected( $f_sort, 'days_desc', false ) . '>Longest Overdue</option>';
    echo '<option value="days_asc"'  . selected( $f_sort, 'days_asc',  false ) . '>Most Recent</option>';
    echo '<option value="purchases"' . selected( $f_sort, 'purchases', false ) . '>Most Purchases</option>';
    echo '</select></div>';

    echo '<div><button type="submit" class="button button-primary">Filter</button> ';
    echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-send' ) ) . '" class="button">Reset</a></div>';
    echo '</div></form>';

    echo '<div style="background:#fff;border-radius:8px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">';
    echo '<div style="font-size:14px"><strong>' . number_format( $total_f ) . '</strong> customers match &bull; ';
    echo '<span style="color:#16a34a;font-weight:600">' . number_format( $instock_n ) . ' in-stock</span>';
    if ( $total_f - $instock_n > 0 ) {
        echo ' &bull; <span style="color:#9ca3af">' . ( $total_f - $instock_n ) . ' blocked (out of stock)</span>';
    }
    echo '</div>';
    echo '<div style="display:flex;gap:8px">';
    echo '<button id="sre-send-sel" class="button button-primary" disabled>Send Selected (<span id="sel-cnt">0</span>)</button>';
    echo '<button id="sre-send-all" class="button button-primary">Send All In-Stock (' . number_format( $instock_n ) . ')</button>';
    echo '</div></div>';
    echo '<div id="sre-send-msg" style="margin-bottom:12px"></div>';

    if ( empty( $paged_rows ) ) {
        echo '<div style="background:#fff;border-radius:8px;padding:26px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.07)"><h3>No customers match these filters</h3></div>';
    } else {
        echo '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<table class="wp-list-table widefat" style="font-size:13px;margin:0;border-collapse:collapse">';
        echo '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
        echo '<th style="padding:10px 14px;width:32px"><input type="checkbox" id="chk-all"></th>';
        echo '<th style="padding:10px">Customer</th><th style="padding:10px">Product</th>';
        echo '<th style="padding:10px;width:110px">Category</th><th style="padding:10px;width:60px">Orders</th>';
        echo '<th style="padding:10px;width:75px">Avg Cycle</th><th style="padding:10px;width:90px">Last Order</th>';
        echo '<th style="padding:10px;width:70px">Days</th><th style="padding:10px;width:100px">Stock</th>';
        echo '<th style="padding:10px;width:160px">Actions</th>';
        echo '</tr></thead><tbody>';

        $ri = 0;
        foreach ( $paged_rows as $r ) {
            $product  = wc_get_product( $r->product_id );
            $pname    = $product ? $product->get_name() : '(#' . $r->product_id . ')';
            $cat      = $r->category_id ? get_term( (int) $r->category_id, 'product_cat' ) : null;
            $catname  = ( $cat && ! is_wp_error( $cat ) ) ? $cat->name : '&mdash;';
            $days_ago = (int) round( ( time() - strtotime( $r->last_order_date ) ) / 86400 );
            $instock  = isset( $r->stock_status ) && $r->stock_status === 'instock';
            $row_bg   = $ri % 2 === 0 ? '#fff' : '#f9fafb';
            $ri++;

            echo '<tr style="background:' . $row_bg . ';opacity:' . ( $instock ? '1' : '0.5' ) . ';border-bottom:1px solid #f3f4f6">';
            echo '<td style="padding:10px 14px">';
            if ( $instock ) {
                echo '<input type="checkbox" class="row-chk" data-email="' . esc_attr( $r->customer_email ) . '" data-product="' . (int) $r->product_id . '">';
            } else {
                echo '<input type="checkbox" disabled>';
            }
            echo '</td>';
            echo '<td style="padding:10px">' . esc_html( $r->customer_email ) . '</td>';
            echo '<td style="padding:10px"><strong>' . esc_html( $pname ) . '</strong></td>';
            echo '<td style="padding:10px">' . esc_html( $catname ) . '</td>';
            echo '<td style="padding:10px">' . (int) $r->purchase_count . 'x</td>';
            echo '<td style="padding:10px">' . ( $r->avg_days > 0 ? '~' . (int) $r->avg_days . 'd' : '&mdash;' ) . '</td>';
            echo '<td style="padding:10px">' . esc_html( wp_date( 'M j, Y', strtotime( $r->last_order_date ) ) ) . '</td>';
            echo '<td style="padding:10px;font-weight:' . ( $days_ago > 90 ? 700 : 400 ) . ';color:' . ( $days_ago > 90 ? '#dc2626' : '#374151' ) . '">' . (int) $days_ago . 'd</td>';
            if ( $instock ) {
                echo '<td style="padding:10px"><span style="color:#16a34a;font-weight:600;font-size:12px">&#10003; In Stock</span></td>';
                echo '<td style="padding:10px;white-space:nowrap">';
                echo '<button type="button" class="button button-small preview-one" data-email="' . esc_attr( $r->customer_email ) . '" data-product="' . (int) $r->product_id . '" data-mode="standard" style="margin-right:4px" title="Preview standard email">Preview</button>';
                if ( sre_is_pro() ) {
                    echo '<button type="button" class="button button-small preview-one" data-email="' . esc_attr( $r->customer_email ) . '" data-product="' . (int) $r->product_id . '" data-mode="ai" style="background:#7c3aed;border-color:#7c3aed;color:#fff" title="Preview AI-generated email">AI Email</button>';
                }
                echo '</td>';
            } else {
                echo '<td style="padding:10px"><span style="color:#dc2626;font-size:12px">Out of Stock</span></td>';
                echo '<td style="padding:10px"><span style="color:#9ca3af;font-size:11px">Blocked</span></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        $base = add_query_arg( array_filter( array(
            'page'          => 'smart-reorder-send',
            'days_min'      => $f_days_min,
            'days_max'      => $f_days_max > 0 ? $f_days_max : null,
            'filter_cat'    => $f_cat ? $f_cat : null,
            'filter_stock'  => $f_stock,
            'filter_orders' => $f_orders,
            'sort'          => $f_sort,
        ) ), admin_url( 'admin.php' ) );
        echo '<div style="margin-top:12px">' . paginate_links( array( 'base' => $base . '&paged=%#%', 'format' => '', 'current' => $cur_page, 'total' => ceil( $total_f / $per_page ) ) ) . '</div>';
    }
    echo '</div>';

    echo '<div id="sre-preview-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100000;overflow-y:auto;padding:30px 20px">';
    echo '<div style="max-width:680px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden">';
    echo '<div style="padding:18px 24px;background:#f9fafb;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center">';
    echo '<div><h3 style="margin:0;font-size:16px;font-weight:700">Email Preview</h3>';
    echo '<p id="sre-prev-info" style="margin:4px 0 0;font-size:12px;color:#6b7280"></p></div>';
    echo '<button id="sre-prev-close" style="background:none;border:none;font-size:22px;color:#9ca3af;cursor:pointer;padding:0;line-height:1">&times;</button>';
    echo '</div>';
    echo '<div style="padding:16px 24px;background:#f3f4f6">';
    echo '<p style="margin:0 0 6px;font-size:12px;font-weight:600;color:#6b7280">SUBJECT LINE:</p>';
    echo '<p id="sre-prev-subject" style="margin:0;font-size:14px;font-weight:700;color:#111827;padding:10px 14px;background:#fff;border-radius:6px;border:1px solid #e5e7eb"></p>';
    echo '</div>';
    echo '<div id="sre-prev-body" style="padding:0;max-height:500px;overflow-y:auto"></div>';
    echo '<div style="padding:16px 24px;background:#f9fafb;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end">';
    echo '<button id="sre-prev-cancel" class="button">Cancel</button>';
    echo '<button id="sre-prev-send" class="button button-primary" style="min-width:120px">Send This Email</button>';
    echo '</div>';
    echo '</div></div>';

    echo '<script>jQuery(function($){';
    echo 'var n="' . esc_js( $nonce ) . '";';
    echo 'var prevData={};';
    echo '$("#chk-all").on("change",function(){$(".row-chk").prop("checked",$(this).is(":checked"));upd();});';
    echo '$(document).on("change",".row-chk",upd);';
    echo 'function upd(){var c=$(".row-chk:checked").length;$("#sel-cnt").text(c);$("#sre-send-sel").prop("disabled",c===0);}';
    echo 'function msg(t,ok){var bg=ok?"#dcfce7":"#fee2e2",c=ok?"#166534":"#991b1b";$("#sre-send-msg").html("<div style=\"padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;background:"+bg+";color:"+c+"\">"+(ok?"&#10003; ":"&#10007; ")+t+"</div>");}';
    echo 'function do_send(data,txt,btn){var o=btn.text();btn.prop("disabled",true).text(txt);$.post(ajaxurl,data,function(r){msg(r.data,r.success);btn.prop("disabled",false).text(o);if(r.success){setTimeout(function(){location.reload();},2500);}});}';
    echo '$("#sre-send-sel").on("click",function(){';
    echo 'var items=[];$(".row-chk:checked").each(function(){items.push({email:$(this).data("email"),product:$(this).data("product")});});';
    echo 'do_send({action:"sre_send_selected",nonce:n,items:JSON.stringify(items)},"Sending...",$(this));});';
    echo '$("#sre-send-all").on("click",function(){';
    echo 'if(!confirm("Send to all ' . (int) $instock_n . ' in-stock customers?"))return;';
    echo 'do_send({action:"sre_send_now",nonce:n},"Sending...",$(this));});';

    echo '$(document).on("click",".preview-one",function(){';
    echo 'var b=$(this),email=b.data("email"),pid=b.data("product"),mode=b.data("mode");';
    echo 'var ot=b.text();b.prop("disabled",true).text("Loading...");';
    echo '$.post(ajaxurl,{action:"sre_preview_email",nonce:n,email:email,product_id:pid,mode:mode},function(r){';
    echo 'b.prop("disabled",false).text(ot);';
    echo 'if(!r.success){msg(r.data,false);return;}';
    echo 'prevData=r.data;';
    echo '$("#sre-prev-info").html("To: <strong>"+r.data.email+"</strong> &bull; Product: <strong>"+r.data.product+"</strong>"+(mode==="ai"?" &bull; <span style=\'color:#7c3aed;font-weight:700\'>AI Generated</span>":""));';
    echo '$("#sre-prev-subject").text(r.data.subject);';
    echo '$("#sre-prev-body").html("<iframe id=\'sre-prev-frame\' sandbox=\'allow-same-origin\' style=\'width:100%;border:none;min-height:400px\'></iframe>");';
    echo 'var fr=document.getElementById("sre-prev-frame");';
    echo 'fr.contentDocument.open();fr.contentDocument.write(r.data.html);fr.contentDocument.close();';
    echo 'setTimeout(function(){fr.style.height=fr.contentDocument.body.scrollHeight+"px";},200);';
    echo '$("#sre-preview-overlay").fadeIn(200);';
    echo '});});';

    echo '$("#sre-prev-close,#sre-prev-cancel").on("click",function(){$("#sre-preview-overlay").fadeOut(200);});';
    echo '$("#sre-preview-overlay").on("click",function(e){if(e.target===this)$(this).fadeOut(200);});';

    echo '$("#sre-prev-send").on("click",function(){';
    echo 'var b=$(this),ot=b.text();b.prop("disabled",true).text("Sending...");';
    echo '$.post(ajaxurl,{action:"sre_send_previewed",nonce:n,email:prevData.email,subject:prevData.subject,html:prevData.html,product_id:prevData.product_id},function(r){';
    echo 'b.prop("disabled",false).text(ot);';
    echo '$("#sre-preview-overlay").fadeOut(200);';
    echo 'msg(r.data,r.success);';
    echo 'if(r.success)setTimeout(function(){location.reload();},2500);';
    echo '});});';

    echo '});</script>';
}

// ----------------------------------------------------------------
// PAGE: PATTERNS (preserved from v4.1)
// ----------------------------------------------------------------
function sre_page_patterns() {
    global $wpdb;
    $pt    = $wpdb->prefix . 'sre_patterns';
    $days  = (int) get_option( 'sre_days', 30 );
    $nonce = wp_create_nonce( 'sre_nonce' );

    if ( isset( $_GET['export_csv'] ) && $_GET['export_csv'] === '1' && current_user_can( 'manage_woocommerce' ) ) {
        check_admin_referer( 'sre_export_patterns' );
        $all = $wpdb->get_results( "SELECT * FROM {$pt} ORDER BY last_order_date ASC" );
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="customer-patterns-' . date( 'Y-m-d' ) . '.csv"' );
        $fp = fopen( 'php://output', 'w' );
        fputcsv( $fp, array( 'Email', 'Product ID', 'Product Name', 'Category', 'Purchases', 'Avg Cycle (days)', 'Last Order', 'Days Since', 'Status' ) );
        foreach ( $all as $r ) {
            $product  = wc_get_product( $r->product_id );
            $pname    = $product ? $product->get_name() : '#' . $r->product_id;
            $cat      = $r->category_id ? get_term( (int) $r->category_id, 'product_cat' ) : null;
            $catname  = ( $cat && ! is_wp_error( $cat ) ) ? $cat->name : '';
            $days_ago = (int) round( ( time() - strtotime( $r->last_order_date ) ) / 86400 );
            fputcsv( $fp, array( $r->customer_email, $r->product_id, $pname, $catname, $r->purchase_count, (int) $r->avg_days, wp_date( 'Y-m-d', strtotime( $r->last_order_date ) ), $days_ago, $days_ago >= $days ? 'Ready' : 'Waiting' ) );
        }
        fclose( $fp );
        exit;
    }

    $f_cat     = isset( $_GET['filter_cat'] ) && strlen( $_GET['filter_cat'] ) ? (int) $_GET['filter_cat'] : 0;
    $f_ready   = isset( $_GET['filter_ready'] ) ? (int) $_GET['filter_ready'] : 0;
    $f_emailed = isset( $_GET['filter_emailed'] ) ? sanitize_text_field( $_GET['filter_emailed'] ) : '';
    $f_search  = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
    $per       = 50;
    $pg        = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

    $lt    = $wpdb->prefix . 'sre_email_log';
    $where = '1=1';
    if ( $f_cat > 0 ) {
        $where .= $wpdb->prepare( ' AND category_id=%d', $f_cat );
    }
    if ( $f_ready ) {
        $where .= $wpdb->prepare( ' AND last_order_date <= %s', gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) ) );
    }
    if ( $f_search ) {
        $where .= $wpdb->prepare( ' AND customer_email LIKE %s', '%' . $wpdb->esc_like( $f_search ) . '%' );
    }
    if ( $f_emailed === 'yes' ) {
        $where .= " AND customer_email IN (SELECT DISTINCT customer_email FROM {$lt})";
    } elseif ( $f_emailed === 'no' ) {
        $where .= " AND customer_email NOT IN (SELECT DISTINCT customer_email FROM {$lt})";
    }

    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pt} WHERE {$where}" );
    $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pt} WHERE {$where} ORDER BY last_order_date ASC LIMIT %d OFFSET %d", $per, ( $pg - 1 ) * $per ) );
    $cats  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );

    $cutoff_date   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
    $total_all     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pt}" );
    $total_ready   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pt} WHERE last_order_date <= %s", $cutoff_date ) );
    $unique_custs  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT customer_email) FROM {$pt}" );
    $avg_cycle_all = (float) $wpdb->get_var( "SELECT AVG(avg_days) FROM {$pt} WHERE avg_days > 0" );
    $top_product   = $wpdb->get_row( "SELECT product_id, SUM(purchase_count) as total FROM {$pt} GROUP BY product_id ORDER BY total DESC LIMIT 1" );
    $top_pname     = '';
    if ( $top_product ) {
        $tp = wc_get_product( (int) $top_product->product_id );
        $top_pname = $tp ? $tp->get_name() : '#' . $top_product->product_id;
    }

    sre_page_header( 'Customer Patterns' );

    sre_page_intro( 'Every (customer, product) pair the plugin has learned from your completed orders. The reorder schedule is computed from the gap between successive purchases — these patterns are what drive predictions and reorder emails.' );

    sre_section( 'Pattern overview' );
    echo '<div class="sre-stat-grid">';
    sre_stat_card(
        'Total Patterns', $total_all, '#2563eb',
        'One pattern per (customer, product) pair. A customer who has bought 3 different products produces 3 patterns.'
    );
    sre_stat_card(
        'Ready to Email', $total_ready, '#f59e0b',
        'Patterns whose customer\'s last order is older than the "Days Between Orders" setting AND whose purchase count meets the minimum-orders threshold. These rows are the email queue.'
    );
    sre_stat_card(
        'Unique Customers', $unique_custs, '#16a34a',
        'Distinct customer email addresses across all patterns — independent of how many products each one has bought.'
    );
    sre_stat_card(
        'Avg Reorder Cycle', '~' . (int) $avg_cycle_all . 'd', '#7c3aed',
        'Average days between successive orders, across every pattern. A useful sanity check for the global "Days Between Orders" setting.'
    );
    if ( $top_pname ) {
        sre_stat_card(
            'Top Reordered Product', esc_html( $top_pname ), '#ec4899',
            'Product with the highest summed purchase_count across all customers — the strongest reorder candidate in your catalog.'
        );
    }
    echo '</div>';

    echo '<form method="get" style="background:#fff;border-radius:8px;padding:14px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">';
    echo '<input type="hidden" name="page" value="smart-reorder-customers">';
    echo '<input type="hidden" name="tab" value="patterns">';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Search Email</label>';
    echo '<input type="text" name="search" value="' . esc_attr( $f_search ) . '" placeholder="email@example.com" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;width:200px"></div>';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Category</label>';
    echo '<select name="filter_cat" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px"><option value="">All</option>';
    if ( ! is_wp_error( $cats ) ) {
        foreach ( $cats as $cat ) {
            echo '<option value="' . (int) $cat->term_id . '"' . selected( $f_cat, $cat->term_id, false ) . '>' . esc_html( $cat->name ) . '</option>';
        }
    }
    echo '</select></div>';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Status</label>';
    echo '<select name="filter_ready" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">';
    echo '<option value="0"' . selected( $f_ready, 0, false ) . '>All</option>';
    echo '<option value="1"' . selected( $f_ready, 1, false ) . '>Ready Only</option>';
    echo '</select></div>';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Emailed</label>';
    echo '<select name="filter_emailed" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">';
    echo '<option value=""' . selected( $f_emailed, '', false ) . '>All</option>';
    echo '<option value="no"' . selected( $f_emailed, 'no', false ) . '>Not Emailed</option>';
    echo '<option value="yes"' . selected( $f_emailed, 'yes', false ) . '>Already Emailed</option>';
    echo '</select></div>';
    echo '<div><button type="submit" class="button button-primary">Filter</button> ';
    echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-customers&tab=patterns' ) ) . '" class="button">Reset</a></div>';
    echo '</form>';

    echo '<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">';
    if ( $total_ready > 0 ) {
        if ( sre_is_pro() ) {
            echo '<button class="button" id="sre-bulk-ready-ai" style="background:#7c3aed;border-color:#7c3aed;color:#fff">AI Email All Ready (' . $total_ready . ')</button>';
        } else {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ) . '" class="button" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);border-color:#7c3aed;color:#fff;display:inline-flex;align-items:center;gap:6px"><span style="font-size:14px">&#128274;</span> Upgrade to Pro to AI Email All Ready</a>';
        }
        echo '<button class="button" id="sre-bulk-ready-std">Email All Ready (' . $total_ready . ')</button>';
    }
    $export_url = wp_nonce_url( admin_url( 'admin.php?page=smart-reorder-customers&tab=patterns&export_csv=1' ), 'sre_export_patterns' );
    echo '<a href="' . esc_url( $export_url ) . '" class="button">Export All as CSV</a>';
    echo '</div>';

    echo '<p style="font-size:13px;color:#6b7280;margin:0 0 8px">' . number_format( $total ) . ' patterns match filters.</p>';

    echo '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<table class="wp-list-table widefat" style="font-size:13px;margin:0;border-collapse:collapse">';
    echo '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
    echo '<th style="padding:10px 14px">Customer Email</th><th style="padding:10px">Product</th>';
    echo '<th style="padding:10px;width:110px">Category</th><th style="padding:10px;width:65px">Purchases</th>';
    echo '<th style="padding:10px;width:80px">Avg Cycle</th><th style="padding:10px;width:90px">Last Order</th>';
    echo '<th style="padding:10px;width:85px">Days Since</th>';
    echo '<th style="padding:10px;width:140px;text-align:center">Actions</th>';
    echo '</tr></thead><tbody>';

    $row_emails = wp_list_pluck( $rows, 'customer_email' );
    $emailed_map = sre_get_emailed_map( $row_emails );

    if ( empty( $rows ) ) {
        echo '<tr><td colspan="8" style="padding:20px;text-align:center;color:#6b7280">No patterns found. Click Re-scan on the Dashboard.</td></tr>';
    } else {
        $ri = 0;
        foreach ( $rows as $r ) {
            $product  = wc_get_product( $r->product_id );
            $pname    = $product ? $product->get_name() : '(#' . $r->product_id . ')';
            $cat      = $r->category_id ? get_term( (int) $r->category_id, 'product_cat' ) : null;
            $catname  = ( $cat && ! is_wp_error( $cat ) ) ? $cat->name : '&mdash;';
            $days_ago = (int) round( ( time() - strtotime( $r->last_order_date ) ) / 86400 );
            $ready    = $days_ago >= $days;
            $row_bg   = $ready ? '#fffbeb' : ( $ri % 2 === 0 ? '#fff' : '#f9fafb' );
            $ri++;
            echo '<tr style="background:' . $row_bg . ';border-bottom:1px solid #f3f4f6">';
            echo '<td style="padding:10px 14px">' . esc_html( $r->customer_email ) . sre_emailed_badge( $r->customer_email, $emailed_map ) . '</td>';
            echo '<td style="padding:10px">' . esc_html( $pname ) . '</td>';
            echo '<td style="padding:10px">' . esc_html( $catname ) . '</td>';
            echo '<td style="padding:10px">' . (int) $r->purchase_count . 'x</td>';
            echo '<td style="padding:10px">' . ( $r->avg_days > 0 ? '~' . (int) $r->avg_days . 'd' : '&mdash;' ) . '</td>';
            echo '<td style="padding:10px">' . esc_html( wp_date( 'M j, Y', strtotime( $r->last_order_date ) ) ) . '</td>';
            echo '<td style="padding:10px;font-weight:' . ( $ready ? 700 : 400 ) . ';color:' . ( $ready ? '#b45309' : '#374151' ) . '">' . (int) $days_ago . 'd';
            if ( $ready ) {
                echo ' <span style="font-size:11px;background:#fef3c7;padding:1px 5px;border-radius:8px">READY</span>';
            }
            echo '</td>';
            echo '<td style="padding:10px;text-align:center;white-space:nowrap">';
            if ( sre_is_pro() ) {
                echo '<button class="button sre-pat-preview" data-email="' . esc_attr( $r->customer_email ) . '" data-pid="' . (int) $r->product_id . '" data-mode="ai" style="background:#7c3aed;border-color:#7c3aed;color:#fff;font-size:11px;padding:2px 8px;margin:0 2px">AI Email</button>';
            } else {
                echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ) . '" class="button" style="background:#7c3aed;border-color:#7c3aed;color:#fff;font-size:11px;padding:2px 8px;margin:0 2px">AI Email <span style="font-size:9px;padding:0 4px;border-radius:8px;background:rgba(255,255,255,.25);font-weight:700">PRO</span></a>';
            }
            echo '<button class="button sre-pat-preview" data-email="' . esc_attr( $r->customer_email ) . '" data-pid="' . (int) $r->product_id . '" data-mode="standard" style="font-size:11px;padding:2px 8px;margin:0 2px">Email</button>';
            echo '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';
    echo '<div style="margin-top:12px">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $pg, 'total' => ceil( $total / $per ) ) ) . '</div>';
    echo '<div id="sre-pat-msg" style="margin-top:14px"></div>';

    echo '<div id="sre-pat-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;overflow-y:auto">';
    echo '<div style="max-width:660px;margin:40px auto;background:#fff;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.2)">';
    echo '<div style="padding:18px 22px;border-bottom:1px solid #e5e7eb">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center"><h3 style="margin:0;font-size:16px">Email Preview</h3>';
    echo '<span id="sre-pat-info" style="font-size:12px;color:#6b7280"></span></div>';
    echo '<p style="margin:8px 0 0;font-size:13px;color:#374151"><strong>SUBJECT:</strong> <span id="sre-pat-subject"></span></p></div>';
    echo '<div id="sre-pat-body" style="padding:0;min-height:300px"></div>';
    echo '<div style="padding:14px 22px;border-top:1px solid #e5e7eb;text-align:right;display:flex;gap:8px;justify-content:flex-end">';
    echo '<button class="button" id="sre-pat-cancel">Cancel</button>';
    echo '<button class="button button-primary" id="sre-pat-send" style="background:#2563eb;border-color:#2563eb">Send This Email</button>';
    echo '</div></div></div>';

    echo '<script>jQuery(function($){';
    echo 'var n="' . esc_js( $nonce ) . '",prevData=null;';
    echo 'function pmsg(t,ok){var bg=ok?"#dcfce7":"#fee2e2",c=ok?"#166534":"#991b1b";';
    echo '$("#sre-pat-msg").html("<div style=\"padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;background:"+bg+";color:"+c+"\">"+t+"</div>");}';

    echo '$(".sre-pat-preview").on("click",function(){';
    echo 'var b=$(this),em=b.data("email"),pid=b.data("pid"),mode=b.data("mode"),o=b.text();';
    echo 'b.prop("disabled",true).text("Loading...");';
    echo '$.post(ajaxurl,{action:"sre_preview_email",nonce:n,email:em,product_id:pid,mode:mode},function(r){';
    echo 'b.prop("disabled",false).text(o);';
    echo 'if(!r.success){pmsg(r.data,false);return;}';
    echo 'prevData=r.data;';
    echo '$("#sre-pat-info").html("To: <strong>"+r.data.email+"</strong>"+(mode==="ai"?" &bull; <span style=\'color:#7c3aed;font-weight:700\'>AI Generated</span>":""));';
    echo '$("#sre-pat-subject").text(r.data.subject);';
    echo '$("#sre-pat-body").html("<iframe id=\'sre-pat-frame\' sandbox=\'allow-same-origin\' style=\'width:100%;border:none;min-height:400px\'></iframe>");';
    echo 'var fr=document.getElementById("sre-pat-frame");';
    echo 'fr.contentDocument.open();fr.contentDocument.write(r.data.html);fr.contentDocument.close();';
    echo 'setTimeout(function(){fr.style.height=fr.contentDocument.body.scrollHeight+"px";},200);';
    echo '$("#sre-pat-overlay").fadeIn(200);';
    echo '});});';

    echo '$("#sre-pat-cancel").on("click",function(){$("#sre-pat-overlay").fadeOut(200);});';

    echo '$("#sre-pat-send").on("click",function(){';
    echo 'if(!prevData)return;';
    echo 'var b=$(this),o=b.text();b.prop("disabled",true).text("Sending...");';
    echo '$.post(ajaxurl,{action:"sre_send_previewed",nonce:n,email:prevData.email,subject:prevData.subject,html:prevData.html,product_id:prevData.product_id},function(r){';
    echo 'b.prop("disabled",false).text(o);';
    echo '$("#sre-pat-overlay").fadeOut(200);';
    echo 'pmsg(r.data,r.success);';
    echo '});});';

    echo '$("#sre-bulk-ready-ai,#sre-bulk-ready-std").on("click",function(){';
    echo 'var b=$(this),md=$(this).attr("id")==="sre-bulk-ready-ai"?"ai":"standard",o=b.text();';
    echo 'if(!confirm("Send "+(md==="ai"?"AI":"standard")+" emails to all ' . $total_ready . ' ready customers?"))return;';
    echo 'b.prop("disabled",true).text("Sending...");';
    echo '$.post(ajaxurl,{action:"sre_bulk_ready_send",nonce:n,mode:md},function(r){';
    echo 'pmsg(r.data,r.success);b.prop("disabled",false).text(o);';
    echo '});});';

    echo '});</script>';
    echo '</div>';
}

// ----------------------------------------------------------------
// PAGE: EMAIL LOG
// ----------------------------------------------------------------
function sre_page_log() {
    global $wpdb;
    $lt    = $wpdb->prefix . 'sre_email_log';
    $per   = 50;
    $pg    = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt}" );
    $logs  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$lt} ORDER BY sent_date DESC LIMIT %d OFFSET %d", $per, ( $pg - 1 ) * $per ) );

    sre_page_header( 'Email Log' );
    echo '<p>' . number_format( $total ) . ' emails sent total.</p>';
    echo '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<table class="wp-list-table widefat" style="font-size:13px;margin:0;border-collapse:collapse">';
    echo '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
    echo '<th style="padding:10px 14px">Date</th><th style="padding:10px">Customer</th>';
    echo '<th style="padding:10px">Product</th><th style="padding:10px;width:100px">Type</th>';
    if ( sre_is_pro() ) {
        echo '<th style="padding:10px;width:80px">A/B Test</th>';
        echo '<th style="padding:10px;width:100px">Discount</th>';
    }
    echo '</tr></thead><tbody>';
    if ( empty( $logs ) ) {
        $colspan = sre_is_pro() ? 6 : 4;
        echo '<tr><td colspan="' . $colspan . '" style="padding:20px;text-align:center;color:#6b7280">No emails sent yet.</td></tr>';
    } else {
        $ri = 0;
        foreach ( $logs as $l ) {
            $p      = $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID=%d", (int) $l->product_id ) );
            $pname  = $p ? $p : 'Product #' . $l->product_id;
            $row_bg = $ri % 2 === 0 ? '#fff' : '#f9fafb';
            $ri++;
            echo '<tr style="background:' . $row_bg . ';border-bottom:1px solid #f3f4f6">';
            echo '<td style="padding:10px 14px">' . esc_html( wp_date( 'M j, Y g:i A', strtotime( $l->sent_date ) ) ) . '</td>';
            echo '<td style="padding:10px">' . esc_html( $l->customer_email ) . '</td>';
            echo '<td style="padding:10px">' . esc_html( $pname ) . '</td>';
            echo '<td style="padding:10px">' . ( $l->email_type === 'reorder' ? 'Reorder' : 'Suggest' ) . '</td>';
            if ( sre_is_pro() ) {
                echo '<td style="padding:10px">' . ( ! empty( $l->ab_variant ) ? 'Variant ' . esc_html( $l->ab_variant ) : '&mdash;' ) . '</td>';
                echo '<td style="padding:10px">' . ( ! empty( $l->discount_code ) ? '<code style="font-size:11px;background:#f3f4f6;padding:2px 6px;border-radius:3px">' . esc_html( $l->discount_code ) . '</code>' : '&mdash;' ) . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';
    echo '<div style="margin-top:12px">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $pg, 'total' => ceil( $total / $per ) ) ) . '</div>';
    echo '</div>';
}

// ----------------------------------------------------------------
// PAGE: SETTINGS (Enhanced for Pro)
// ----------------------------------------------------------------
function sre_page_settings() {
    if ( isset( $_GET['settings-updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }
    $cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    $excl = array_map( 'intval', (array) get_option( 'sre_exclude_cats', array() ) );

    sre_page_header( 'Settings' );
    echo '<form method="post" action="options.php">';
    settings_fields( 'sre_settings' );

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:960px">';

    echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h2 style="margin:0 0 14px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">General</h2>';
    echo '<table class="form-table" style="margin:0">';
    echo '<tr><th style="padding:8px 0;width:140px">Enable</th><td><label><input type="checkbox" name="sre_enabled" value="1"' . checked( get_option( 'sre_enabled' ), 1, false ) . '> Active</label></td></tr>';
    echo '<tr><th style="padding:8px 0">Reorder After</th><td><input type="number" name="sre_days" value="' . (int) get_option( 'sre_days', 30 ) . '" min="1" max="730" class="small-text"> days<p class="description">Email after this many days since last order.</p></td></tr>';
    echo '<tr><th style="padding:8px 0">Min Orders</th><td><input type="number" name="sre_min_orders" value="' . (int) get_option( 'sre_min_orders', 1 ) . '" min="1" max="20" class="small-text"><p class="description">Set to 1 for all customers.</p></td></tr>';
    echo '<tr><th style="padding:8px 0">Cooldown</th><td><input type="number" name="sre_cooldown" value="' . (int) get_option( 'sre_cooldown', 7 ) . '" min="1" max="365" class="small-text"> days<p class="description">Gap between emails per customer.</p></td></tr>';
    echo '<tr><th style="padding:8px 0">From Name</th><td><input type="text" name="sre_from_name" value="' . esc_attr( get_option( 'sre_from_name', '' ) ) . '" class="regular-text" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"><p class="description">Sender name shown in emails. Leave blank to use site name.</p></td></tr>';
    echo '<tr><th style="padding:8px 0">From Email</th><td><input type="email" name="sre_from_email" value="' . esc_attr( get_option( 'sre_from_email', '' ) ) . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"><p class="description">Sender email address. Leave blank to use admin email.</p></td></tr>';
    echo '</table></div>';

    echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h2 style="margin:0 0 14px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Email Subjects</h2>';
    echo '<table class="form-table" style="margin:0">';
    echo '<tr><th style="padding:8px 0;width:140px">Reorder</th><td><input type="text" name="sre_subject_reorder" value="' . esc_attr( get_option( 'sre_subject_reorder', 'Time to restock {product_name}!' ) ) . '" class="large-text"><p class="description"><code>{product_name}</code> <code>{customer_name}</code> <code>{days}</code></p></td></tr>';
    echo '<tr><th style="padding:8px 0">Suggestion</th><td><input type="text" name="sre_subject_suggest" value="' . esc_attr( get_option( 'sre_subject_suggest', 'New {category_name} products for you, {customer_name}!' ) ) . '" class="large-text"><p class="description"><code>{customer_name}</code> <code>{category_name}</code></p></td></tr>';
    echo '</table></div>';
    echo '</div>';

    if ( sre_is_pro() ) {
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:960px;margin-top:18px">';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 14px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">AI Settings <span style="font-size:11px;padding:2px 8px;border-radius:10px;background:#7c3aed;color:#fff">PRO</span></h2>';

        echo '<div style="background:#f0f6fc;border:1px solid #c3d3e0;border-radius:8px;padding:18px 20px;margin:0 0 16px;display:flex;gap:14px;align-items:flex-start;">';
        echo '<div style="font-size:28px;line-height:1;">&#129302;</div>';
        echo '<div>';
        echo '<h4 style="margin:0 0 6px;font-size:14px;">Supported AI Provider</h4>';
        echo '<p style="margin:0 0 10px;line-height:1.6;color:#374151;font-size:13px;">';
        echo 'Smart Reorder Emailer uses OpenAI for AI-powered email generation, customer segmentation, and predictive analytics. Your API key is stored securely in your WordPress database (AES-256 encrypted) and never shared with third parties. Usage costs are billed directly by OpenAI.';
        echo '</p>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
        echo '<span style="font-size:12px;background:#f0fdf4;color:#166534;padding:4px 10px;border-radius:6px;font-weight:600;">OpenAI (GPT-4o) &mdash; Recommended</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<table class="form-table" style="margin:0">';
        echo '<tr><th style="padding:8px 0;width:140px">Enable AI</th><td><label><input type="checkbox" name="sre_ai_enabled" value="1"' . checked( get_option( 'sre_ai_enabled' ), 1, false ) . '> Use AI for email generation</label></td></tr>';

        $stored_key = get_option( 'sre_openai_key', '' );
        $has_key = ! empty( $stored_key );
        echo '<tr><th style="padding:8px 0">OpenAI API Key</th><td>';
        if ( $has_key ) {
            $decrypted = sre_decrypt_key( $stored_key );
            $masked_hint = str_repeat( '&#8226;', 8 ) . esc_html( substr( $decrypted, -4 ) );
            echo '<div id="sre-key-status" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
            echo '<span style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;border:1px solid #bbf7d0;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;color:#166534;">';
            echo '<span style="width:8px;height:8px;background:#16a34a;border-radius:50%;display:inline-block;"></span>';
            echo 'Connected';
            echo '</span>';
            echo '<span style="font-family:monospace;font-size:13px;color:#475569;letter-spacing:1px;">' . $masked_hint . '</span>';
            echo '<button type="button" id="sre-change-key-btn" class="button button-small" style="font-size:12px;">Change Key</button>';
            echo '<button type="button" id="sre-remove-key-btn" class="button button-small" style="font-size:12px;color:#991b1b;">Remove Key</button>';
            echo '</div>';
            echo '<input type="hidden" name="sre_key_action" id="sre_key_action" value="">';
            echo '<div id="sre-key-input-wrap" style="display:none;margin-top:10px;">';
            echo '<input type="password" name="sre_openai_key" id="sre_openai_key" class="large-text" value="" placeholder="Paste new API key here" autocomplete="new-password">';
            echo '<p class="description" style="margin-top:4px;">Leave blank to keep the existing key. Keys are AES-256 encrypted before storage.</p>';
            echo '</div>';
            echo '<script>(function(){ document.getElementById("sre-change-key-btn").addEventListener("click", function(){ document.getElementById("sre-key-status").style.display = "none"; document.getElementById("sre-key-input-wrap").style.display = ""; document.getElementById("sre_openai_key").focus(); }); document.getElementById("sre-remove-key-btn").addEventListener("click", function(){ if(confirm("Remove the stored API key? AI features will be disabled.")){document.getElementById("sre_key_action").value="remove";this.closest("form").submit();} }); })();</script>';
        } else {
            echo '<div style="margin-bottom:8px;">';
            echo '<span style="display:inline-flex;align-items:center;gap:6px;background:#fef2f2;border:1px solid #fecaca;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;color:#991b1b;">';
            echo '<span style="width:8px;height:8px;background:#dc2626;border-radius:50%;display:inline-block;"></span>';
            echo 'Not connected';
            echo '</span>';
            echo '</div>';
            echo '<input type="password" name="sre_openai_key" id="sre_openai_key" class="large-text" value="" placeholder="Paste your API key here" autocomplete="new-password">';
        }
        echo '<p class="description" style="margin-top:6px;"><a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer" style="color:#059669;text-decoration:none;font-weight:500;">&rarr; Get OpenAI API Key</a></p>';
        echo '</td></tr>';

        echo '<tr><th style="padding:8px 0">AI Model</th><td><select name="sre_ai_model" style="min-width:220px;height:36px;font-size:13px;">';
        // 5.1.0: dropped gpt-3.5-turbo (deprecated, worse than 4o-mini at the
        // same price). Added gpt-4.1-mini and gpt-4.1 (current OpenAI lineup).
        $models = array(
            'gpt-4o-mini'   => 'GPT-4o Mini (Fast, Cheap — recommended)',
            'gpt-4o'        => 'GPT-4o (High Quality)',
            'gpt-4.1-mini'  => 'GPT-4.1 Mini (Newer, Cheaper)',
            'gpt-4.1'       => 'GPT-4.1 (Best Quality)',
            'gpt-4-turbo'   => 'GPT-4 Turbo (Legacy)',
        );
        foreach ( $models as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( get_option( 'sre_ai_model', 'gpt-4o-mini' ), $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th style="padding:8px 0">Email Tone</th><td><select name="sre_ai_tone" style="min-width:220px;height:36px;font-size:13px;">';
        $tones = array( 'friendly' => 'Friendly', 'professional' => 'Professional', 'casual' => 'Casual', 'urgent' => 'Urgent', 'humorous' => 'Humorous' );
        foreach ( $tones as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( get_option( 'sre_ai_tone', 'friendly' ), $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></td></tr>';
        echo '</table></div>';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 14px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Discount Amounts by Segment ' . '<span style="font-size:11px;padding:2px 8px;border-radius:10px;background:#7c3aed;color:#fff">PRO</span></h2>';
        echo '<table class="form-table" style="margin:0">';
        $seg_labels = array( 'vip' => 'VIP', 'loyal' => 'Loyal', 'atrisk' => 'At Risk', 'churned' => 'Churned', 'new' => 'New' );
        foreach ( $seg_labels as $key => $label ) {
            $opt = 'sre_discount_' . $key;
            echo '<tr><th style="padding:8px 0;width:140px">' . sre_segment_badge( str_replace( 'atrisk', 'at_risk', $key ) ) . '</th><td><input type="number" name="' . $opt . '" value="' . (int) get_option( $opt, 10 ) . '" min="0" max="100" class="small-text">%</td></tr>';
        }
        echo '</table></div>';

        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:960px;margin-top:18px">';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 14px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Post-Purchase Follow-ups <span style="font-size:11px;padding:2px 8px;border-radius:10px;background:#7c3aed;color:#fff">PRO</span></h2>';
        echo '<table class="form-table" style="margin:0">';
        echo '<tr><th style="padding:8px 0;width:140px">Enable</th><td><label><input type="checkbox" name="sre_followup_enabled" value="1"' . checked( get_option( 'sre_followup_enabled' ), 1, false ) . '> Send follow-up emails</label></td></tr>';
        echo '<tr><th style="padding:8px 0">Thank-you Delay</th><td><input type="number" name="sre_followup_delay" value="' . (int) get_option( 'sre_followup_delay', 7 ) . '" min="1" max="90" class="small-text"> days</td></tr>';
        echo '<tr><th style="padding:8px 0">Review Delay</th><td><input type="number" name="sre_review_delay" value="' . (int) get_option( 'sre_review_delay', 14 ) . '" min="1" max="90" class="small-text"> days</td></tr>';
        echo '<tr><th style="padding:8px 0">Thank-you Subject</th><td><input type="text" name="sre_followup_subject" value="' . esc_attr( get_option( 'sre_followup_subject', '' ) ) . '" class="large-text"><p class="description"><code>{customer_name}</code></p></td></tr>';
        echo '<tr><th style="padding:8px 0">Review Subject</th><td><input type="text" name="sre_review_subject" value="' . esc_attr( get_option( 'sre_review_subject', '' ) ) . '" class="large-text"><p class="description"><code>{customer_name}</code> <code>{product_name}</code></p></td></tr>';
        echo '</table></div>';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 14px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Loyalty Program <span style="font-size:11px;padding:2px 8px;border-radius:10px;background:#7c3aed;color:#fff">PRO</span></h2>';
        echo '<table class="form-table" style="margin:0">';
        echo '<tr><th style="padding:8px 0;width:140px">Enable</th><td><label><input type="checkbox" name="sre_loyalty_enabled" value="1"' . checked( get_option( 'sre_loyalty_enabled' ), 1, false ) . '> Enable loyalty tiers</label></td></tr>';
        echo '<tr><th style="padding:8px 0">Points / $</th><td><input type="number" name="sre_loyalty_points_per_dollar" value="' . (int) get_option( 'sre_loyalty_points_per_dollar', 1 ) . '" min="1" max="100" class="small-text"></td></tr>';
        echo '<tr><th style="padding:8px 0">Silver</th><td><input type="number" name="sre_loyalty_silver_threshold" value="' . (int) get_option( 'sre_loyalty_silver_threshold', 500 ) . '" min="1" class="small-text"> pts / <input type="number" name="sre_loyalty_silver_discount" value="' . (int) get_option( 'sre_loyalty_silver_discount', 5 ) . '" min="0" max="100" class="small-text">% off</td></tr>';
        echo '<tr><th style="padding:8px 0">Gold</th><td><input type="number" name="sre_loyalty_gold_threshold" value="' . (int) get_option( 'sre_loyalty_gold_threshold', 1500 ) . '" min="1" class="small-text"> pts / <input type="number" name="sre_loyalty_gold_discount" value="' . (int) get_option( 'sre_loyalty_gold_discount', 10 ) . '" min="0" max="100" class="small-text">% off</td></tr>';
        echo '<tr><th style="padding:8px 0">Platinum</th><td><input type="number" name="sre_loyalty_platinum_threshold" value="' . (int) get_option( 'sre_loyalty_platinum_threshold', 5000 ) . '" min="1" class="small-text"> pts / <input type="number" name="sre_loyalty_platinum_discount" value="' . (int) get_option( 'sre_loyalty_platinum_discount', 15 ) . '" min="0" max="100" class="small-text">% off</td></tr>';
        echo '</table></div>';

        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:1fr;gap:18px;max-width:960px;margin-top:18px">';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 14px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Churn Risk Alerts <span style="font-size:11px;padding:2px 8px;border-radius:10px;background:#7c3aed;color:#fff">PRO</span></h2>';
        echo '<table class="form-table" style="margin:0">';
        echo '<tr><th style="padding:8px 0;width:140px">Enable</th><td><label><input type="checkbox" name="sre_churn_alerts_enabled" value="1"' . checked( get_option( 'sre_churn_alerts_enabled' ), 1, false ) . '> Send churn alert digests</label></td></tr>';
        echo '<tr><th style="padding:8px 0">Alert Email</th><td><input type="email" name="sre_churn_alert_email" value="' . esc_attr( get_option( 'sre_churn_alert_email', '' ) ) . '" class="large-text" placeholder="Leave blank for admin email"></td></tr>';
        echo '<tr><th style="padding:8px 0">Risk Threshold</th><td><input type="number" name="sre_churn_alert_threshold" value="' . (int) get_option( 'sre_churn_alert_threshold', 70 ) . '" min="1" max="100" class="small-text">%<p class="description">Alert when risk score exceeds this.</p></td></tr>';
        echo '<tr><th style="padding:8px 0">Min Revenue</th><td>$<input type="number" name="sre_churn_alert_min_revenue" value="' . (int) get_option( 'sre_churn_alert_min_revenue', 100 ) . '" min="0" class="small-text"><p class="description">Only alert for customers with revenue above this.</p></td></tr>';
        echo '</table></div>';

        echo '</div>';
    } else {
        $sre_pro_license_url = admin_url( 'admin.php?page=smart-reorder-upgrade' );
        $sre_pro_badge = '<span style="font-size:11px;padding:2px 8px;border-radius:10px;background:#7c3aed;color:#fff;font-weight:700">PRO</span>';
        $sre_lock_msg = '<div style="margin-top:10px;padding:10px 14px;background:#f5f3ff;border-radius:6px;border:1px solid #ede9fe;font-size:13px;color:#5b21b6;display:flex;align-items:center;gap:8px"><span style="font-size:16px">&#128274;</span> <a href="' . esc_url( $sre_pro_license_url ) . '" style="color:#7c3aed;font-weight:600;text-decoration:none">Upgrade to Pro</a> to configure these settings.</div>';

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:960px;margin-top:18px">';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 8px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">AI Settings ' . $sre_pro_badge . '</h2>';
        echo '<p style="margin:0;font-size:13px;color:#6b7280">Enable AI-powered email generation with OpenAI. Choose your model and tone for personalized customer emails.</p>';
        echo $sre_lock_msg;
        echo '</div>';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 8px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Dynamic Discounts ' . $sre_pro_badge . '</h2>';
        echo '<p style="margin:0;font-size:13px;color:#6b7280">Configure automatic discount amounts per customer segment (VIP, Loyal, At Risk, Churned, New).</p>';
        echo $sre_lock_msg;
        echo '</div>';

        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;max-width:960px;margin-top:18px">';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 8px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Post-Purchase Follow-ups ' . $sre_pro_badge . '</h2>';
        echo '<p style="margin:0;font-size:13px;color:#6b7280">Automatically send thank-you and review request emails after purchases with configurable delays.</p>';
        echo $sre_lock_msg;
        echo '</div>';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 8px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Loyalty Program ' . $sre_pro_badge . '</h2>';
        echo '<p style="margin:0;font-size:13px;color:#6b7280">Reward repeat customers with tiered loyalty points, automatic tier upgrades, and exclusive discounts.</p>';
        echo $sre_lock_msg;
        echo '</div>';

        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:1fr;gap:18px;max-width:960px;margin-top:18px">';

        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h2 style="margin:0 0 8px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Churn Risk Alerts ' . $sre_pro_badge . '</h2>';
        echo '<p style="margin:0;font-size:13px;color:#6b7280">Get notified when high-value customers show signs of churning. Set risk thresholds and minimum revenue filters.</p>';
        echo $sre_lock_msg;
        echo '</div>';

        echo '</div>';
    }

    echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-top:18px;max-width:960px">';
    echo '<h2 style="margin:0 0 10px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">Exclude Categories</h2>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px">';
    if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
        foreach ( $cats as $cat ) {
            $chk = in_array( $cat->term_id, $excl, true ) ? ' checked' : '';
            echo '<label style="display:flex;align-items:center;gap:6px;font-size:13px;padding:6px 10px;background:#f9fafb;border-radius:4px">';
            echo '<input type="checkbox" name="sre_exclude_cats[]" value="' . (int) $cat->term_id . '"' . $chk . '> ' . esc_html( $cat->name ) . '</label>';
        }
    }
    echo '</div></div>';

    echo '<div style="max-width:960px;margin-top:18px">';
    echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h2 style="margin:0 0 14px;font-size:15px;border-bottom:1px solid #e5e7eb;padding-bottom:10px">License</h2>';
    echo '<table class="form-table" style="margin:0">';
    $lic_key = get_option( 'sre_license_key', '' );
    $lic_status = sre_is_pro() ? '<span style="color:#059669;font-weight:600">Pro Active</span>' : '<span style="color:#6b7280">Free</span>';
    echo '<tr><th style="padding:8px 0;width:140px">Status</th><td>' . $lic_status . '</td></tr>';
    if ( $lic_key ) {
        echo '<tr><th style="padding:8px 0">Key</th><td><code style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-size:13px">' . esc_html( $lic_key ) . '</code></td></tr>';
    }
    echo '<tr><th style="padding:8px 0">Server</th><td><code style="background:#f3f4f6;padding:2px 8px;border-radius:4px;font-size:13px">cao-tech.com</code></td></tr>';
    echo '<tr><th style="padding:8px 0">Manage</th><td><a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ) . '" class="button">' . ( sre_is_pro() ? 'Manage License' : 'Upgrade to Pro' ) . '</a></td></tr>';
    echo '</table></div>';
    echo '</div>';

    echo '<p style="margin-top:18px">';
    submit_button( 'Save Settings', 'primary', '', false );
    echo '</p></form></div>';
}

function sre_get_emailed_map( $emails ) {
    if ( empty( $emails ) ) return array();
    global $wpdb;
    $lt = $wpdb->prefix . 'sre_email_log';
    $placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT customer_email, MAX(sent_date) as last_sent, COUNT(*) as send_count FROM {$lt} WHERE customer_email IN ({$placeholders}) GROUP BY customer_email",
            ...$emails
        )
    );
    $map = array();
    foreach ( $results as $row ) {
        $map[ $row->customer_email ] = array(
            'last_sent'  => $row->last_sent,
            'send_count' => (int) $row->send_count,
        );
    }
    return $map;
}

function sre_emailed_badge( $email, $emailed_map ) {
    if ( ! isset( $emailed_map[ $email ] ) ) return '';
    $info = $emailed_map[ $email ];
    $ago  = human_time_diff( strtotime( $info['last_sent'] ), time() );
    $tip  = esc_attr( 'Emailed ' . (int) $info['send_count'] . 'x - last ' . $ago . ' ago' );
    return ' <span title="' . $tip . '" style="display:inline-block;font-size:10px;background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:8px;font-weight:600;vertical-align:middle;cursor:help">EMAILED</span>';
}

function sre_inline_customer_detail_js() {
    echo '$(".sre-cust-row").on("click",function(e){';
    echo 'if($(e.target).closest(".sre-pred-send,.button").length&&!$(e.target).closest(".sre-cust-link").length)return;';
    echo 'e.preventDefault();';
    echo 'var row=$(this),em=row.data("email"),dr=$(".sre-detail-row[data-email=\'"+em+"\']"),td=dr.find("td");';
    echo 'if(dr.is(":visible")){dr.hide();return;}';
    echo '$(".sre-detail-row").hide();';
    echo 'td.html("<div style=\"padding:16px 20px;background:#f0f9ff;border-top:2px solid #3b82f6\"><p style=\"color:#6b7280;font-size:13px\">Loading customer details...</p></div>");';
    echo 'dr.show();';
    echo '$.post(ajaxurl,{action:"sre_customer_detail",nonce:n,email:em},function(r){';
    echo 'if(!r.success){td.html("<div style=\"padding:16px 20px;background:#fef2f2\"><p style=\"color:#991b1b\">"+r.data+"</p></div>");return;}';
    echo 'var d=r.data,h="";';
    echo 'h+="<div style=\"padding:18px 22px;background:#f0f9ff;border-top:2px solid #3b82f6\">";';
    echo 'h+="<div style=\"display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:14px\">";';
    echo 'h+="<div style=\"flex:1;min-width:220px\">";';
    echo 'h+="<h3 style=\"margin:0 0 4px;font-size:16px;color:#111827\">"+(d.name||"Guest Customer")+"</h3>";';
    echo 'h+="<p style=\"margin:0 0 8px;color:#6b7280;font-size:13px\">"+d.email+"</p>";';
    echo 'var loc="";';
    echo 'if(d.city)loc+=d.city;';
    echo 'if(d.state)loc+=(loc?", ":"")+d.state;';
    echo 'if(d.postcode)loc+=" "+d.postcode;';
    echo 'if(d.country)loc+=(loc?", ":"")+d.country;';
    echo 'if(loc)h+="<p style=\"margin:0 0 4px;font-size:13px;color:#374151\"><strong>Location:</strong> "+loc+"</p>";';
    echo 'if(d.phone)h+="<p style=\"margin:0 0 4px;font-size:13px;color:#374151\"><strong>Phone:</strong> "+d.phone+"</p>";';
    echo 'if(d.username)h+="<p style=\"margin:0 0 4px;font-size:13px;color:#374151\"><strong>Username:</strong> "+d.username+"</p>";';
    echo 'if(d.sign_up)h+="<p style=\"margin:0 0 4px;font-size:13px;color:#374151\"><strong>Registered:</strong> "+d.sign_up+"</p>";';
    echo 'if(d.last_active)h+="<p style=\"margin:0 0 4px;font-size:13px;color:#374151\"><strong>Last Active:</strong> "+d.last_active+"</p>";';
    echo 'h+="</div>";';
    echo 'h+="<div style=\"flex:1;min-width:200px\">";';
    echo 'h+="<div style=\"display:grid;grid-template-columns:1fr 1fr;gap:8px\">";';
    echo 'h+="<div style=\"background:#fff;padding:10px 14px;border-radius:6px;border:1px solid #e5e7eb\"><div style=\"font-size:11px;color:#6b7280;text-transform:uppercase\">Total Orders</div><div style=\"font-size:18px;font-weight:700;color:#111827\">"+d.orders+"</div></div>";';
    echo 'h+="<div style=\"background:#fff;padding:10px 14px;border-radius:6px;border:1px solid #e5e7eb\"><div style=\"font-size:11px;color:#6b7280;text-transform:uppercase\">Total Spend</div><div style=\"font-size:18px;font-weight:700;color:#111827\">$"+d.total_spend+"</div></div>";';
    echo 'h+="<div style=\"background:#fff;padding:10px 14px;border-radius:6px;border:1px solid #e5e7eb\"><div style=\"font-size:11px;color:#6b7280;text-transform:uppercase\">Avg Order</div><div style=\"font-size:18px;font-weight:700;color:#111827\">$"+d.aov+"</div></div>";';
    echo 'if(d.ltv)h+="<div style=\"background:#fff;padding:10px 14px;border-radius:6px;border:1px solid #e5e7eb\"><div style=\"font-size:11px;color:#6b7280;text-transform:uppercase\">LTV</div><div style=\"font-size:18px;font-weight:700;color:#111827\">$"+d.ltv+"</div></div>";';
    echo 'h+="</div></div>";';
    echo 'h+="<div style=\"display:flex;gap:8px;align-items:center\">";';
    echo 'if(d.edit_url)h+="<a href=\""+d.edit_url+"\" class=\"button button-primary\" target=\"_blank\">View Profile</a>";';
    echo 'h+="<a href=\""+d.orders_url+"\" class=\"button\" target=\"_blank\">View Orders</a>";';
    echo 'h+="</div>";';
    echo 'h+="</div>";';
    echo 'if(d.recent_orders&&d.recent_orders.length){';
    echo 'h+="<h4 style=\"margin:14px 0 8px;font-size:13px;font-weight:600;color:#374151\">Recent Orders (last "+d.recent_orders.length+")</h4>";';
    echo 'h+="<table style=\"width:100%;font-size:12px;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden\">";';
    echo 'h+="<thead><tr style=\"background:#f3f4f6\"><th style=\"padding:6px 10px;text-align:left\">Order</th><th style=\"padding:6px 10px;text-align:left\">Date</th><th style=\"padding:6px 10px;text-align:left\">Items</th><th style=\"padding:6px 10px;text-align:right\">Total</th><th style=\"padding:6px 10px\">Status</th></tr></thead><tbody>";';
    echo 'for(var i=0;i<d.recent_orders.length;i++){var o=d.recent_orders[i];';
    echo 'h+="<tr style=\"border-top:1px solid #e5e7eb\"><td style=\"padding:6px 10px\"><a href=\""+o.url+"\" target=\"_blank\" style=\"color:#2563eb\">#"+o.id+"</a></td><td style=\"padding:6px 10px\">"+o.date+"</td><td style=\"padding:6px 10px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap\">"+o.items+"</td><td style=\"padding:6px 10px;text-align:right\">$"+o.total+"</td><td style=\"padding:6px 10px\">"+o.status+"</td></tr>";}';
    echo 'h+="</tbody></table>";}';
    echo 'h+="</div>";';
    echo 'td.html(h);';
    echo '});});';
}

// ----------------------------------------------------------------
// PRO PAGE: AI SEGMENTS
// ----------------------------------------------------------------
function sre_page_segments() {
    if ( ! sre_is_pro() ) { wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ); exit; }
    global $wpdb;
    $seg_tbl = $wpdb->prefix . 'sre_segments';
    $nonce   = wp_create_nonce( 'sre_nonce' );

    $f_seg     = isset( $_GET['segment'] ) ? sanitize_text_field( $_GET['segment'] ) : '';
    $f_search  = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
    $f_emailed = isset( $_GET['filter_emailed'] ) ? sanitize_text_field( $_GET['filter_emailed'] ) : '';
    $per       = 50;
    $pg        = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

    $lt_tbl = $wpdb->prefix . 'sre_email_log';
    $where  = '1=1';
    if ( $f_seg ) $where .= $wpdb->prepare( ' AND segment=%s', $f_seg );
    if ( $f_search ) $where .= $wpdb->prepare( ' AND customer_email LIKE %s', '%' . $wpdb->esc_like( $f_search ) . '%' );
    if ( $f_emailed === 'yes' ) {
        $where .= " AND customer_email IN (SELECT DISTINCT customer_email FROM {$lt_tbl})";
    } elseif ( $f_emailed === 'no' ) {
        $where .= " AND customer_email NOT IN (SELECT DISTINCT customer_email FROM {$lt_tbl})";
    }

    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$seg_tbl} WHERE {$where}" );
    $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$seg_tbl} WHERE {$where} ORDER BY risk_score DESC, total_revenue DESC LIMIT %d OFFSET %d", $per, ( $pg - 1 ) * $per ) );

    $counts = $wpdb->get_results( "SELECT segment, COUNT(*) as cnt FROM {$seg_tbl} GROUP BY segment ORDER BY cnt DESC" );

    sre_page_header( 'AI Customer Segments' );

    sre_page_intro( 'Every customer is sorted into one of five segments based on their order frequency, recency, and lifetime value. Segments drive the discount amount, email tone, and which campaigns target them.' );

    sre_explainer(
        'How segments are assigned',
        '<strong>VIP</strong> = 10+ orders OR $1000+ lifetime revenue. Smallest discount; goal is to stay top-of-mind.<br>'
        . '<strong>Loyal</strong> = 3+ orders, recent. Repeat buyers worth nurturing.<br>'
        . '<strong>At Risk</strong> = days-since-last-order > 90, or > 2× their normal frequency. Slipping toward churn — stronger discount.<br>'
        . '<strong>Churned</strong> = days-since-last-order > 180, or > 3× frequency. Win-back territory.<br>'
        . '<strong>New</strong> = 1 order or under 90 days old. Welcome flow.<br><br>'
        . 'Re-runs every day at the cron interval, OR when you click "Re-run Segmentation" below. A churned customer who places a new order gets re-classified <em>immediately</em> via the order-completion hook (5.1.0+).'
    );

    sre_section( 'Filter by segment', 'Click a badge to narrow the table below.' );
    echo '<div class="sre-toolbar">';
    echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-customers&tab=segments' ) ) . '" class="button' . ( ! $f_seg ? ' button-primary' : '' ) . '">All</a>';
    foreach ( $counts as $c ) {
        $active = $f_seg === $c->segment ? ' button-primary' : '';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-customers&tab=segments&segment=' . $c->segment ) ) . '" class="button' . $active . '">' . sre_segment_badge( $c->segment ) . ' ' . number_format( $c->cnt ) . '</a>';
    }
    echo '<span class="sre-toolbar-divider"></span>';
    echo '<button class="button" id="sre-reseg" style="background:#7c3aed;border-color:#7c3aed;color:#fff">Re-run Segmentation</button>';
    echo sre_tip( 'Recalculates segment, risk score, predicted next order, and lifetime value for every customer using the latest pattern data. Takes ~5–30 seconds depending on customer count.' );
    echo '</div>';

    echo '<form method="get" style="background:#fff;border-radius:8px;padding:12px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">';
    echo '<input type="hidden" name="page" value="smart-reorder-customers">';
    echo '<input type="hidden" name="tab" value="segments">';
    if ( $f_seg ) echo '<input type="hidden" name="segment" value="' . esc_attr( $f_seg ) . '">';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Search Email</label>';
    echo '<input type="text" name="search" value="' . esc_attr( $f_search ) . '" placeholder="email@..." style="padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;width:220px"></div>';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Emailed</label>';
    echo '<select name="filter_emailed" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">';
    echo '<option value=""' . selected( $f_emailed, '', false ) . '>All</option>';
    echo '<option value="no"' . selected( $f_emailed, 'no', false ) . '>Not Emailed</option>';
    echo '<option value="yes"' . selected( $f_emailed, 'yes', false ) . '>Already Emailed</option>';
    echo '</select></div>';
    echo '<button type="submit" class="button button-primary">Search</button>';
    echo '</form>';

    echo '<p style="color:#6b7280;font-size:13px;margin-bottom:10px">' . number_format( $total ) . ' customers' . ( $f_seg ? ' in ' . ucfirst( str_replace( '_', ' ', $f_seg ) ) . ' segment' : '' ) . '</p>';

    echo '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<table class="wp-list-table widefat" style="font-size:13px;margin:0;border-collapse:collapse">';
    echo '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
    echo '<th style="padding:10px 14px">Customer</th><th style="padding:10px;width:80px">Segment</th>';
    echo '<th style="padding:10px;width:60px">Orders</th><th style="padding:10px;width:90px">Revenue</th>';
    echo '<th style="padding:10px;width:70px">AOV</th><th style="padding:10px;width:80px">Frequency</th>';
    echo '<th style="padding:10px;width:80px">Days Since</th><th style="padding:10px;width:70px">Risk</th>';
    echo '<th style="padding:10px;width:90px">LTV</th><th style="padding:10px;width:100px">Next Order</th>';
    echo '<th style="padding:10px;width:200px">Recommended Action</th>';
    echo '</tr></thead><tbody>';

    $row_emails  = wp_list_pluck( $rows, 'customer_email' );
    $emailed_map = sre_get_emailed_map( $row_emails );

    if ( empty( $rows ) ) {
        echo '<tr><td colspan="11" style="padding:20px;text-align:center;color:#6b7280">No segments found. Click "Re-run Segmentation" or run Re-scan first.</td></tr>';
    } else {
        $ri = 0;
        foreach ( $rows as $r ) {
            $row_bg = $ri % 2 === 0 ? '#fff' : '#f9fafb';
            $ri++;
            $action = sre_segment_action( $r->segment, (int) $r->days_since_last, (int) $r->total_orders, $r->risk_score );
            echo '<tr class="sre-cust-row" data-email="' . esc_attr( $r->customer_email ) . '" style="background:' . $row_bg . ';border-bottom:1px solid #f3f4f6;cursor:pointer" title="Click to view customer details">';
            echo '<td style="padding:10px 14px"><a href="#" class="sre-cust-link" style="color:#2563eb;text-decoration:none;font-weight:500">' . esc_html( $r->customer_email ) . '</a>' . sre_emailed_badge( $r->customer_email, $emailed_map ) . '</td>';
            echo '<td style="padding:10px">' . sre_segment_badge( $r->segment ) . '</td>';
            echo '<td style="padding:10px">' . (int) $r->total_orders . '</td>';
            echo '<td style="padding:10px">' . sre_money( $r->total_revenue, 2 ) . '</td>';
            echo '<td style="padding:10px">' . sre_money( $r->avg_order_value, 2 ) . '</td>';
            echo '<td style="padding:10px">' . ( $r->order_frequency > 0 ? '~' . (int) $r->order_frequency . 'd' : '&mdash;' ) . '</td>';
            echo '<td style="padding:10px">' . (int) $r->days_since_last . 'd</td>';
            echo '<td style="padding:10px">' . sre_risk_bar( $r->risk_score ) . '</td>';
            echo '<td style="padding:10px;font-weight:600">' . sre_money( $r->lifetime_value, 0 ) . '</td>';
            echo '<td style="padding:10px">' . ( $r->predicted_next ? esc_html( wp_date( 'M j', strtotime( $r->predicted_next ) ) ) : '&mdash;' ) . '</td>';
            echo '<td style="padding:10px">' . $action . '</td>';
            echo '</tr>';
            echo '<tr class="sre-detail-row" data-email="' . esc_attr( $r->customer_email ) . '" style="display:none"><td colspan="11" style="padding:0"></td></tr>';
        }
    }
    echo '</tbody></table></div>';
    echo '<div style="margin-top:12px">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $pg, 'total' => ceil( $total / $per ) ) ) . '</div>';
    echo '<div id="sre-seg-msg" style="margin-top:14px"></div>';
    echo '</div>';

    echo '<script>jQuery(function($){';
    echo 'var n="' . esc_js( $nonce ) . '";';
    echo '$("#sre-reseg").on("click",function(){';
    echo 'var b=$(this),o=b.text();b.prop("disabled",true).text("Analyzing...");';
    echo '$.post(ajaxurl,{action:"sre_run_segmentation",nonce:n},function(r){';
    echo 'var ok=r.success,bg=ok?"#dcfce7":"#fee2e2",c=ok?"#166534":"#991b1b";';
    echo '$("#sre-seg-msg").html("<div style=\"padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;background:"+bg+";color:"+c+"\">"+r.data+"</div>");';
    echo 'b.prop("disabled",false).text(o);if(ok)setTimeout(function(){location.reload();},2000);';
    echo '});});';

    sre_inline_customer_detail_js();

    echo '});</script>';
}

// ----------------------------------------------------------------
// PRO PAGE: PREDICTIONS
// ----------------------------------------------------------------
function sre_page_predictions() {
    if ( ! sre_is_pro() ) { wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ); exit; }
    global $wpdb;
    $seg_tbl = $wpdb->prefix . 'sre_segments';
    $nonce   = wp_create_nonce( 'sre_nonce' );

    $f_window  = isset( $_GET['window'] ) ? (int) $_GET['window'] : 7;
    $f_emailed = isset( $_GET['filter_emailed'] ) ? sanitize_text_field( $_GET['filter_emailed'] ) : '';
    $per       = 50;
    $pg        = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

    $lt_tbl = $wpdb->prefix . 'sre_email_log';
    $now    = date( 'Y-m-d H:i:s' );
    $future = date( 'Y-m-d H:i:s', strtotime( "+{$f_window} days" ) );

    $where = $wpdb->prepare( "predicted_next IS NOT NULL AND predicted_next >= %s AND predicted_next <= %s", $now, $future );
    if ( $f_emailed === 'yes' ) {
        $where .= " AND customer_email IN (SELECT DISTINCT customer_email FROM {$lt_tbl})";
    } elseif ( $f_emailed === 'no' ) {
        $where .= " AND customer_email NOT IN (SELECT DISTINCT customer_email FROM {$lt_tbl})";
    }
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$seg_tbl} WHERE {$where}" );
    $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$seg_tbl} WHERE {$where} ORDER BY predicted_next ASC LIMIT %d OFFSET %d", $per, ( $pg - 1 ) * $per ) );

    $overdue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$seg_tbl} WHERE predicted_next IS NOT NULL AND predicted_next < %s AND predicted_next >= %s AND segment NOT IN ('churned')", $now, date( 'Y-m-d H:i:s', strtotime( '-30 days' ) ) ) );

    sre_page_header( 'Predicted Reorders' );

    sre_page_intro( 'Customers whose buying pattern says they are about to reorder. Use this to send reminders BEFORE they run out — that is when reorder emails convert best.' );

    sre_explainer(
        'How predictions work',
        'For every customer, the plugin looks at the gap between past completed orders to learn how often they buy (their <strong>frequency</strong>, e.g. ~30 days between orders). It then projects the next likely order date forward from their <strong>last order</strong>. A customer with 3+ orders gets a confident prediction; brand-new buyers do not appear here until they place a second order. Predictions only update after segmentation runs (daily cron, or click "Refresh Predictions" below).<br><br><strong>How to use this page:</strong> the table is sorted by predicted date — the top rows are the most urgent. Filter to "Not Emailed" to focus on customers who have not yet been contacted, then click "AI Email" or "Email" on each row to send a reminder.'
    );

    sre_section( 'At a glance', 'Counts for the current filter window.' );
    echo '<div class="sre-stat-grid">';
    sre_stat_card(
        'Upcoming (' . $f_window . ' days)',
        $total,
        '#2563eb',
        'Customers whose predicted next order falls within the next ' . $f_window . ' days. These are the ones most likely to convert from a timely reminder.'
    );
    sre_stat_card(
        'Overdue',
        $overdue,
        '#dc2626',
        'Customers whose predicted reorder date has already passed (in the last 30 days) but who have not yet placed a new order. They are at risk of churning — worth a reminder with a small incentive.'
    );
    echo '</div>';

    sre_section( 'Filters & actions', 'Pick a time window, narrow by email status, then send.' );
    echo '<div class="sre-toolbar">';
    echo '<span class="sre-toolbar-label">Window:</span>';
    foreach ( array( 7 => '7 Days', 14 => '14 Days', 30 => '30 Days', 60 => '60 Days' ) as $w => $label ) {
        $active = $f_window === $w ? ' button-primary' : '';
        $em_param = $f_emailed ? '&filter_emailed=' . urlencode( $f_emailed ) : '';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-customers&tab=predictions&window=' . $w . $em_param ) ) . '" class="button' . $active . '">' . $label . '</a>';
    }
    echo '<span class="sre-toolbar-divider"></span>';
    echo '<span class="sre-toolbar-label">Show:</span>';
    $base_url = admin_url( 'admin.php?page=smart-reorder-customers&tab=predictions&window=' . $f_window );
    echo '<a href="' . esc_url( $base_url ) . '" class="button' . ( ! $f_emailed ? ' button-primary' : '' ) . '">All</a>';
    echo '<a href="' . esc_url( $base_url . '&filter_emailed=no' ) . '" class="button' . ( $f_emailed === 'no' ? ' button-primary' : '' ) . '">Not Emailed</a>';
    echo '<a href="' . esc_url( $base_url . '&filter_emailed=yes' ) . '" class="button' . ( $f_emailed === 'yes' ? ' button-primary' : '' ) . '">Already Emailed</a>';
    echo '<span class="sre-toolbar-divider"></span>';
    echo '<button class="button" id="sre-predict" style="background:#7c3aed;border-color:#7c3aed;color:#fff">Refresh Predictions</button>';
    echo sre_tip( 'Re-runs the daily segmentation pass right now. Use after importing new orders or if predictions look stale.' );
    if ( $total > 0 ) {
        echo ' <button class="button" id="sre-bulk-ai" style="background:#2563eb;border-color:#2563eb;color:#fff">AI Email All Upcoming (' . $total . ')</button>';
        echo sre_tip( 'Generates a personalized email per customer using your AI provider, then sends all ' . $total . ' in one batch.' );
        echo ' <button class="button" id="sre-bulk-std">Email All Upcoming (' . $total . ')</button>';
        echo sre_tip( 'Sends the standard reorder template (no AI) to all ' . $total . ' upcoming customers.' );
    }
    echo '</div>';

    sre_section( 'Predicted reorders', 'Sorted soonest first. Click a row to view full customer detail.' );
    echo '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<table class="wp-list-table widefat" style="font-size:13px;margin:0;border-collapse:collapse">';
    echo '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
    echo '<th style="padding:10px 14px">Customer</th>';
    echo '<th style="padding:10px;width:90px">Segment ' . sre_tip( 'AI-classified bucket: VIP, Loyal, At Risk, Churned, or New. Drives the discount amount and email tone.' ) . '</th>';
    echo '<th style="padding:10px;width:120px">Predicted Date ' . sre_tip( 'When the buying pattern projects the customer is most likely to reorder. Calculated as last-order-date + average-days-between-orders.' ) . '</th>';
    echo '<th style="padding:10px;width:90px">Days Until ' . sre_tip( 'Days from now until the predicted reorder. Red when 2 or fewer days remain — those are the most time-sensitive sends.' ) . '</th>';
    echo '<th style="padding:10px;width:90px">Frequency ' . sre_tip( 'Average gap between this customer\'s past orders, in days. The basis of every prediction. Higher confidence with 3+ past orders.' ) . '</th>';
    echo '<th style="padding:10px;width:90px">Days Since ' . sre_tip( 'Days since the customer\'s last completed order. When this exceeds 2x the frequency, they are flagged at-risk; 3x is churned.' ) . '</th>';
    echo '<th style="padding:10px;width:100px">Revenue ' . sre_tip( 'Lifetime spend across all completed orders. Use to prioritize: high-revenue customers earn priority outreach.' ) . '</th>';
    echo '<th style="padding:10px;width:80px">Risk ' . sre_tip( 'Churn-risk score 0–100. Combines days-since-last-order, order frequency, and total order count. Higher = more likely to stop buying without intervention.' ) . '</th>';
    echo '<th style="padding:10px;width:160px;text-align:center">Actions</th>';
    echo '</tr></thead><tbody>';

    $row_emails  = wp_list_pluck( $rows, 'customer_email' );
    $emailed_map = sre_get_emailed_map( $row_emails );

    if ( empty( $rows ) ) {
        echo '<tr><td colspan="9" style="padding:20px;text-align:center;color:#6b7280">No predictions in this window. Try a larger window or run segmentation first.</td></tr>';
    } else {
        $ri = 0;
        foreach ( $rows as $r ) {
            $days_until = max( 0, (int) round( ( strtotime( $r->predicted_next ) - time() ) / 86400 ) );
            $row_bg = $ri % 2 === 0 ? '#fff' : '#f9fafb';
            $ri++;
            echo '<tr class="sre-cust-row" data-email="' . esc_attr( $r->customer_email ) . '" style="background:' . $row_bg . ';border-bottom:1px solid #f3f4f6;cursor:pointer" title="Click to view customer details">';
            echo '<td style="padding:10px 14px"><a href="#" class="sre-cust-link" style="color:#2563eb;text-decoration:none;font-weight:500">' . esc_html( $r->customer_email ) . '</a>' . sre_emailed_badge( $r->customer_email, $emailed_map ) . '</td>';
            echo '<td style="padding:10px">' . sre_segment_badge( $r->segment ) . '</td>';
            echo '<td style="padding:10px;font-weight:600">' . esc_html( wp_date( 'M j, Y', strtotime( $r->predicted_next ) ) ) . '</td>';
            echo '<td style="padding:10px;font-weight:700;color:' . ( $days_until <= 2 ? '#dc2626' : '#2563eb' ) . '">' . $days_until . 'd</td>';
            echo '<td style="padding:10px">~' . (int) $r->order_frequency . 'd</td>';
            echo '<td style="padding:10px">' . (int) $r->days_since_last . 'd</td>';
            echo '<td style="padding:10px">' . sre_money( $r->total_revenue, 0 ) . '</td>';
            echo '<td style="padding:10px">' . sre_risk_bar( $r->risk_score ) . '</td>';
            echo '<td style="padding:10px;text-align:center;white-space:nowrap">';
            echo '<button class="button sre-pred-send" data-email="' . esc_attr( $r->customer_email ) . '" data-mode="ai" style="background:#7c3aed;border-color:#7c3aed;color:#fff;font-size:11px;padding:2px 8px;margin:0 2px">AI Email</button>';
            echo '<button class="button sre-pred-send" data-email="' . esc_attr( $r->customer_email ) . '" data-mode="standard" style="font-size:11px;padding:2px 8px;margin:0 2px">Email</button>';
            echo '</td>';
            echo '</tr>';
            echo '<tr class="sre-detail-row" data-email="' . esc_attr( $r->customer_email ) . '" style="display:none"><td colspan="9" style="padding:0"></td></tr>';
        }
    }
    echo '</tbody></table></div>';
    echo '<div style="margin-top:12px">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $pg, 'total' => ceil( $total / $per ) ) ) . '</div>';
    echo '<div id="sre-pred-msg" style="margin-top:14px"></div>';
    echo '</div>';

    echo '<script>jQuery(function($){';
    echo 'var n="' . esc_js( $nonce ) . '";';
    echo 'function msg(t,ok){var bg=ok?"#dcfce7":"#fee2e2",c=ok?"#166534":"#991b1b";';
    echo '$("#sre-pred-msg").html("<div style=\"padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;background:"+bg+";color:"+c+"\">"+t+"</div>");}';

    echo '$("#sre-predict").on("click",function(){';
    echo 'var b=$(this),o=b.text();b.prop("disabled",true).text("Updating...");';
    echo '$.post(ajaxurl,{action:"sre_predict_reorder",nonce:n},function(r){';
    echo 'msg(r.data,r.success);';
    echo 'b.prop("disabled",false).text(o);if(r.success)setTimeout(function(){location.reload();},2000);});});';

    echo '$(".sre-pred-send").on("click",function(){';
    echo 'var b=$(this),em=b.data("email"),md=b.data("mode"),o=b.text();';
    echo 'if(!confirm("Send "+(md==="ai"?"AI":"standard")+" reorder email to "+em+"?"))return;';
    echo 'b.prop("disabled",true).text("Sending...");';
    echo '$.post(ajaxurl,{action:"sre_predict_send",nonce:n,emails:em,mode:md},function(r){';
    echo 'msg(r.data,r.success);b.prop("disabled",false).text(o);';
    echo '});});';

    echo '$("#sre-bulk-ai,#sre-bulk-std").on("click",function(){';
    echo 'var b=$(this),md=$(this).attr("id")==="sre-bulk-ai"?"ai":"standard",o=b.text();';
    echo 'var emails=[];$(".sre-pred-send[data-mode=\\"ai\\"]").each(function(){emails.push($(this).data("email"));});';
    echo 'if(!emails.length){msg("No customers to email.",false);return;}';
    echo 'if(!confirm("Send "+(md==="ai"?"AI":"standard")+" emails to "+emails.length+" customers?"))return;';
    echo 'b.prop("disabled",true).text("Sending...");';
    echo '$.post(ajaxurl,{action:"sre_predict_send",nonce:n,emails:emails,mode:md},function(r){';
    echo 'msg(r.data,r.success);b.prop("disabled",false).text(o);';
    echo '});});';

    sre_inline_customer_detail_js();

    echo '});</script>';
}

// ----------------------------------------------------------------
// PRO PAGE: DISCOUNT CODES
// ----------------------------------------------------------------
function sre_page_discounts() {
    if ( ! sre_is_pro() ) { wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ); exit; }
    global $wpdb;
    $disc_tbl = $wpdb->prefix . 'sre_discounts';
    $nonce    = wp_create_nonce( 'sre_nonce' );

    $filter   = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
    $search   = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
    $per      = 50;
    $pg       = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

    $where = '1=1';
    $params = array();
    if ( 'active' === $filter ) {
        $where .= " AND (expires_at IS NULL OR expires_at > NOW()) AND used_count < max_uses";
    } elseif ( 'used' === $filter ) {
        $where .= " AND used_count > 0";
    } elseif ( 'expired' === $filter ) {
        $where .= " AND expires_at IS NOT NULL AND expires_at <= NOW()";
    } elseif ( 'unused' === $filter ) {
        $where .= " AND used_count = 0 AND (expires_at IS NULL OR expires_at > NOW())";
    }
    if ( $search ) {
        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $where .= $wpdb->prepare( " AND (code LIKE %s OR customer_email LIKE %s)", $like, $like );
    }

    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$disc_tbl} WHERE {$where}" );
    $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$disc_tbl} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per, ( $pg - 1 ) * $per ) );

    $count_all     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$disc_tbl}" );
    $count_active  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$disc_tbl} WHERE (expires_at IS NULL OR expires_at > NOW()) AND used_count < max_uses" );
    $count_used    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$disc_tbl} WHERE used_count > 0" );
    $count_expired = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$disc_tbl} WHERE expires_at IS NOT NULL AND expires_at <= NOW()" );
    $count_unused  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$disc_tbl} WHERE used_count = 0 AND (expires_at IS NULL OR expires_at > NOW())" );
    $total_revenue = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(el.revenue), 0) FROM {$wpdb->prefix}sre_email_log el
         INNER JOIN {$disc_tbl} d ON el.discount_code = d.code
         WHERE el.converted = 1"
    );

    sre_page_header( 'Discount Codes' );
    sre_page_intro( 'Single-use coupon codes the plugin auto-generated for customers. Each is restricted to the recipient\'s email address and expires 30 days after issue. The discount % is set by segment in Settings.' );

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:18px">';
    echo '<div style="background:#fff;border-radius:8px;padding:18px;border-left:4px solid #2563eb;box-shadow:0 1px 4px rgba(0,0,0,.07)"><p style="margin:0 0 4px;font-size:12px;color:#6b7280">Total Codes</p><p style="margin:0;font-size:28px;font-weight:700;color:#2563eb">' . number_format( $count_all ) . '</p></div>';
    echo '<div style="background:#fff;border-radius:8px;padding:18px;border-left:4px solid #16a34a;box-shadow:0 1px 4px rgba(0,0,0,.07)"><p style="margin:0 0 4px;font-size:12px;color:#6b7280">Active</p><p style="margin:0;font-size:28px;font-weight:700;color:#16a34a">' . number_format( $count_active ) . '</p></div>';
    echo '<div style="background:#fff;border-radius:8px;padding:18px;border-left:4px solid #7c3aed;box-shadow:0 1px 4px rgba(0,0,0,.07)"><p style="margin:0 0 4px;font-size:12px;color:#6b7280">Redeemed</p><p style="margin:0;font-size:28px;font-weight:700;color:#7c3aed">' . number_format( $count_used ) . '</p></div>';
    echo '<div style="background:#fff;border-radius:8px;padding:18px;border-left:4px solid #dc2626;box-shadow:0 1px 4px rgba(0,0,0,.07)"><p style="margin:0 0 4px;font-size:12px;color:#6b7280">Expired</p><p style="margin:0;font-size:28px;font-weight:700;color:#dc2626">' . number_format( $count_expired ) . '</p></div>';
    echo '<div style="background:#fff;border-radius:8px;padding:18px;border-left:4px solid #ea580c;box-shadow:0 1px 4px rgba(0,0,0,.07)"><p style="margin:0 0 4px;font-size:12px;color:#6b7280">Revenue</p><p style="margin:0;font-size:28px;font-weight:700;color:#ea580c">' . wc_price( $total_revenue ) . '</p></div>';
    echo '</div>';

    $base_url = admin_url( 'admin.php?page=smart-reorder-campaigns&tab=discounts' );
    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;align-items:center">';
    $filters = array(
        ''        => 'All (' . $count_all . ')',
        'active'  => 'Active (' . $count_active . ')',
        'used'    => 'Redeemed (' . $count_used . ')',
        'unused'  => 'Unused (' . $count_unused . ')',
        'expired' => 'Expired (' . $count_expired . ')',
    );
    foreach ( $filters as $fkey => $flabel ) {
        $furl   = $fkey ? add_query_arg( 'status', $fkey, $base_url ) : $base_url;
        $fclass = ( $filter === $fkey ) ? 'button-primary' : '';
        echo '<a href="' . esc_url( $furl ) . '" class="button ' . esc_attr( $fclass ) . '">' . esc_html( $flabel ) . '</a>';
    }
    echo '<form method="get" style="margin-left:auto;display:flex;gap:6px">';
    echo '<input type="hidden" name="page" value="smart-reorder-campaigns">';
    echo '<input type="hidden" name="tab" value="discounts">';
    if ( $filter ) echo '<input type="hidden" name="status" value="' . esc_attr( $filter ) . '">';
    echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search code or email..." style="padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;width:220px">';
    echo '<button type="submit" class="button">Search</button>';
    if ( $search ) echo '<a href="' . esc_url( $filter ? add_query_arg( 'status', $filter, $base_url ) : $base_url ) . '" class="button">Clear</a>';
    echo '</form>';
    echo '</div>';

    echo '<div style="background:#fff;border-radius:8px;padding:16px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h3 style="margin:0 0 10px;font-size:14px">Generate Manual Discount</h3>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Customer Email</label>';
    echo '<input type="email" id="disc-email" placeholder="customer@email.com" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;width:220px"></div>';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Segment</label>';
    echo '<select id="disc-seg" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">';
    echo '<option value="vip">VIP</option><option value="loyal">Loyal</option><option value="at_risk">At Risk</option><option value="churned">Churned</option><option value="new">New</option>';
    echo '</select></div>';
    echo '<button class="button button-primary" id="disc-gen">Generate Code</button>';
    echo '</div>';
    echo '<div id="disc-msg" style="margin-top:10px"></div>';
    echo '</div>';

    echo '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<table class="wp-list-table widefat" style="font-size:13px;margin:0;border-collapse:collapse">';
    echo '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
    echo '<th style="padding:10px 14px">Code</th><th style="padding:10px">Customer</th>';
    echo '<th style="padding:10px;width:80px">Segment</th><th style="padding:10px;width:80px">Amount</th>';
    echo '<th style="padding:10px;width:90px">Status</th>';
    echo '<th style="padding:10px;width:90px">WooCommerce</th>';
    echo '<th style="padding:10px;width:100px">Expires</th>';
    echo '<th style="padding:10px;width:100px">Created</th>';
    echo '</tr></thead><tbody>';

    if ( empty( $rows ) ) {
        echo '<tr><td colspan="8" style="padding:20px;text-align:center;color:#6b7280">No discount codes found.' . ( $search || $filter ? ' Try adjusting your filters.' : ' Generate one above or enable AI emails to auto-create them.' ) . '</td></tr>';
    } else {
        $ri = 0;
        foreach ( $rows as $r ) {
            $row_bg  = $ri % 2 === 0 ? '#fff' : '#f9fafb';
            $expired = $r->expires_at && strtotime( $r->expires_at ) < time();
            $used    = $r->used_count > 0;
            $ri++;

            if ( $used ) {
                $status_html = '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;background:#dcfce7;color:#166534">Redeemed</span>';
            } elseif ( $expired ) {
                $status_html = '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;background:#fee2e2;color:#991b1b">Expired</span>';
            } else {
                $status_html = '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;background:#dbeafe;color:#1e40af">Active</span>';
            }

            $wc_html = '&mdash;';
            if ( $r->wc_coupon_id > 0 ) {
                $wc_usage = (int) get_post_meta( $r->wc_coupon_id, 'usage_count', true );
                $wc_link  = admin_url( 'post.php?post=' . $r->wc_coupon_id . '&action=edit' );
                if ( $wc_usage > 0 ) {
                    $wc_html = '<a href="' . esc_url( $wc_link ) . '" style="color:#16a34a;font-weight:600;text-decoration:none" title="View in WooCommerce">Used ' . $wc_usage . 'x</a>';
                } else {
                    $wc_html = '<a href="' . esc_url( $wc_link ) . '" style="color:#6b7280;text-decoration:none" title="View in WooCommerce">Not used</a>';
                }
            }

            $days_left = '';
            if ( ! $expired && $r->expires_at ) {
                $remaining = max( 0, (int) round( ( strtotime( $r->expires_at ) - time() ) / 86400 ) );
                $days_left = ' <span style="color:' . ( $remaining <= 3 ? '#dc2626' : '#6b7280' ) . ';font-size:11px">(' . $remaining . 'd left)</span>';
            }

            echo '<tr style="background:' . $row_bg . ';border-bottom:1px solid #f3f4f6;' . ( $expired && ! $used ? 'opacity:0.6;' : '' ) . '">';
            echo '<td style="padding:10px 14px"><code style="font-size:12px;background:#f3f4f6;padding:3px 8px;border-radius:4px;font-weight:700">' . esc_html( $r->code ) . '</code></td>';
            echo '<td style="padding:10px">' . esc_html( $r->customer_email ) . '</td>';
            echo '<td style="padding:10px">' . sre_segment_badge( $r->segment ) . '</td>';
            echo '<td style="padding:10px;font-weight:700">' . (int) $r->amount . '%</td>';
            echo '<td style="padding:10px">' . $status_html . '</td>';
            echo '<td style="padding:10px">' . $wc_html . '</td>';
            echo '<td style="padding:10px;font-size:12px">' . ( $r->expires_at ? esc_html( wp_date( 'M j, Y', strtotime( $r->expires_at ) ) ) : 'Never' ) . ( $expired ? ' <span style="color:#dc2626;font-weight:600">EXPIRED</span>' : $days_left ) . '</td>';
            echo '<td style="padding:10px;font-size:12px">' . esc_html( wp_date( 'M j, Y', strtotime( $r->created_at ) ) ) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';
    $pag_args = array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $pg, 'total' => ceil( $total / $per ) );
    $pag_links = paginate_links( $pag_args );
    if ( $pag_links ) {
        echo '<div style="margin-top:12px">' . $pag_links . '</div>';
    }
    echo '</div>';

    echo '<script>jQuery(function($){';
    echo 'var n="' . esc_js( $nonce ) . '";';
    echo '$("#disc-gen").on("click",function(){';
    echo 'var b=$(this),email=$("#disc-email").val(),seg=$("#disc-seg").val();';
    echo 'if(!email){$("#disc-msg").html("<div style=\"padding:8px 14px;border-radius:6px;font-size:13px;background:#fee2e2;color:#991b1b\">Enter an email address.</div>");return;}';
    echo 'b.prop("disabled",true).text("Creating...");';
    echo '$.post(ajaxurl,{action:"sre_create_discount",nonce:n,email:email,segment:seg},function(r){';
    echo 'if(r.success){$("#disc-msg").html("<div style=\"padding:8px 14px;border-radius:6px;font-size:13px;background:#dcfce7;color:#166534\">Code created: <strong>"+r.data.code+"</strong> ("+r.data.amount+"% off)</div>");setTimeout(function(){location.reload();},2000);}';
    echo 'else{$("#disc-msg").html("<div style=\"padding:8px 14px;border-radius:6px;font-size:13px;background:#fee2e2;color:#991b1b\">"+r.data+"</div>");}';
    echo 'b.prop("disabled",false).text("Generate Code");});});';
    echo '});</script>';
}

// ----------------------------------------------------------------
// PRO PAGE: A/B TESTS
// ----------------------------------------------------------------
function sre_page_abtests() {
    if ( ! sre_is_pro() ) { wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ); exit; }
    global $wpdb;
    $ab_tbl = $wpdb->prefix . 'sre_ab_tests';
    $nonce  = wp_create_nonce( 'sre_nonce' );

    $tests   = $wpdb->get_results( "SELECT * FROM {$ab_tbl} ORDER BY created_at DESC LIMIT 50" );
    $running = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ab_tbl} WHERE status='running'" );

    sre_page_header( 'A/B Tests' );
    sre_page_intro( 'Run A/B tests on subject lines (and bodies) to find what gets opened and clicked. Variant assignment is sticky per recipient as of 5.1.0 — once a customer sees variant A, all future sends of the same test stay A.' );
    sre_explainer(
        'How a winner is declared',
        'A test needs at least <strong>50 sends per variant</strong> AND <strong>100 total sends</strong> before the winner check runs. The variant with the higher click-through rate wins. Below those thresholds the test just keeps running so the result is statistically meaningful.'
    );

    echo '<div style="background:#fff;border-radius:8px;padding:22px;margin-bottom:18px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h2 style="margin:0 0 14px;font-size:16px">Create New A/B Test</h2>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Test Name</label>';
    echo '<input type="text" id="ab-name" placeholder="e.g., Urgency vs Friendly" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px"></div>';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Target Segment</label>';
    echo '<select id="ab-segment" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">';
    echo '<option value="all">All Segments</option><option value="vip">VIP</option><option value="loyal">Loyal</option><option value="at_risk">At Risk</option><option value="churned">Churned</option><option value="new">New</option>';
    echo '</select></div>';
    echo '</div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Subject Line A</label>';
    echo '<input type="text" id="ab-subj-a" placeholder="Time to restock {product_name}!" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px"></div>';
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Subject Line B</label>';
    echo '<input type="text" id="ab-subj-b" placeholder="{customer_name}, your {product_name} is running low!" style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px"></div>';
    echo '</div>';
    echo '<p style="margin:10px 0 0;font-size:12px;color:#6b7280">Placeholders: <code>{product_name}</code> <code>{customer_name}</code> <code>{days}</code></p>';
    echo '<button class="button button-primary" id="ab-create" style="margin-top:12px">Start A/B Test</button>';
    echo '<div id="ab-msg" style="margin-top:10px"></div>';
    echo '</div>';

    if ( ! empty( $tests ) ) {
        echo '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<table class="wp-list-table widefat" style="font-size:13px;margin:0;border-collapse:collapse">';
        echo '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
        echo '<th style="padding:10px 14px">Test Name</th><th style="padding:10px;width:80px">Status</th>';
        echo '<th style="padding:10px">Subject A</th><th style="padding:10px">Subject B</th>';
        echo '<th style="padding:10px;width:60px">Sent A</th><th style="padding:10px;width:60px">Sent B</th>';
        echo '<th style="padding:10px;width:70px">Clicks A</th><th style="padding:10px;width:70px">Clicks B</th>';
        echo '<th style="padding:10px;width:70px">Winner</th>';
        echo '</tr></thead><tbody>';

        $ri = 0;
        foreach ( $tests as $t ) {
            $row_bg = $ri % 2 === 0 ? '#fff' : '#f9fafb';
            $ri++;
            $status_color = $t->status === 'running' ? '#16a34a' : '#6b7280';
            $status_bg    = $t->status === 'running' ? '#dcfce7' : '#f3f4f6';
            echo '<tr style="background:' . $row_bg . ';border-bottom:1px solid #f3f4f6">';
            echo '<td style="padding:10px 14px;font-weight:600">' . esc_html( $t->test_name ) . '</td>';
            echo '<td style="padding:10px"><span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;background:' . $status_bg . ';color:' . $status_color . '">' . ucfirst( $t->status ) . '</span></td>';
            echo '<td style="padding:10px;font-size:12px">' . esc_html( $t->subject_a ) . '</td>';
            echo '<td style="padding:10px;font-size:12px">' . esc_html( $t->subject_b ) . '</td>';
            echo '<td style="padding:10px">' . (int) $t->sent_a . '</td>';
            echo '<td style="padding:10px">' . (int) $t->sent_b . '</td>';
            echo '<td style="padding:10px">' . (int) $t->clicks_a . '</td>';
            echo '<td style="padding:10px">' . (int) $t->clicks_b . '</td>';
            echo '<td style="padding:10px">';
            if ( $t->winner ) {
                echo '<span style="font-size:12px;font-weight:700;padding:2px 8px;border-radius:10px;background:#dcfce7;color:#16a34a">' . esc_html( $t->winner ) . '</span>';
            } elseif ( $t->status === 'running' ) {
                echo '<span style="font-size:11px;color:#6b7280">Testing...</span>';
            } else {
                echo '&mdash;';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<div style="background:#fff;border-radius:8px;padding:26px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<h3 style="margin:0 0 8px">No A/B tests yet</h3>';
        echo '<p style="color:#6b7280;font-size:13px;margin:0">Create your first test above to start optimizing your email subject lines.</p>';
        echo '</div>';
    }
    echo '</div>';

    echo '<script>jQuery(function($){';
    echo 'var n="' . esc_js( $nonce ) . '";';
    echo '$("#ab-create").on("click",function(){';
    echo 'var b=$(this),name=$("#ab-name").val(),sa=$("#ab-subj-a").val(),sb=$("#ab-subj-b").val(),seg=$("#ab-segment").val();';
    echo 'if(!name||!sa||!sb){$("#ab-msg").html("<div style=\"padding:8px 14px;border-radius:6px;font-size:13px;background:#fee2e2;color:#991b1b\">All fields are required.</div>");return;}';
    echo 'b.prop("disabled",true).text("Creating...");';
    echo '$.post(ajaxurl,{action:"sre_create_ab_test",nonce:n,test_name:name,subject_a:sa,subject_b:sb,segment_filter:seg},function(r){';
    echo 'var ok=r.success,bg=ok?"#dcfce7":"#fee2e2",c=ok?"#166534":"#991b1b";';
    echo '$("#ab-msg").html("<div style=\"padding:8px 14px;border-radius:6px;font-size:13px;background:"+bg+";color:"+c+"\">"+r.data+"</div>");';
    echo 'b.prop("disabled",false).text("Start A/B Test");if(ok)setTimeout(function(){location.reload();},2000);});});';
    echo '});</script>';
}

// ----------------------------------------------------------------
// PRO PAGE: AI LOG
// ----------------------------------------------------------------
function sre_page_ailog() {
    if ( ! sre_is_pro() ) { wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) ); exit; }
    global $wpdb;
    $ai_tbl = $wpdb->prefix . 'sre_ai_logs';
    $per    = 50;
    $pg     = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
    $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ai_tbl}" );
    $rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ai_tbl} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per, ( $pg - 1 ) * $per ) );

    $total_tokens = (int) $wpdb->get_var( "SELECT COALESCE(SUM(tokens_used),0) FROM {$ai_tbl}" );
    $today_tokens = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(tokens_used),0) FROM {$ai_tbl} WHERE created_at >= %s", gmdate( 'Y-m-d 00:00:00' ) ) );

    sre_page_header( 'AI Activity Log' );

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px">';
    echo '<div style="background:#fff;border-radius:8px;padding:18px;border-left:4px solid #7c3aed;box-shadow:0 1px 4px rgba(0,0,0,.07)"><p style="margin:0 0 4px;font-size:12px;color:#6b7280">Total AI Calls</p><p style="margin:0;font-size:28px;font-weight:700;color:#7c3aed">' . number_format( $total ) . '</p></div>';
    echo '<div style="background:#fff;border-radius:8px;padding:18px;border-left:4px solid #2563eb;box-shadow:0 1px 4px rgba(0,0,0,.07)"><p style="margin:0 0 4px;font-size:12px;color:#6b7280">Total Tokens</p><p style="margin:0;font-size:28px;font-weight:700;color:#2563eb">' . number_format( $total_tokens ) . '</p></div>';
    echo '<div style="background:#fff;border-radius:8px;padding:18px;border-left:4px solid #059669;box-shadow:0 1px 4px rgba(0,0,0,.07)"><p style="margin:0 0 4px;font-size:12px;color:#6b7280">Tokens Today</p><p style="margin:0;font-size:28px;font-weight:700;color:#059669">' . number_format( $today_tokens ) . '</p></div>';
    echo '</div>';

    echo '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<table class="wp-list-table widefat" style="font-size:13px;margin:0;border-collapse:collapse">';
    echo '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
    echo '<th style="padding:10px 14px">Date</th><th style="padding:10px;width:120px">Action</th>';
    echo '<th style="padding:10px">Customer</th><th style="padding:10px">Summary</th>';
    echo '<th style="padding:10px;width:80px">Tokens</th>';
    echo '</tr></thead><tbody>';

    if ( empty( $rows ) ) {
        echo '<tr><td colspan="5" style="padding:20px;text-align:center;color:#6b7280">No AI activity yet. Enable AI emails in Settings to start.</td></tr>';
    } else {
        $ri = 0;
        foreach ( $rows as $r ) {
            $row_bg = $ri % 2 === 0 ? '#fff' : '#f9fafb';
            $ri++;
            echo '<tr style="background:' . $row_bg . ';border-bottom:1px solid #f3f4f6">';
            echo '<td style="padding:10px 14px;font-size:12px">' . esc_html( wp_date( 'M j, g:i A', strtotime( $r->created_at ) ) ) . '</td>';
            echo '<td style="padding:10px"><span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:#ede9fe;color:#7c3aed">' . esc_html( ucwords( str_replace( '_', ' ', $r->action_type ) ) ) . '</span></td>';
            echo '<td style="padding:10px">' . esc_html( $r->customer_email ) . '</td>';
            echo '<td style="padding:10px;font-size:12px;color:#6b7280">' . esc_html( substr( $r->prompt_summary, 0, 100 ) ) . '</td>';
            echo '<td style="padding:10px;font-weight:600">' . number_format( $r->tokens_used ) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';
    echo '<div style="margin-top:12px">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $pg, 'total' => ceil( $total / $per ) ) ) . '</div>';
    echo '</div>';
}

// ----------------------------------------------------------------
// PAGE: ANALYTICS (Enhanced for Pro)
// ----------------------------------------------------------------
function sre_page_analytics() {
    if ( ! sre_is_pro() ) {
        wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) );
        exit;
    }
    global $wpdb;
    $lt      = $wpdb->prefix . 'sre_email_log';
    $pt      = $wpdb->prefix . 'sre_patterns';
    $seg_tbl = $wpdb->prefix . 'sre_segments';
    $disc_tbl= $wpdb->prefix . 'sre_discounts';
    $ab_tbl  = $wpdb->prefix . 'sre_ab_tests';

    $by_type  = $wpdb->get_results( "SELECT email_type, COUNT(*) AS cnt FROM {$lt} GROUP BY email_type" );
    $by_day   = $wpdb->get_results( "SELECT DATE(sent_date) AS d, COUNT(*) AS cnt FROM {$lt} WHERE sent_date >= DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY DATE(sent_date) ORDER BY d ASC" );
    $top_prod = $wpdb->get_results( "SELECT product_id, COUNT(*) AS cnt FROM {$lt} GROUP BY product_id ORDER BY cnt DESC LIMIT 10" );
    $freq     = $wpdb->get_results( "SELECT CASE WHEN avg_days BETWEEN 1 AND 10 THEN 'Weekly' WHEN avg_days BETWEEN 11 AND 21 THEN 'Bi-weekly' WHEN avg_days BETWEEN 22 AND 50 THEN 'Monthly' WHEN avg_days > 50 THEN 'Infrequent' ELSE 'Single' END AS frequency, COUNT(*) AS cnt FROM {$pt} GROUP BY frequency ORDER BY cnt DESC" );

    $seg_dist   = $wpdb->get_results( "SELECT segment, COUNT(*) as cnt, AVG(total_revenue) as avg_rev, AVG(risk_score) as avg_risk FROM {$seg_tbl} GROUP BY segment ORDER BY cnt DESC" );
    $total_rev  = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total_revenue),0) FROM {$seg_tbl}" );
    $avg_ltv    = (float) $wpdb->get_var( "SELECT COALESCE(AVG(lifetime_value),0) FROM {$seg_tbl}" );
    $disc_used  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$disc_tbl} WHERE used_count > 0" );
    $disc_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$disc_tbl}" );
    $ab_completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ab_tbl} WHERE status='completed'" );

    $total_emails  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt}" );
    $total_opened  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt} WHERE opened=1" );
    $total_clicked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt} WHERE clicked=1" );
    $total_convert = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt} WHERE converted=1" );
    $email_revenue = (float) $wpdb->get_var( "SELECT COALESCE(SUM(revenue),0) FROM {$lt} WHERE converted=1" );
    $open_rate   = $total_emails > 0 ? round( ( $total_opened / $total_emails ) * 100, 1 ) : 0;
    $click_rate  = $total_emails > 0 ? round( ( $total_clicked / $total_emails ) * 100, 1 ) : 0;
    $conv_rate   = $total_emails > 0 ? round( ( $total_convert / $total_emails ) * 100, 1 ) : 0;

    sre_page_header( 'Analytics' );

    sre_page_intro( 'How your reorder program is performing — overall revenue, email engagement rates, and how many converted into sales. Email Revenue uses the tightened attribution rules introduced in 5.1.0 (coupon match, click, or product overlap required).' );

    sre_section( 'Customer value' );
    echo '<div class="sre-stat-grid">';
    sre_stat_card(
        'Total Revenue', sre_money( $total_rev, 0 ), '#2563eb',
        'Sum of every segmented customer\'s lifetime revenue across all completed orders. Updated nightly by segmentation.'
    );
    sre_stat_card(
        'Avg LTV', sre_money( $avg_ltv, 0 ), '#7c3aed',
        'Average lifetime value across all customers (Total Revenue ÷ customers). LTV is projected forward from order frequency for active multi-order customers.'
    );
    sre_stat_card(
        'Email Revenue', sre_money( $email_revenue, 0 ), '#ea580c',
        'Revenue from orders we can credibly attribute to a reorder email — the customer either used a code we issued, clicked the email, or bought the exact product we promoted. Tightened in 5.1.0 to remove false-positive attribution.'
    );
    sre_stat_card(
        'Discounts Used', $disc_used . '/' . $disc_total, '#059669',
        'Single-use coupon codes redeemed (numerator) out of total issued (denominator). High ratio means your discount tiers are well-calibrated.'
    );
    echo '</div>';

    sre_section( 'Email engagement', 'Per-email rates across all sends.' );
    echo '<div class="sre-stat-grid">';
    sre_stat_card(
        'Open Rate', $open_rate . '%', '#0891b2',
        'Percentage of emails where the tracking pixel loaded. Industry baseline is 15–25%. Some clients (Apple Mail Privacy Protection) inflate opens artificially.'
    );
    sre_stat_card(
        'Click Rate', $click_rate . '%', '#0d9488',
        'Percentage of emails where any link was clicked. The most reliable engagement metric — pixels can be blocked, clicks cannot.'
    );
    sre_stat_card(
        'Conversion Rate', $conv_rate . '%', '#16a34a',
        'Percentage of emails that produced an attributed order. Uses the same 5.1.0 tightened-attribution rules as Email Revenue.'
    );
    sre_stat_card(
        'A/B Tests Done', $ab_completed, '#d97706',
        'Number of A/B tests that have completed (declared a winning variant). Run more A/B tests to keep optimizing your subject lines.'
    );
    echo '</div>';

    echo '<div style="background:#fff;border-radius:8px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:18px"><h3 style="margin:0 0 12px">Email Performance Funnel</h3>';
    $funnel = array(
        array( 'Sent', $total_emails, '#2563eb' ),
        array( 'Opened', $total_opened, '#0891b2' ),
        array( 'Clicked', $total_clicked, '#0d9488' ),
        array( 'Converted', $total_convert, '#16a34a' ),
    );
    $funnel_max = max( 1, $total_emails );
    foreach ( $funnel as $f ) {
        $pct = round( ( $f[1] / $funnel_max ) * 100 );
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">';
        echo '<span style="min-width:80px;font-size:13px;font-weight:600">' . $f[0] . '</span>';
        echo '<div style="flex:1;height:22px;background:#f3f4f6;border-radius:6px;overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:' . $f[2] . ';border-radius:6px;transition:width .3s"></div></div>';
        echo '<span style="min-width:70px;font-size:13px;font-weight:600;text-align:right">' . number_format( $f[1] ) . ' (' . $pct . '%)</span>';
        echo '</div>';
    }
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px">';

    echo '<div style="background:#fff;border-radius:8px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.07)"><h3 style="margin:0 0 12px">Segment Distribution</h3>';
    if ( ! empty( $seg_dist ) ) {
        $total_seg = 0;
        foreach ( $seg_dist as $s ) $total_seg += (int) $s->cnt;
        foreach ( $seg_dist as $s ) {
            $pct = $total_seg > 0 ? round( ( $s->cnt / $total_seg ) * 100 ) : 0;
            echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">';
            echo sre_segment_badge( $s->segment );
            echo '<div style="flex:1;height:16px;background:#f3f4f6;border-radius:8px;overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:#7c3aed;border-radius:8px"></div></div>';
            echo '<span style="font-size:12px;font-weight:600;min-width:60px">' . number_format( $s->cnt ) . ' (' . $pct . '%)</span>';
            echo '</div>';
        }
    } else {
        echo '<p style="color:#9ca3af">No segment data. Run segmentation first.</p>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border-radius:8px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.07)"><h3 style="margin:0 0 12px">Revenue by Segment</h3>';
    if ( ! empty( $seg_dist ) ) {
        echo '<table class="wp-list-table widefat striped" style="font-size:13px"><thead><tr><th>Segment</th><th>Customers</th><th>Avg Revenue</th><th>Avg Risk</th></tr></thead><tbody>';
        foreach ( $seg_dist as $s ) {
            echo '<tr><td>' . sre_segment_badge( $s->segment ) . '</td>';
            echo '<td>' . number_format( $s->cnt ) . '</td>';
            echo '<td>' . sre_money( $s->avg_rev, 0 ) . '</td>';
            echo '<td>' . sre_risk_bar( (int) $s->avg_risk ) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="color:#9ca3af">No data yet.</p>';
    }
    echo '</div>';

    foreach ( array( array( 'Emails by Type', $by_type, 'email_type' ), array( 'Purchase Frequency', $freq, 'frequency' ) ) as $panel ) {
        echo '<div style="background:#fff;border-radius:8px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.07)"><h3 style="margin:0 0 12px">' . $panel[0] . '</h3>';
        echo '<table class="wp-list-table widefat striped" style="font-size:13px"><thead><tr><th>Label</th><th>Count</th></tr></thead><tbody>';
        if ( empty( $panel[1] ) ) {
            echo '<tr><td colspan="2">No data yet.</td></tr>';
        } else {
            foreach ( $panel[1] as $r ) {
                $key = $panel[2];
                echo '<tr><td>' . esc_html( $r->$key ) . '</td><td><strong>' . number_format( $r->cnt ) . '</strong></td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    echo '<div style="background:#fff;border-radius:8px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.07)"><h3 style="margin:0 0 12px">Top Products Emailed</h3>';
    echo '<table class="wp-list-table widefat striped" style="font-size:13px"><thead><tr><th>Product</th><th>Emails</th></tr></thead><tbody>';
    if ( empty( $top_prod ) ) {
        echo '<tr><td colspan="2">None yet.</td></tr>';
    } else {
        foreach ( $top_prod as $r ) {
            $p = wc_get_product( $r->product_id );
            $n = $p ? $p->get_name() : '#' . $r->product_id;
            echo '<tr><td>' . esc_html( $n ) . '</td><td><strong>' . number_format( $r->cnt ) . '</strong></td></tr>';
        }
    }
    echo '</tbody></table></div>';

    echo '<div style="background:#fff;border-radius:8px;padding:18px;box-shadow:0 1px 4px rgba(0,0,0,.07);grid-column:1/-1"><h3 style="margin:0 0 12px">Emails Sent - Last 30 Days</h3>';
    if ( ! empty( $by_day ) ) {
        $max_v = 0;
        foreach ( $by_day as $d ) {
            if ( $d->cnt > $max_v ) {
                $max_v = $d->cnt;
            }
        }
        echo '<div style="display:flex;align-items:flex-end;gap:3px;height:80px;border-bottom:2px solid #e5e7eb">';
        foreach ( $by_day as $d ) {
            $h = $max_v > 0 ? max( 4, (int) round( ( $d->cnt / $max_v ) * 70 ) ) : 4;
            echo '<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:2px" title="' . esc_attr( $d->d . ': ' . $d->cnt ) . '">';
            echo '<span style="font-size:9px;color:#6b7280">' . (int) $d->cnt . '</span>';
            echo '<div style="width:100%;height:' . (int) $h . 'px;background:#7c3aed;border-radius:2px 2px 0 0"></div>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<p style="color:#9ca3af">No emails in last 30 days.</p>';
    }
    echo '</div></div></div>';
}

// ----------------------------------------------------------------
// PAGE: UPGRADE
// ----------------------------------------------------------------
function sre_page_upgrade() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'You do not have permission to view this page.' );
    }

    // Handle deactivation.
    if ( isset( $_POST['sre_deactivate_license'] ) && check_admin_referer( 'sre_pro_deactivate' ) ) {
        sre_deactivate_license();
        delete_option( 'sre_license_key' );
        update_option( 'sre_license_status', 'free' );
        delete_option( 'sre_license_plan' );
        delete_option( 'sre_license_max_sites' );
        delete_option( 'sre_license_expires' );
        delete_option( 'sre_license_reason' );
        delete_option( 'sre_license_status_message' );
        echo '<div class="notice notice-info"><p>License deactivated. This site slot is now free for use on another site.</p></div>';
    }

    // Handle activation.
    if ( isset( $_POST['sre_license_key'] ) && check_admin_referer( 'sre_pro_activate' ) ) {
        $key = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['sre_license_key'] ) ) ) );
        if ( sre_check_key( $key ) ) {
            update_option( 'sre_license_key', $key );
            update_option( 'sre_license_status', 'pro' );
            delete_option( 'sre_license_reason' );
            delete_option( 'sre_license_status_message' );
            sre_create_tables();
            echo '<div class="notice notice-success"><p>License activated! All Pro features are now unlocked.</p></div>';
        } else {
            $act_msg = get_option( 'sre_license_status_message', '' );
            if ( $act_msg ) {
                echo '<div class="notice notice-error"><p>Activation failed: ' . esc_html( $act_msg ) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Activation failed. Please check your license key and try again.</p></div>';
            }
        }
    }

    // Handle Pro signup request.
    if ( isset( $_POST['sre_pro_signup_submit'] ) && check_admin_referer( 'sre_pro_signup' ) ) {
        $email = sanitize_email( wp_unslash( $_POST['sre_signup_email'] ?? '' ) );
        $name  = sanitize_text_field( wp_unslash( $_POST['sre_signup_name'] ?? '' ) );
        if ( is_email( $email ) ) {
            $response = wp_remote_post( sre_license_api_url() . '/wp-json/sre-license/v1/signup', array(
                'timeout' => 15,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( array(
                    'email'    => $email,
                    'name'     => $name,
                    'site_url' => home_url(),
                    'product'  => 'smart-reorder-emailer-pro',
                ) ),
            ) );
            if ( ! is_wp_error( $response ) ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body['success'] ) ) {
                    // License server 3.9.1+ emails an existing key to the license email instead of returning it.
                    if ( ! empty( $body['license_key'] ) ) {
                        echo '<div class="notice notice-success"><p>You already have an active license! Your key is: <code>' . esc_html( $body['license_key'] ) . '</code> — enter it below to activate Pro.</p></div>';
                    } elseif ( 'emailed' === ( $body['status'] ?? '' ) ) {
                        echo '<div class="notice notice-success"><p>' . esc_html( $body['message'] ?? 'If this site already has a license, we have emailed the key to the license email.' ) . ' Enter it below to activate Pro.</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>Your Pro license request has been submitted! We will review it and send your license key by email.</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html( $body['message'] ?? $body['error'] ?? 'Signup request failed.' ) . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>Could not connect to the license server. Please try again later.</p></div>';
            }
        } else {
            echo '<div class="notice notice-error"><p>Please enter a valid email address.</p></div>';
        }
    }

    echo '<div class="wrap">';
    echo '<h1>Smart Reorder Emailer — Pro License</h1>';

    $has_key    = ! empty( get_option( 'sre_license_key', '' ) );
    $is_expired = sre_license_is_expired();

    // STATE 1: Pro Active
    if ( $has_key && ! $is_expired && sre_is_pro() ) {
        $plan    = get_option( 'sre_license_plan', 'single' );
        $max     = get_option( 'sre_license_max_sites', 1 );
        $expires = get_option( 'sre_license_expires', '' );

        echo '<div style="background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);border-radius:12px;padding:28px 32px;color:#fff;margin:20px 0 24px">';
        echo '<h2 style="color:#fff;margin:0 0 6px;font-size:22px">Pro License Active</h2>';
        echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:12px;font-size:14px">';
        echo '<span>Plan: <strong>' . esc_html( ucfirst( $plan ) ) . '</strong></span>';
        echo '<span>Key: <code style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px">' . esc_html( get_option( 'sre_license_key', '' ) ) . '</code></span>';
        echo '<span>Sites: <strong>' . ( $max >= 999 ? 'Unlimited' : intval( $max ) ) . '</strong></span>';
        if ( $expires ) {
            $exp_ts    = strtotime( $expires );
            $exp_fmt   = wp_date( 'F j, Y', $exp_ts );
            $days_left = max( 0, (int) ceil( ( $exp_ts - time() ) / 86400 ) );
            echo '<span>Expires: <strong>' . esc_html( $exp_fmt ) . '</strong>';
            if ( $days_left <= 30 ) {
                echo ' <span style="background:#fbbf24;color:#000;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">' . $days_left . ' days left</span>';
            } else {
                echo ' <span style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px;font-size:12px">' . $days_left . ' days left</span>';
            }
            echo '</span>';
        } else {
            echo '<span>Expires: <strong>Never</strong></span>';
        }
        echo '</div>';
        echo '</div>';

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
        echo '<h3 style="margin:0 0 8px;font-size:15px">Deactivate License</h3>';
        echo '<p style="margin:0 0 12px;color:#6b7280;font-size:13px">This will free up the site slot so you can use your license on a different site.</p>';
        echo '<form method="post">';
        wp_nonce_field( 'sre_pro_deactivate' );
        echo '<input type="hidden" name="sre_deactivate_license" value="1">';
        echo '<button type="submit" class="button" onclick="return confirm(\'Deactivate Pro on this site?\')">Deactivate License</button>';
        echo '</form>';
        echo '</div>';

    // STATE 2: Suspended
    } elseif ( $has_key && ! $is_expired && ! sre_is_pro() && get_option( 'sre_license_reason', '' ) === 'suspended' ) {
        $status_msg = get_option( 'sre_license_status_message', '' );
        echo '<div style="background:linear-gradient(135deg,#92400e 0%,#f59e0b 100%);border-radius:12px;padding:28px 32px;color:#fff;margin:20px 0 24px">';
        echo '<h2 style="color:#fff;margin:0 0 6px;font-size:22px">License Suspended</h2>';
        echo '<p style="margin:0 0 6px;font-size:14px;color:#fef3c7">Your Pro license has been suspended. Pro features are disabled.</p>';
        if ( $status_msg ) {
            echo '<div style="background:rgba(0,0,0,.2);border-radius:8px;padding:14px 18px;margin:12px 0 16px;">';
            echo '<p style="margin:0;font-size:13px;color:#fef3c7;"><strong>Reason from Cao-Tech LLC:</strong></p>';
            echo '<p style="margin:6px 0 0;font-size:14px;color:#fff;">' . esc_html( $status_msg ) . '</p>';
            echo '</div>';
        }
        echo '<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:14px;margin-bottom:16px">';
        echo '<span>Key: <code style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px">' . esc_html( get_option( 'sre_license_key', '' ) ) . '</code></span>';
        echo '</div>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
        echo '<a href="mailto:info@cao-tech.com?subject=Smart%20Reorder%20Emailer%20License%20Inquiry&body=Hi%20Cao-Tech%20LLC%2C%0A%0AMy%20license%20has%20been%20suspended.%20I%20would%20like%20to%20resolve%20this.%0A%0ASite%3A%20' . rawurlencode( home_url() ) . '%0AKey%3A%20' . rawurlencode( get_option( 'sre_license_key', '' ) ) . '%0A%0AThank%20you!" class="button" style="padding:8px 28px;font-size:14px;background:#fff;color:#92400e;border:none;font-weight:600">Contact Cao-Tech LLC</a>';
        echo '<a href="https://cao-tech.com/smart-reorder-emailer" target="_blank" class="button" style="padding:8px 28px;font-size:14px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)">Visit Cao-Tech.com</a>';
        echo '</div>';
        echo '</div>';

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
        echo '<h3 style="margin:0 0 8px;font-size:15px">Deactivate License</h3>';
        echo '<p style="margin:0 0 12px;color:#6b7280;font-size:13px">Remove this license key from your site.</p>';
        echo '<form method="post">';
        wp_nonce_field( 'sre_pro_deactivate' );
        echo '<input type="hidden" name="sre_deactivate_license" value="1">';
        echo '<button type="submit" class="button" onclick="return confirm(\'Remove this license key?\')">Remove License Key</button>';
        echo '</form>';
        echo '</div>';

    // STATE 3: Expired
    } elseif ( $has_key && $is_expired ) {
        $expires = get_option( 'sre_license_expires', '' );
        $exp_fmt = $expires ? wp_date( 'F j, Y', strtotime( $expires ) ) : '';

        echo '<div style="background:linear-gradient(135deg,#7f1d1d 0%,#dc2626 100%);border-radius:12px;padding:28px 32px;color:#fff;margin:20px 0 24px">';
        echo '<h2 style="color:#fff;margin:0 0 6px;font-size:22px">Pro License Expired</h2>';
        echo '<p style="margin:0 0 12px;font-size:14px;color:#fecaca">Your Pro license expired on <strong>' . esc_html( $exp_fmt ) . '</strong>. Pro features are disabled until you renew.</p>';
        echo '<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:14px;margin-bottom:16px">';
        echo '<span>Key: <code style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px">' . esc_html( get_option( 'sre_license_key', '' ) ) . '</code></span>';
        echo '</div>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
        echo '<a href="https://cao-tech.com/smart-reorder-emailer" target="_blank" class="button button-primary" style="padding:8px 28px;font-size:14px;background:#fff;color:#dc2626;border:none;font-weight:600">Renew License</a>';
        echo '<a href="mailto:info@cao-tech.com?subject=Smart%20Reorder%20Emailer%20Pro%20License%20Renewal&body=Hi%20Cao-Tech%20LLC%2C%0A%0AMy%20Pro%20license%20has%20expired.%20I%20would%20like%20to%20renew.%0A%0ASite%3A%20' . rawurlencode( home_url() ) . '%0AKey%3A%20' . rawurlencode( get_option( 'sre_license_key', '' ) ) . '%0A%0AThank%20you!" class="button" style="padding:8px 28px;font-size:14px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)">Contact Cao-Tech LLC</a>';
        echo '</div>';
        echo '</div>';

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
        echo '<h3 style="margin:0 0 8px;font-size:15px">Deactivate License</h3>';
        echo '<p style="margin:0 0 12px;color:#6b7280;font-size:13px">This will free up the site slot so you can use your license on a different site.</p>';
        echo '<form method="post">';
        wp_nonce_field( 'sre_pro_deactivate' );
        echo '<input type="hidden" name="sre_deactivate_license" value="1">';
        echo '<button type="submit" class="button" onclick="return confirm(\'Deactivate Pro on this site?\')">Deactivate License</button>';
        echo '</form>';
        echo '</div>';

    // STATE 4: No key — Upgrade page
    } elseif ( ! $has_key ) {
        echo '<div style="background:linear-gradient(135deg,#f8fafc 0%,#e2e8f0 100%);border:2px solid #2563eb;border-radius:12px;padding:28px 32px;margin:20px 0 24px">';
        echo '<h2 style="margin:0 0 8px;font-size:20px;color:#1e3a5f">Upgrade to Smart Reorder Emailer Pro</h2>';
        echo '<p style="margin:0 0 16px;color:#475569;font-size:14px">Unlock AI-powered emails, customer segmentation, predictive analytics, dynamic discount codes, A/B testing, and more.</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:16px">';
        $pro_features = array(
            'AI-Personalised Emails',
            'Customer Segmentation',
            'Predictive Reorder Dates',
            'Dynamic Discount Codes',
            'A/B Testing',
            'Risk Scoring & Churn Alerts',
            'Revenue Analytics',
            'Loyalty Program',
        );
        foreach ( $pro_features as $f ) {
            echo '<div style="display:flex;align-items:center;gap:6px;font-size:13px"><span style="color:#22c55e;font-weight:bold">✓</span> ' . esc_html( $f ) . '</div>';
        }
        echo '</div>';
        echo '<a href="https://cao-tech.com/smart-reorder-emailer" target="_blank" class="button button-primary" style="padding:8px 28px;font-size:14px">Get Pro License</a>';
        echo '</div>';

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
        echo '<h2 style="margin:0 0 6px">Request a Pro License</h2>';
        echo '<p style="margin:0 0 16px;color:#6b7280;font-size:13px">Fill in your details to request a Pro license. We will review your request and send your license key by email.</p>';
        echo '<form method="post">';
        wp_nonce_field( 'sre_pro_signup' );
        echo '<input type="hidden" name="sre_pro_signup_submit" value="1">';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">';
        echo '<div><label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Name</label>';
        echo '<input type="text" name="sre_signup_name" style="padding:8px 12px;border:2px solid #e5e7eb;border-radius:6px;font-size:14px;width:200px" value="' . esc_attr( wp_get_current_user()->display_name ) . '"></div>';
        echo '<div><label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Email</label>';
        echo '<input type="email" name="sre_signup_email" required style="padding:8px 12px;border:2px solid #e5e7eb;border-radius:6px;font-size:14px;width:260px" value="' . esc_attr( wp_get_current_user()->user_email ) . '"></div>';
        echo '<button type="submit" class="button button-primary" style="padding:8px 28px;font-size:14px">Request Pro License</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        echo '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
        echo '<h3 style="margin:0 0 6px;font-size:15px">Or Contact Cao-Tech LLC Directly</h3>';
        echo '<p style="margin:0 0 12px;color:#6b7280;font-size:13px">Prefer to reach out directly? Contact us by email or visit our website.</p>';
        echo '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">';
        echo '<a href="mailto:info@cao-tech.com?subject=Smart%20Reorder%20Emailer%20Pro%20License%20Request&body=Hi%20Cao-Tech%20LLC%2C%0A%0AI%20would%20like%20to%20request%20a%20Pro%20license%20for%20Smart%20Reorder%20Emailer.%0A%0ASite%3A%20' . rawurlencode( home_url() ) . '%0AName%3A%20' . rawurlencode( wp_get_current_user()->display_name ) . '%0AEmail%3A%20' . rawurlencode( wp_get_current_user()->user_email ) . '%0A%0AThank%20you!" class="button" style="padding:8px 28px;font-size:14px">Email Cao-Tech LLC</a>';
        echo '<a href="https://cao-tech.com/smart-reorder-emailer" target="_blank" class="button" style="padding:8px 28px;font-size:14px">Visit Cao-Tech.com</a>';
        echo '</div>';
        echo '<p style="margin:12px 0 0;color:#94a3b8;font-size:12px">Email: <a href="mailto:info@cao-tech.com">info@cao-tech.com</a> · Website: <a href="https://cao-tech.com" target="_blank">cao-tech.com</a></p>';
        echo '</div>';
    }

    // Always show key entry form.
    echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
    $key_heading = $has_key ? 'Change License Key' : 'Already Have a License Key?';
    $key_desc    = $has_key ? 'Replace with a different license key.' : 'Enter your activation code to unlock Pro instantly.';
    echo '<h2 style="margin:0 0 6px">' . esc_html( $key_heading ) . '</h2>';
    echo '<p style="margin:0 0 16px;color:#6b7280;font-size:13px">' . esc_html( $key_desc ) . '</p>';
    echo '<form method="post">';
    wp_nonce_field( 'sre_pro_activate' );
    echo '<div style="display:flex;gap:12px;align-items:center">';
    echo '<input type="text" name="sre_license_key" placeholder="SRP-2026-XXXX-XXXX-XXXX" style="flex:1;min-width:230px;padding:10px 14px;border:2px solid #e5e7eb;border-radius:6px;font-size:15px;font-family:monospace;letter-spacing:1px" value="' . esc_attr( get_option( 'sre_license_key', '' ) ) . '">';
    echo '<button type="submit" class="button button-primary" style="padding:10px 28px;font-size:14px">Activate</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    // License server info footer.
    echo '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;margin-top:8px">';
    echo '<p style="margin:0;font-size:12px;color:#94a3b8">License Server: <code>' . esc_html( sre_license_api_url() ) . '</code> · Product: <code>smart-reorder-emailer-pro</code></p>';
    echo '</div>';

    echo '</div>'; // .wrap
}

// ----------------------------------------------------------------
// FEATURE: EMAIL TRACKING
// ----------------------------------------------------------------
function sre_tracking_hmac( $data ) {
    $key = wp_salt( 'auth' );
    return hash_hmac( 'sha256', $data, $key );
}

/**
 * 5.2.5: persist the rendered subject + html body on the email_log row so
 * the orders-list "Source" badge can pop up the exact email the customer
 * received. Best-effort — silently no-ops if the columns are missing
 * (older installs that never re-ran the migration). Call AFTER
 * sre_add_tracking() so the stored body matches the wire copy.
 */
function sre_save_email_content( $email_log_id, $subject, $body ) {
    $email_log_id = (int) $email_log_id;
    if ( $email_log_id <= 0 ) {
        return;
    }
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'sre_email_log',
        array(
            'subject' => (string) $subject,
            'body'    => (string) $body,
        ),
        array( 'id' => $email_log_id ),
        array( '%s', '%s' ),
        array( '%d' )
    );
}

function sre_add_tracking( $email_log_id, $html_body ) {
    if ( ! $email_log_id ) {
        return $html_body;
    }
    $encoded_id = base64_encode( $email_log_id );
    $id_sig     = sre_tracking_hmac( 'id:' . $email_log_id );

    $html_body = preg_replace_callback(
        '/<a\s([^>]*?)href=["\']([^"\']+)["\']/i',
        function ( $matches ) use ( $encoded_id, $id_sig, $email_log_id ) {
            $url = $matches[2];
            if ( strpos( $url, 'sre_track' ) !== false ) {
                return $matches[0];
            }
            $encoded_url = base64_encode( $url );
            $url_sig     = sre_tracking_hmac( 'click:' . $email_log_id . ':' . $url );
            $track_url   = site_url( '?sre_track=click&id=' . urlencode( $encoded_id ) . '&sig=' . urlencode( $id_sig ) . '&url=' . urlencode( $encoded_url ) . '&usig=' . urlencode( $url_sig ) );
            return '<a ' . $matches[1] . 'href="' . esc_url( $track_url ) . '"';
        },
        $html_body
    );

    $pixel_url = site_url( '?sre_track=open&id=' . urlencode( $encoded_id ) . '&sig=' . urlencode( $id_sig ) );
    $pixel = '<img src="' . esc_url( $pixel_url ) . '" alt="" width="1" height="1" style="display:none;border:0" />';
    $html_body = str_replace( '</body>', $pixel . '</body>', $html_body );
    if ( strpos( $html_body, $pixel ) === false ) {
        $html_body .= $pixel;
    }
    return $html_body;
}

function sre_handle_tracking() {
    if ( ! isset( $_GET['sre_track'] ) ) {
        return;
    }
    $event = sanitize_text_field( $_GET['sre_track'] );
    if ( ! in_array( $event, array( 'open', 'click' ), true ) ) {
        return;
    }
    $encoded_id = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
    $log_id = (int) base64_decode( $encoded_id );
    if ( $log_id < 1 ) {
        return;
    }

    $sig = isset( $_GET['sig'] ) ? sanitize_text_field( $_GET['sig'] ) : '';
    if ( ! hash_equals( sre_tracking_hmac( 'id:' . $log_id ), $sig ) ) {
        return;
    }

    global $wpdb;
    $track_tbl = $wpdb->prefix . 'sre_email_tracking';
    $log_tbl   = $wpdb->prefix . 'sre_email_log';
    $ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
    $ua        = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : '';
    $url_val   = '';

    if ( $event === 'click' ) {
        $encoded_url = isset( $_GET['url'] ) ? sanitize_text_field( $_GET['url'] ) : '';
        $url_val = base64_decode( $encoded_url );
        if ( ! $url_val ) {
            return;
        }
        $usig = isset( $_GET['usig'] ) ? sanitize_text_field( $_GET['usig'] ) : '';
        if ( ! hash_equals( sre_tracking_hmac( 'click:' . $log_id . ':' . $url_val ), $usig ) ) {
            return;
        }
        $allowed_host = wp_parse_url( site_url(), PHP_URL_HOST );
        $redirect_host = wp_parse_url( $url_val, PHP_URL_HOST );
        if ( $redirect_host && strtolower( $redirect_host ) !== strtolower( $allowed_host ) ) {
            $url_val = site_url();
        }
        $wpdb->update( $log_tbl, array( 'clicked' => 1 ), array( 'id' => $log_id ), array( '%d' ), array( '%d' ) );
    } else {
        $already_opened = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$track_tbl} WHERE email_log_id=%d AND event_type='open' AND ip_address=%s",
            $log_id, $ip
        ) );
        if ( (int) $already_opened > 0 ) {
            header( 'Content-Type: image/gif' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            echo base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==' );
            exit;
        }
        $wpdb->update( $log_tbl, array( 'opened' => 1 ), array( 'id' => $log_id ), array( '%d' ), array( '%d' ) );
    }

    $wpdb->insert( $track_tbl, array(
        'email_log_id' => $log_id,
        'event_type'   => $event,
        'url'          => substr( $url_val, 0, 500 ),
        'ip_address'   => $ip,
        'user_agent'   => $ua,
        'created_at'   => current_time( 'mysql' ),
    ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );

    if ( $event === 'open' ) {
        header( 'Content-Type: image/gif' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        echo base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==' );
        exit;
    }

    if ( $event === 'click' && $url_val ) {
        wp_redirect( esc_url_raw( $url_val ) );
        exit;
    }
}

/**
 * 5.3.1: handle `?sre_unsub=1&e=…&sig=…` requests. Verifies the HMAC,
 * records the suppression, and renders a self-contained confirmation page
 * with a one-click resubscribe button. Honors Gmail's RFC 8058 One-Click
 * POST too (the List-Unsubscribe-Post header) — GET and POST both work.
 */
function sre_handle_unsubscribe() {
    if ( empty( $_REQUEST['sre_unsub'] ) ) {
        return;
    }
    $email = isset( $_REQUEST['e'] ) ? strtolower( trim( rawurldecode( wp_unslash( $_REQUEST['e'] ) ) ) ) : '';
    $sig   = isset( $_REQUEST['sig'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sig'] ) ) : '';
    $resub = ! empty( $_REQUEST['resub'] );

    if ( ! $email || ! is_email( $email ) || ! hash_equals( sre_unsub_signature( $email ), $sig ) ) {
        status_header( 400 );
        sre_render_unsub_page(
            'Link expired',
            'This unsubscribe link is not valid. It may have been tampered with, or it was generated for a different email address. If you keep receiving messages you do not want, reply to one of our emails and we will remove you manually.'
        );
        exit;
    }

    if ( $resub ) {
        sre_remove_unsubscribe( $email );
        sre_render_unsub_page(
            'You are subscribed again',
            sprintf( 'We have re-added <strong>%s</strong> to our mailing list. You will start receiving reminders again on the normal schedule.', esc_html( $email ) ),
            $email,
            'resubscribed'
        );
        exit;
    }

    sre_add_unsubscribe( $email, 'link', '' );

    // RFC 8058 one-click POSTs expect a 200 with no body. Respect that for
    // POST so Gmail / Yahoo do not surface a "unsubscribe failed" warning.
    if ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) === 'POST' ) {
        status_header( 200 );
        exit;
    }

    sre_render_unsub_page(
        'You have been unsubscribed',
        sprintf( 'We have removed <strong>%s</strong> from our reorder reminders. You will not hear from us again unless you change your mind.', esc_html( $email ) ),
        $email,
        'unsubscribed'
    );
    exit;
}

/**
 * 5.3.1: Backend "Unsubscribes" page. Lists every suppressed address with
 * source + date, supports paste-many bulk import via a POST form, and lets
 * the admin remove a row (re-enable email) inline. Also exposes a CSV
 * export of the full suppression list and an "Unsubscribe" action straight
 * from the WC orders/customer flow.
 */
function sre_page_unsubscribes() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Permission denied.' );
    }
    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_unsubscribes';

    // Bulk add via POSTed textarea.
    if ( ! empty( $_POST['sre_unsub_bulk'] ) && check_admin_referer( 'sre_unsub_bulk' ) ) {
        $raw    = (string) wp_unslash( $_POST['sre_unsub_bulk'] );
        $lines  = preg_split( '/[\s,;]+/', $raw );
        $added  = 0;
        $skipped = 0;
        foreach ( $lines as $line ) {
            $line = strtolower( trim( $line ) );
            if ( $line === '' ) {
                continue;
            }
            if ( ! is_email( $line ) ) {
                $skipped++;
                continue;
            }
            if ( sre_add_unsubscribe( $line, 'import', 'Bulk import' ) ) {
                $added++;
            }
        }
        echo '<div class="notice notice-success is-dismissible"><p>Imported <strong>' . (int) $added . '</strong> address' . ( $added === 1 ? '' : 'es' ) . '.';
        if ( $skipped > 0 ) {
            echo ' Skipped ' . (int) $skipped . ' invalid line' . ( $skipped === 1 ? '' : 's' ) . '.';
        }
        echo '</p></div>';
    }

    // CSV export.
    if ( isset( $_GET['export_csv'] ) && check_admin_referer( 'sre_unsub_export' ) ) {
        $rows = $wpdb->get_results( "SELECT customer_email, source, reason, created_at FROM {$tbl} ORDER BY created_at DESC" );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename=sre-unsubscribes-' . gmdate( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'Email', 'Source', 'Reason', 'Created' ) );
        foreach ( (array) $rows as $r ) {
            fputcsv( $out, array( $r->customer_email, $r->source, $r->reason, $r->created_at ) );
        }
        fclose( $out );
        exit;
    }

    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $where  = '1=1';
    if ( $search !== '' ) {
        $where .= $wpdb->prepare( ' AND customer_email LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
    }
    $per   = 50;
    $pg    = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE {$where}" );
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$tbl} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per, ( $pg - 1 ) * $per
    ) );
    $nonce = wp_create_nonce( 'sre_nonce' );

    sre_page_header( 'Unsubscribes' );
    sre_page_intro( 'Customers who have opted out of reorder reminders. The plugin will refuse to send to anyone on this list — including AI-personalised reorders, follow-ups, win-back sequences, and tier-upgrade emails. Customers can opt back in via the link in their confirmation email, or you can remove them manually below.' );

    $source_counts = $wpdb->get_results( "SELECT source, COUNT(*) AS cnt FROM {$tbl} GROUP BY source" );
    $by_source = array();
    foreach ( $source_counts as $sc ) {
        $by_source[ $sc->source ] = (int) $sc->cnt;
    }
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px">';
    sre_stat_card( 'Total suppressed', $total, '#dc2626', 'How many distinct addresses are currently blocked from receiving reorder emails.' );
    sre_stat_card( 'Customer link', isset( $by_source['link'] ) ? $by_source['link'] : 0, '#2563eb', 'Self-service unsubscribes via the email footer link.' );
    sre_stat_card( 'Admin removed', isset( $by_source['admin'] ) ? $by_source['admin'] : 0, '#7c3aed', 'Removed by a store admin from the WP dashboard.' );
    sre_stat_card( 'Bulk imported', isset( $by_source['import'] ) ? $by_source['import'] : 0, '#f59e0b', 'Pasted into the bulk-import textarea below — typically migrated from another ESP.' );
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:2fr 1fr;gap:18px;max-width:1100px">';

    // Left column: list table.
    echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<form method="get" style="display:flex;gap:8px;margin-bottom:14px;align-items:center">';
    echo '<input type="hidden" name="page" value="smart-reorder-unsubscribes">';
    echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search email..." class="regular-text">';
    echo '<button class="button">Search</button>';
    if ( $search !== '' ) {
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-unsubscribes' ) ) . '">Clear</a>';
    }
    $export_url = wp_nonce_url( admin_url( 'admin.php?page=smart-reorder-unsubscribes&export_csv=1' ), 'sre_unsub_export' );
    echo '<span style="flex:1"></span>';
    echo '<a class="button" href="' . esc_url( $export_url ) . '">Export CSV</a>';
    echo '</form>';

    if ( empty( $rows ) ) {
        echo '<p style="color:#9ca3af;margin:18px 0">No suppressed addresses' . ( $search !== '' ? ' match that search.' : ' yet — every customer is opted in.' ) . '</p>';
    } else {
        echo '<table class="wp-list-table widefat striped" style="font-size:13px">';
        echo '<thead><tr><th>Email</th><th style="width:120px">Source</th><th style="width:170px">Suppressed</th><th style="width:90px">Action</th></tr></thead><tbody>';
        $src_labels = array(
            'link'   => '<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">Customer link</span>',
            'admin'  => '<span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">Admin</span>',
            'import' => '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">Bulk import</span>',
            'mailto' => '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">Mailto reply</span>',
            'bounce' => '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">Bounce</span>',
        );
        foreach ( $rows as $r ) {
            $label = isset( $src_labels[ $r->source ] )
                ? $src_labels[ $r->source ]
                : '<span style="color:#6b7280;font-size:11px">' . esc_html( $r->source ) . '</span>';
            $ago = $r->created_at && $r->created_at !== '0000-00-00 00:00:00'
                ? human_time_diff( strtotime( $r->created_at ), time() ) . ' ago'
                : '—';
            echo '<tr data-email="' . esc_attr( $r->customer_email ) . '">';
            echo '<td><code style="font-size:12px">' . esc_html( $r->customer_email ) . '</code>';
            if ( $r->reason !== '' ) {
                echo '<div style="font-size:11px;color:#9ca3af;margin-top:2px">' . esc_html( $r->reason ) . '</div>';
            }
            echo '</td>';
            echo '<td>' . $label . '</td>';
            echo '<td><span title="' . esc_attr( $r->created_at ) . '">' . esc_html( $ago ) . '</span></td>';
            echo '<td><button class="button button-small sre-unsub-remove" data-email="' . esc_attr( $r->customer_email ) . '">Re-enable</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        $pages = (int) ceil( $total / $per );
        if ( $pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            $base = remove_query_arg( 'paged' );
            for ( $i = 1; $i <= $pages; $i++ ) {
                $is = $i === $pg;
                $url = esc_url( add_query_arg( 'paged', $i, $base ) );
                echo $is
                    ? '<span class="button button-primary" style="margin-right:4px">' . $i . '</span>'
                    : '<a class="button" style="margin-right:4px" href="' . $url . '">' . $i . '</a>';
            }
            echo '</div></div>';
        }
    }
    echo '</div>';

    // Right column: add + bulk import.
    echo '<div style="display:flex;flex-direction:column;gap:18px">';

    echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h2 style="margin:0 0 8px;font-size:15px">Add a single address</h2>';
    echo '<p style="margin:0 0 12px;font-size:12px;color:#6b7280">Suppress one customer manually. Useful when someone calls or emails to opt out.</p>';
    echo '<div style="display:flex;gap:6px">';
    echo '<input type="email" id="sre-unsub-single" placeholder="customer@example.com" class="regular-text" style="flex:1">';
    echo '<button class="button button-primary" id="sre-unsub-single-btn">Suppress</button>';
    echo '</div>';
    echo '<div id="sre-unsub-single-msg" style="margin-top:8px;font-size:12px"></div>';
    echo '</div>';

    echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h2 style="margin:0 0 8px;font-size:15px">Bulk import</h2>';
    echo '<p style="margin:0 0 10px;font-size:12px;color:#6b7280">Paste one email per line (commas, semicolons, and whitespace also work). Existing rows are skipped.</p>';
    echo '<form method="post">';
    wp_nonce_field( 'sre_unsub_bulk' );
    echo '<textarea name="sre_unsub_bulk" rows="8" style="width:100%;font-family:monospace;font-size:12px" placeholder="user1@example.com&#10;user2@example.com&#10;user3@example.com"></textarea>';
    echo '<p style="margin:10px 0 0;text-align:right"><button class="button button-primary">Import &amp; suppress</button></p>';
    echo '</form>';
    echo '</div>';

    echo '</div>'; // right column
    echo '</div>'; // grid

    // Inline JS for the single-add + remove buttons.
    echo '<script>jQuery(function($){';
    echo 'var n="' . esc_js( $nonce ) . '";';
    echo '$("#sre-unsub-single-btn").on("click",function(){';
    echo 'var em=$("#sre-unsub-single").val(),msg=$("#sre-unsub-single-msg");';
    echo 'if(!em){msg.html("<span style=\"color:#991b1b\">Enter an email.</span>");return;}';
    echo '$(this).prop("disabled",true);';
    echo '$.post(ajaxurl,{action:"sre_unsub_add",nonce:n,email:em},function(r){';
    echo 'if(r.success){msg.html("<span style=\"color:#166534\">"+r.data+"</span>");setTimeout(function(){location.reload();},800);}';
    echo 'else{msg.html("<span style=\"color:#991b1b\">"+r.data+"</span>");$("#sre-unsub-single-btn").prop("disabled",false);}';
    echo '});});';
    echo '$(document).on("click",".sre-unsub-remove",function(){';
    echo 'var b=$(this),em=b.data("email");';
    echo 'if(!confirm("Re-enable emails for "+em+"?"))return;';
    echo 'b.prop("disabled",true).text("...");';
    echo '$.post(ajaxurl,{action:"sre_unsub_remove",nonce:n,email:em},function(r){';
    echo 'if(r.success){b.closest("tr").fadeOut();}else{b.prop("disabled",false).text("Re-enable");alert(r.data||"Failed.");}';
    echo '});});';
    echo '});</script>';

    echo '</div>'; // .wrap from sre_page_header
}

/**
 * AJAX: admin-triggered suppression. Adds a row with source='admin'.
 */
function sre_ajax_unsub_add() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $email = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
    if ( ! $email || ! is_email( $email ) ) {
        wp_send_json_error( 'Enter a valid email address.' );
    }
    if ( sre_is_unsubscribed( $email ) ) {
        wp_send_json_error( 'That address is already suppressed.' );
    }
    if ( sre_add_unsubscribe( $email, 'admin', 'Suppressed from dashboard' ) ) {
        wp_send_json_success( $email . ' suppressed.' );
    }
    wp_send_json_error( 'Could not suppress that address.' );
}

/**
 * AJAX: admin-triggered re-enable. Removes the suppression row.
 */
function sre_ajax_unsub_remove() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $email = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
    if ( ! $email ) {
        wp_send_json_error( 'Missing email.' );
    }
    if ( sre_remove_unsubscribe( $email ) ) {
        wp_send_json_success( $email . ' re-enabled.' );
    }
    wp_send_json_error( 'Address was not on the suppression list.' );
}

/**
 * Render a tiny standalone HTML page for the unsubscribe / resubscribe
 * confirmation. Deliberately self-contained — does not load the theme so
 * customers see something legible even on broken templates.
 */
function sre_render_unsub_page( $title, $body_html, $email = '', $state = '' ) {
    $site   = esc_html( get_bloginfo( 'name' ) );
    $home   = esc_url( home_url( '/' ) );
    $action = '';
    if ( $email && $state === 'unsubscribed' ) {
        $resub_url = add_query_arg( array(
            'sre_unsub' => '1',
            'e'         => rawurlencode( $email ),
            'sig'       => sre_unsub_signature( $email ),
            'resub'     => '1',
        ), home_url( '/' ) );
        $action = '<p style="margin:18px 0 0;font-size:14px"><a href="' . esc_url( $resub_url ) . '" style="display:inline-block;padding:10px 22px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600">Subscribe again</a></p>';
    } elseif ( $email && $state === 'resubscribed' ) {
        $unsub_url = sre_unsub_url( $email );
        $action = '<p style="margin:18px 0 0;font-size:13px;color:#6b7280">Changed your mind? <a href="' . esc_url( $unsub_url ) . '" style="color:#2563eb">Unsubscribe again</a>.</p>';
    }

    nocache_headers();
    header( 'Content-Type: text/html; charset=UTF-8' );
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . esc_html( $title ) . ' — ' . $site . '</title>';
    echo '<style>body{margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2937}.w{max-width:540px;margin:80px auto;padding:0 20px}.c{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:36px 32px;text-align:center}.c h1{margin:0 0 14px;font-size:22px;color:#111827}.c p{margin:0 0 10px;font-size:15px;line-height:1.6;color:#374151}.c .s{margin-top:24px;font-size:12px;color:#9ca3af}.c .s a{color:#6b7280;text-decoration:underline}</style>';
    echo '</head><body><div class="w"><div class="c">';
    echo '<h1>' . esc_html( $title ) . '</h1>';
    echo '<p>' . wp_kses( $body_html, array( 'strong' => array(), 'a' => array( 'href' => true ) ) ) . '</p>';
    echo $action;
    echo '<p class="s">&mdash; ' . $site . ' &middot; <a href="' . $home . '">Return to site</a></p>';
    echo '</div></div></body></html>';
}

function sre_attribute_revenue( $order_id ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    $email = $order->get_billing_email();
    if ( ! $email || ! is_email( $email ) ) {
        return;
    }

    // 5.1.0: tightened attribution. Previously credited any email sent within
    // 30 days regardless of engagement, which inflated revenue numbers
    // dramatically. We now require an actual signal — coupon used, click, or
    // matching product — and pick the strongest match.
    $coupons = $order->get_coupon_codes();
    $product_ids = array();
    foreach ( $order->get_items() as $item ) {
        if ( method_exists( $item, 'get_product_id' ) ) {
            $pid = (int) $item->get_product_id();
            if ( $pid > 0 ) {
                $product_ids[] = $pid;
            }
        }
    }
    $product_ids = array_values( array_unique( $product_ids ) );

    global $wpdb;
    $cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
    $log_row = sre_find_attribution_log( $email, $cutoff, $coupons, $product_ids );

    if ( $log_row ) {
        $lt = $wpdb->prefix . 'sre_email_log';
        $wpdb->update( $lt, array(
            'converted' => 1,
            'revenue'   => (float) $order->get_total(),
        ), array( 'id' => $log_row->id ), array( '%d', '%f' ), array( '%d' ) );
        // 5.2.0: stamp the order so the WC order-edit screen can show a
        // "Came from reorder email" badge + loyalty status without having
        // to re-run the attribution cascade.
        // 5.2.4: also stamp _sre_attributed_email_type (denormalized from
        // the sre_email_log row) so the orders-list Source filter can
        // match on type without joining sre_email_log per row.
        update_post_meta( $order_id, '_sre_attributed_email_id', (int) $log_row->id );
        $row_type = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT email_type FROM {$lt} WHERE id=%d LIMIT 1",
            (int) $log_row->id
        ) );
        if ( $row_type !== '' ) {
            update_post_meta( $order_id, '_sre_attributed_email_type', $row_type );
        }
    }

    // 5.3.1: Track which SRE coupon codes were actually redeemed on this order
    // so the orders list and meta box can show coupon usage at a glance.
    if ( ! empty( $coupons ) ) {
        $disc_tbl   = $wpdb->prefix . 'sre_discounts';
        $sre_codes  = array();
        $sre_savings = 0;
        foreach ( $coupons as $code ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$disc_tbl} SET used_count = used_count + 1 WHERE code=%s",
                $code
            ) );
            // Check if this coupon is an SRE-generated one
            $sre_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT code, discount_type, amount, segment FROM {$disc_tbl} WHERE code=%s LIMIT 1",
                $code
            ) );
            if ( $sre_row ) {
                $sre_codes[] = $sre_row->code;
                // Calculate actual savings from WC coupon on this order
                foreach ( $order->get_coupon_codes() as $oc ) {
                    if ( strtolower( $oc ) === strtolower( $sre_row->code ) ) {
                        foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
                            if ( strtolower( $coupon_item->get_code() ) === strtolower( $sre_row->code ) ) {
                                $sre_savings += (float) $coupon_item->get_discount();
                            }
                        }
                    }
                }
            }
        }
        if ( ! empty( $sre_codes ) ) {
            update_post_meta( $order_id, '_sre_coupon_code', implode( ', ', $sre_codes ) );
            update_post_meta( $order_id, '_sre_coupon_savings', $sre_savings );
        }
    }
}

/**
 * Find the most credit-worthy email log row for this order.
 *
 * Priority cascade (most specific → least specific):
 *   1. Coupon-code match: customer used a discount the email gave them.
 *   2. Click + matching product_id: engaged AND bought what we promoted.
 *   3. Click alone: engaged, presume influence.
 *   4. Matching product_id without click: bought what we promoted.
 *
 * Logs already marked converted=1 are excluded so the same email is not
 * credited twice across multiple orders.
 *
 * @return object|null Log row with `id`, or null if no signal found.
 */
function sre_find_attribution_log( $email, $cutoff, $coupons, $product_ids ) {
    global $wpdb;
    $lt = $wpdb->prefix . 'sre_email_log';

    // 1. Coupon match.
    if ( ! empty( $coupons ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $coupons ), '%s' ) );
        $args = array_merge( $coupons, array( $email, $cutoff ) );
        $row  = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$lt} WHERE discount_code IN ($placeholders) AND customer_email=%s AND sent_date >= %s AND converted=0 ORDER BY sent_date DESC LIMIT 1",
            $args
        ) );
        if ( $row ) {
            return $row;
        }
    }

    // Build product_id IN list (ints — absint sanitizes for inline use).
    $pid_in = '';
    if ( ! empty( $product_ids ) ) {
        $pid_in = implode( ',', array_map( 'absint', $product_ids ) );
    }

    // 2. Click + product match.
    if ( $pid_in !== '' ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$lt} WHERE clicked=1 AND product_id IN ($pid_in) AND customer_email=%s AND sent_date >= %s AND converted=0 ORDER BY sent_date DESC LIMIT 1",
            $email, $cutoff
        ) );
        if ( $row ) {
            return $row;
        }
    }

    // 3. Click alone.
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM {$lt} WHERE clicked=1 AND customer_email=%s AND sent_date >= %s AND converted=0 ORDER BY sent_date DESC LIMIT 1",
        $email, $cutoff
    ) );
    if ( $row ) {
        return $row;
    }

    // 4. Product match without click.
    if ( $pid_in !== '' ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$lt} WHERE product_id IN ($pid_in) AND customer_email=%s AND sent_date >= %s AND converted=0 ORDER BY sent_date DESC LIMIT 1",
            $email, $cutoff
        ) );
        if ( $row ) {
            return $row;
        }
    }

    return null;
}

// ----------------------------------------------------------------
// FEATURE: WIN-BACK CAMPAIGNS
// ----------------------------------------------------------------
function sre_process_winback_campaigns() {
    if ( ! sre_is_pro() ) {
        return;
    }
    global $wpdb;
    $camp_tbl  = $wpdb->prefix . 'sre_winback_campaigns';
    $queue_tbl = $wpdb->prefix . 'sre_winback_queue';
    $seg_tbl   = $wpdb->prefix . 'sre_segments';
    $lt        = $wpdb->prefix . 'sre_email_log';

    $campaigns = $wpdb->get_results( "SELECT * FROM {$camp_tbl} WHERE status='active'" );
    if ( empty( $campaigns ) ) {
        return;
    }

    foreach ( $campaigns as $camp ) {
        $customers = $wpdb->get_results( $wpdb->prepare(
            "SELECT customer_email FROM {$seg_tbl}
             WHERE segment=%s
             AND customer_email NOT IN (SELECT customer_email FROM {$queue_tbl} WHERE campaign_id=%d)
             LIMIT 100",
            $camp->target_segment, $camp->id
        ) );
        // 5.1.0: respect step1_days when enqueuing. Previously this was
        // hard-coded to current_time(), so the "Initial email" of every
        // campaign fired the moment the daily cron picked up the row even
        // when the admin set a non-zero delay.
        $step1_days = max( 0, (int) $camp->step1_days );
        $next_send  = $step1_days > 0
            ? gmdate( 'Y-m-d H:i:s', strtotime( "+{$step1_days} days" ) )
            : current_time( 'mysql' );
        foreach ( $customers as $c ) {
            $wpdb->insert( $queue_tbl, array(
                'campaign_id'    => $camp->id,
                'customer_email' => $c->customer_email,
                'current_step'   => 1,
                'next_send_date' => $next_send,
                'status'         => 'pending',
                'created_at'     => current_time( 'mysql' ),
            ), array( '%d', '%s', '%d', '%s', '%s', '%s' ) );
        }

        $now = current_time( 'mysql' );
        $queue_items = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.*, c.step1_subject, c.step1_discount, c.step2_subject, c.step2_discount,
                    c.step2_days, c.step3_subject, c.step3_discount, c.step3_days
             FROM {$queue_tbl} q
             JOIN {$camp_tbl} c ON q.campaign_id=c.id
             WHERE q.campaign_id=%d AND q.status='pending' AND q.next_send_date <= %s
             LIMIT 50",
            $camp->id, $now
        ) );

        foreach ( $queue_items as $qi ) {
            // 5.3.1: drop the queue row for unsubscribed customers so the
            // sequence does not keep retrying them every day.
            if ( sre_is_unsubscribed( $qi->customer_email ) ) {
                $wpdb->update( $queue_tbl, array( 'status' => 'unsubscribed' ), array( 'id' => $qi->id ), array( '%s' ), array( '%d' ) );
                continue;
            }
            $step = (int) $qi->current_step;
            $subject_key = 'step' . $step . '_subject';
            $discount_key = 'step' . $step . '_discount';
            $subject = $qi->$subject_key;
            $discount_pct = (int) $qi->$discount_key;

            $name = sre_get_name( $qi->customer_email );
            $subject = str_replace( '{customer_name}', $name, $subject );

            $discount_code = '';
            if ( $discount_pct > 0 ) {
                $disc = sre_generate_discount( $qi->customer_email, 'winback' );
                if ( $disc ) {
                    $discount_code = $disc['code'];
                }
            }

            $body = '<h2>Hi ' . esc_html( $name ) . '!</h2>';
            $body .= '<p>We miss you! It has been a while since your last visit. We would love to welcome you back.</p>';
            if ( $discount_code ) {
                $body .= '<div style="background:#eff6ff;border:2px dashed #2563eb;border-radius:8px;padding:16px;margin:18px 0;text-align:center">';
                $body .= '<p style="margin:0 0 6px;font-size:13px;color:#1e40af;font-weight:600">Welcome back offer</p>';
                $body .= '<p style="margin:0;font-size:24px;font-weight:800;color:#2563eb;letter-spacing:2px">' . esc_html( $discount_code ) . '</p>';
                $body .= '<p style="margin:6px 0 0;font-size:12px;color:#6b7280">' . $discount_pct . '% off your next order</p>';
                $body .= '</div>';
            }
            $body .= '<p><a href="' . esc_url( home_url( '/shop/' ) ) . '" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600">Shop Now</a></p>';
            $wrapped = sre_email_wrap( $body );

            $wpdb->insert( $lt, array(
                'customer_email' => $qi->customer_email,
                'product_id'     => 0,
                'email_type'     => 'winback_step' . $step,
                'sent_date'      => current_time( 'mysql' ),
                'discount_code'  => $discount_code,
            ), array( '%s', '%d', '%s', '%s', '%s' ) );
            $log_id = $wpdb->insert_id;
            $wrapped = sre_add_tracking( $log_id, $wrapped );
            sre_save_email_content( $log_id, $subject, $wrapped );

            $sent = sre_safe_mail( $qi->customer_email, $subject, $wrapped, 'winback' );
            if ( ! $sent ) {
                $wpdb->delete( $lt, array( 'id' => $log_id ), array( '%d' ) );
            }

            if ( $step < 3 ) {
                $next_days_key = 'step' . ( $step + 1 ) . '_days';
                $next_days = (int) $qi->$next_days_key;
                $next_date = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $next_days . ' days' ) );
                $wpdb->update( $queue_tbl, array(
                    'current_step'   => $step + 1,
                    'next_send_date' => $next_date,
                ), array( 'id' => $qi->id ), array( '%d', '%s' ), array( '%d' ) );
            } else {
                $wpdb->update( $queue_tbl, array( 'status' => 'completed' ), array( 'id' => $qi->id ), array( '%s' ), array( '%d' ) );
            }

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$camp_tbl} SET emails_sent = emails_sent + 1 WHERE id=%d",
                $camp->id
            ) );
        }
    }
}

function sre_page_winback() {
    if ( ! sre_is_pro() ) {
        wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) );
        exit;
    }
    global $wpdb;
    $camp_tbl  = $wpdb->prefix . 'sre_winback_campaigns';
    $queue_tbl = $wpdb->prefix . 'sre_winback_queue';
    $nonce     = wp_create_nonce( 'sre_nonce' );

    $campaigns = $wpdb->get_results( "SELECT * FROM {$camp_tbl} ORDER BY created_at DESC" );

    sre_page_header( 'Win-Back Campaigns' );
    sre_page_intro( 'Multi-step email sequences for customers who have stopped buying. Each campaign targets a segment (At Risk or Churned) and walks them through 3 escalating offers over a configurable schedule.' );
    sre_explainer(
        'How a 3-step sequence works',
        '<strong>Step 1</strong> sends after the "Delay before sending" you set on creation (default 0 days = next daily cron — fixed in 5.1.0, was previously hard-coded).<br>'
        . '<strong>Step 2</strong> sends N days after Step 1 if the customer has not converted.<br>'
        . '<strong>Step 3</strong> is the last attempt with the biggest discount.<br><br>'
        . 'A customer who places an order at any point is removed from the queue and immediately re-segmented out of churn (5.1.0).'
    );

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">';

    echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h3 style="margin:0 0 14px">Active Campaigns</h3>';
    if ( empty( $campaigns ) ) {
        echo '<p style="color:#9ca3af">No campaigns yet. Create one below.</p>';
    } else {
        echo '<table class="wp-list-table widefat striped" style="font-size:13px"><thead><tr>';
        echo '<th>Name</th><th>Segment</th><th>Sent</th><th>Conv</th><th>Revenue</th><th>Status</th><th>Action</th>';
        echo '</tr></thead><tbody>';
        foreach ( $campaigns as $c ) {
            $st_color = $c->status === 'active' ? '#059669' : '#d97706';
            echo '<tr>';
            echo '<td>' . esc_html( $c->campaign_name ) . '</td>';
            echo '<td>' . sre_segment_badge( $c->target_segment ) . '</td>';
            echo '<td>' . (int) $c->emails_sent . '</td>';
            echo '<td>' . (int) $c->conversions . '</td>';
            echo '<td>' . sre_money( $c->revenue, 2 ) . '</td>';
            echo '<td><span style="padding:2px 8px;border-radius:10px;font-size:11px;background:' . $st_color . ';color:#fff">' . esc_html( $c->status ) . '</span></td>';
            echo '<td><button class="button sre-toggle-wb" data-id="' . (int) $c->id . '">' . ( $c->status === 'active' ? 'Pause' : 'Resume' ) . '</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<h3 style="margin:0 0 6px">Create New Campaign</h3>';
    echo '<p style="color:#6b7280;font-size:13px;margin:0 0 14px">Win-back campaigns automatically send a series of emails with escalating discounts to customers who haven\'t purchased recently. Each step is sent on a schedule to encourage them to return.</p>';
    echo '<div style="display:flex;flex-direction:column;gap:10px" id="sre-wb-form">';
    echo '<div>';
    echo '<label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">Campaign Name</label>';
    echo '<input type="text" id="wb-name" placeholder="e.g. Holiday Win-Back 2025" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px">';
    echo '</div>';
    echo '<div>';
    echo '<label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">Target Customers</label>';
    echo '<select id="wb-segment" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:4px"><option value="at_risk">At Risk &mdash; inactive but still recoverable</option><option value="churned" selected>Churned &mdash; haven\'t ordered in 180+ days</option></select>';
    echo '</div>';
    $step_descs = array(
        1 => 'First email in the sequence. Set the delay below to wait N days before sending (0 = send on next daily cron).',
        2 => 'Follow-up for customers who didn\'t respond to Step 1. A stronger offer.',
        3 => 'Final attempt with the biggest discount to win them back.',
    );
    $step_placeholders = array(
        1 => 'e.g. We miss you! Here\'s 5% off your next order',
        2 => 'e.g. Still thinking about it? 10% off just for you',
        3 => 'e.g. Last chance! 15% off expires soon',
    );
    $steps = array( 1, 2, 3 );
    foreach ( $steps as $s ) {
        echo '<div style="background:#f9fafb;padding:12px;border-radius:4px;border:1px solid #e5e7eb">';
        echo '<p style="margin:0 0 2px;font-weight:600;font-size:13px;color:#111827">Step ' . $s . ( $s === 1 ? ' (Initial Email)' : ( $s === 2 ? ' (Follow-Up)' : ' (Final Offer)' ) ) . '</p>';
        echo '<p style="margin:0 0 8px;font-size:12px;color:#6b7280">' . $step_descs[ $s ] . '</p>';
        echo '<label style="display:block;font-size:11px;color:#6b7280;margin-bottom:2px">Email Subject Line</label>';
        echo '<input type="text" id="wb-s' . $s . '-subj" placeholder="' . esc_attr( $step_placeholders[ $s ] ) . '" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;margin-bottom:6px">';
        echo '<div style="display:flex;gap:8px;align-items:center">';
        echo '<div><label style="display:block;font-size:11px;color:#6b7280;margin-bottom:2px">Discount %</label>';
        echo '<input type="number" id="wb-s' . $s . '-disc" placeholder="%" min="0" max="100" value="' . ( $s * 5 ) . '" style="width:80px;padding:6px 10px;border:1px solid #d1d5db;border-radius:4px"></div>';
        if ( $s === 1 ) {
            echo '<div><label style="display:block;font-size:11px;color:#6b7280;margin-bottom:2px">Delay before sending</label>';
            echo '<input type="number" id="wb-s1-days" placeholder="Days" min="0" value="0" style="width:120px;padding:6px 10px;border:1px solid #d1d5db;border-radius:4px"></div>';
        } else {
            echo '<div><label style="display:block;font-size:11px;color:#6b7280;margin-bottom:2px">Days after previous step</label>';
            echo '<input type="number" id="wb-s' . $s . '-days" placeholder="Days" min="1" value="7" style="width:120px;padding:6px 10px;border:1px solid #d1d5db;border-radius:4px"></div>';
        }
        echo '</div></div>';
    }
    echo '<button class="button button-primary" id="sre-create-wb">Create Campaign</button>';
    echo '<div id="sre-wb-msg"></div>';
    echo '</div></div>';

    echo '</div>';

    $queue = $wpdb->get_results( "SELECT q.*, c.campaign_name FROM {$queue_tbl} q JOIN {$camp_tbl} c ON q.campaign_id=c.id ORDER BY q.next_send_date DESC LIMIT 50" );
    if ( ! empty( $queue ) ) {
        echo '<div style="background:#fff;border-radius:8px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:18px">';
        echo '<h3 style="margin:0 0 14px">Queue</h3>';
        echo '<table class="wp-list-table widefat striped" style="font-size:13px"><thead><tr>';
        echo '<th>Campaign</th><th>Email</th><th>Step</th><th>Next Send</th><th>Status</th>';
        echo '</tr></thead><tbody>';
        foreach ( $queue as $q ) {
            echo '<tr>';
            echo '<td>' . esc_html( $q->campaign_name ) . '</td>';
            echo '<td>' . esc_html( $q->customer_email ) . '</td>';
            echo '<td>' . (int) $q->current_step . '/3</td>';
            echo '<td>' . esc_html( $q->next_send_date ) . '</td>';
            echo '<td>' . esc_html( $q->status ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '</div>';

    echo '<script>jQuery(function($){';
    echo 'var n="' . esc_js( $nonce ) . '";';
    echo '$("#sre-create-wb").on("click",function(){';
    echo 'var b=$(this);b.prop("disabled",true).text("Creating...");';
    echo '$.post(ajaxurl,{action:"sre_create_winback",nonce:n,';
    echo 'name:$("#wb-name").val(),segment:$("#wb-segment").val(),';
    echo 's1_subj:$("#wb-s1-subj").val(),s1_disc:$("#wb-s1-disc").val(),s1_days:$("#wb-s1-days").val(),';
    echo 's2_subj:$("#wb-s2-subj").val(),s2_disc:$("#wb-s2-disc").val(),s2_days:$("#wb-s2-days").val(),';
    echo 's3_subj:$("#wb-s3-subj").val(),s3_disc:$("#wb-s3-disc").val(),s3_days:$("#wb-s3-days").val()';
    echo '},function(r){';
    echo 'var ok=r.success;';
    echo '$("#sre-wb-msg").html("<div style=\"padding:8px 14px;border-radius:6px;font-size:13px;background:"+(ok?"#dcfce7":"#fee2e2")+";color:"+(ok?"#166534":"#991b1b")+"\">"+r.data+"</div>");';
    echo 'b.prop("disabled",false).text("Create Campaign");';
    echo 'if(ok)setTimeout(function(){location.reload();},1500);';
    echo '});});';
    echo '$(".sre-toggle-wb").on("click",function(){';
    echo 'var b=$(this),id=b.data("id");b.prop("disabled",true);';
    echo '$.post(ajaxurl,{action:"sre_toggle_winback",nonce:n,campaign_id:id},function(r){';
    echo 'if(r.success)location.reload();else b.prop("disabled",false);';
    echo '});});';
    echo '});</script>';
}

function sre_ajax_create_winback() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $name = sanitize_text_field( isset( $_POST['name'] ) ? $_POST['name'] : '' );
    if ( empty( $name ) ) {
        wp_send_json_error( 'Campaign name is required.' );
    }
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'sre_winback_campaigns', array(
        'campaign_name'  => $name,
        'target_segment' => sanitize_text_field( isset( $_POST['segment'] ) ? $_POST['segment'] : 'churned' ),
        'step1_days'     => max( 0, (int) ( isset( $_POST['s1_days'] ) ? $_POST['s1_days'] : 0 ) ),
        'step1_subject'  => sanitize_text_field( isset( $_POST['s1_subj'] ) ? $_POST['s1_subj'] : '' ),
        'step1_discount' => (int) ( isset( $_POST['s1_disc'] ) ? $_POST['s1_disc'] : 5 ),
        'step2_days'     => max( 1, (int) ( isset( $_POST['s2_days'] ) ? $_POST['s2_days'] : 7 ) ),
        'step2_subject'  => sanitize_text_field( isset( $_POST['s2_subj'] ) ? $_POST['s2_subj'] : '' ),
        'step2_discount' => (int) ( isset( $_POST['s2_disc'] ) ? $_POST['s2_disc'] : 10 ),
        'step3_days'     => max( 1, (int) ( isset( $_POST['s3_days'] ) ? $_POST['s3_days'] : 14 ) ),
        'step3_subject'  => sanitize_text_field( isset( $_POST['s3_subj'] ) ? $_POST['s3_subj'] : '' ),
        'step3_discount' => (int) ( isset( $_POST['s3_disc'] ) ? $_POST['s3_disc'] : 15 ),
        'status'         => 'active',
        'created_at'     => current_time( 'mysql' ),
    ), array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s' ) );
    wp_send_json_success( 'Campaign "' . esc_html( $name ) . '" created.' );
}

function sre_ajax_toggle_winback() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $id = (int) ( isset( $_POST['campaign_id'] ) ? $_POST['campaign_id'] : 0 );
    if ( ! $id ) {
        wp_send_json_error( 'Missing campaign ID.' );
    }
    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_winback_campaigns';
    $current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tbl} WHERE id=%d", $id ) );
    $new_status = $current === 'active' ? 'paused' : 'active';
    $wpdb->update( $tbl, array( 'status' => $new_status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
    wp_send_json_success( 'Campaign ' . $new_status . '.' );
}

function sre_ajax_customer_detail() {
    check_ajax_referer( 'sre_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
    if ( ! $email ) {
        wp_send_json_error( 'Missing email.' );
    }

    global $wpdb;
    $seg_tbl = $wpdb->prefix . 'sre_segments';
    $seg     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$seg_tbl} WHERE customer_email=%s LIMIT 1", $email ) );

    $detail = array(
        'email'          => $email,
        'name'           => '',
        'username'       => '',
        'sign_up'        => '',
        'last_active'    => '',
        'city'           => '',
        'state'          => '',
        'postcode'       => '',
        'country'        => '',
        'phone'          => '',
        'orders'         => 0,
        'total_spend'    => 0,
        'aov'            => 0,
        'user_id'        => 0,
        'edit_url'       => '',
        'orders_url'     => '',
        'recent_orders'  => array(),
    );

    $user = get_user_by( 'email', $email );
    if ( $user ) {
        $detail['user_id']    = $user->ID;
        $detail['name']       = trim( $user->first_name . ' ' . $user->last_name );
        if ( ! $detail['name'] ) {
            $detail['name'] = $user->display_name;
        }
        $detail['username']   = $user->user_login;
        $detail['sign_up']    = $user->user_registered ? wp_date( 'M j, Y', strtotime( $user->user_registered ) ) : '';
        $detail['city']       = get_user_meta( $user->ID, 'billing_city', true );
        $detail['state']      = get_user_meta( $user->ID, 'billing_state', true );
        $detail['postcode']   = get_user_meta( $user->ID, 'billing_postcode', true );
        $detail['country']    = get_user_meta( $user->ID, 'billing_country', true );
        $detail['phone']      = get_user_meta( $user->ID, 'billing_phone', true );
        $detail['edit_url']   = admin_url( 'user-edit.php?user_id=' . $user->ID );
    }

    if ( function_exists( 'wc_get_orders' ) ) {
        $orders = wc_get_orders( array(
            'customer' => $email,
            'status'   => array( 'wc-completed', 'wc-processing', 'completed', 'processing' ),
            'limit'    => -1,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'objects',
        ) );

        $detail['orders'] = count( $orders );
        $total = 0;
        $last_date = '';
        $recent = array();
        foreach ( $orders as $i => $o ) {
            $total += (float) $o->get_total();
            $odate = $o->get_date_created();
            if ( $i === 0 && $odate ) {
                $last_date = $odate->date( 'M j, Y' );
            }
            if ( $i < 10 ) {
                $items_list = array();
                foreach ( $o->get_items() as $item ) {
                    $items_list[] = $item->get_name() . ' x' . $item->get_quantity();
                }
                $recent[] = array(
                    'id'     => $o->get_id(),
                    'date'   => $odate ? $odate->date( 'M j, Y' ) : '',
                    'total'  => number_format( (float) $o->get_total(), 2 ),
                    'status' => wc_get_order_status_name( $o->get_status() ),
                    'items'  => implode( ', ', $items_list ),
                    'url'    => admin_url( 'post.php?post=' . $o->get_id() . '&action=edit' ),
                );
            }
        }
        $detail['total_spend']   = number_format( $total, 2 );
        $detail['aov']           = $detail['orders'] > 0 ? number_format( $total / $detail['orders'], 2 ) : '0.00';
        $detail['last_active']   = $last_date;
        $detail['recent_orders'] = $recent;

        if ( ! $detail['name'] && ! empty( $orders ) ) {
            $first_order = $orders[0];
            $detail['name'] = trim( $first_order->get_billing_first_name() . ' ' . $first_order->get_billing_last_name() );
            if ( ! $detail['city'] ) {
                $detail['city']    = $first_order->get_billing_city();
                $detail['state']   = $first_order->get_billing_state();
                $detail['postcode'] = $first_order->get_billing_postcode();
                $detail['country'] = $first_order->get_billing_country();
                $detail['phone']   = $first_order->get_billing_phone();
            }
        }
    }

    $detail['orders_url'] = admin_url( 'edit.php?post_type=shop_order&s=' . rawurlencode( $email ) );

    if ( $seg ) {
        $detail['segment']     = $seg->segment;
        $detail['risk_score']  = $seg->risk_score;
        $detail['ltv']         = number_format( $seg->lifetime_value, 0 );
        $detail['frequency']   = $seg->order_frequency > 0 ? round( $seg->order_frequency ) . ' days' : 'N/A';
        $detail['days_since']  = $seg->days_since_last;
        $detail['predicted']   = $seg->predicted_next ? wp_date( 'M j, Y', strtotime( $seg->predicted_next ) ) : 'N/A';
    }

    wp_send_json_success( $detail );
}

// ----------------------------------------------------------------
// FEATURE: CROSS-SELL RECOMMENDATIONS
// ----------------------------------------------------------------
function sre_build_cross_sells() {
    if ( ! function_exists( 'wc_get_orders' ) ) {
        return;
    }
    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_cross_sells';
    $orders = wc_get_orders( array(
        'status' => 'completed',
        'limit'  => 500,
        'orderby' => 'date',
        'order'  => 'DESC',
    ) );
    $pairs = array();
    foreach ( $orders as $order ) {
        $items = $order->get_items();
        $pids = array();
        foreach ( $items as $item ) {
            $pid = $item->get_product_id();
            if ( $pid > 0 ) {
                $pids[] = $pid;
            }
        }
        $pids = array_unique( $pids );
        $count = count( $pids );
        for ( $i = 0; $i < $count; $i++ ) {
            for ( $j = $i + 1; $j < $count; $j++ ) {
                $a = min( $pids[$i], $pids[$j] );
                $b = max( $pids[$i], $pids[$j] );
                $key = $a . '_' . $b;
                if ( ! isset( $pairs[$key] ) ) {
                    $pairs[$key] = array( 'a' => $a, 'b' => $b, 's' => 0 );
                }
                $pairs[$key]['s']++;
            }
        }
    }
    // 5.1.0: each daily run now OVERWRITES the score with the fresh
    // co-purchase count from the recent-500-orders window, instead of
    // ADDING to it (which made scores inflate forever and made the top-N
    // ranking dominated by whichever pairs were oldest in the table).
    // VALUES(score) tells MySQL "use the value from the INSERT clause".
    foreach ( $pairs as $p ) {
        $now = current_time( 'mysql' );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$tbl} (product_id, recommended_id, score, updated_at)
             VALUES (%d, %d, %d, %s)
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = VALUES(updated_at)",
            $p['a'], $p['b'], $p['s'], $now
        ) );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$tbl} (product_id, recommended_id, score, updated_at)
             VALUES (%d, %d, %d, %s)
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = VALUES(updated_at)",
            $p['b'], $p['a'], $p['s'], $now
        ) );
    }

    // Prune pairs whose products no longer co-occur in the recent window.
    // Without this, a one-time co-purchase from years ago would keep its
    // stale score in the table forever.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$tbl} WHERE updated_at < %s",
        gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
    ) );
}

function sre_get_cross_sells( $product_id, $limit = 3 ) {
    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_cross_sells';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT recommended_id FROM {$tbl} WHERE product_id=%d ORDER BY score DESC LIMIT %d",
        $product_id, $limit + 5
    ) );
    $products = array();
    foreach ( $rows as $r ) {
        if ( count( $products ) >= $limit ) {
            break;
        }
        $p = wc_get_product( $r->recommended_id );
        if ( $p && $p->is_purchasable() && $p->is_in_stock() ) {
            $products[] = $p;
        }
    }
    return $products;
}

function sre_cross_sell_html( $product_id ) {
    $products = sre_get_cross_sells( $product_id, 3 );
    if ( empty( $products ) ) {
        return '';
    }
    $html = '<div style="margin:24px 0;padding:18px 0;border-top:1px solid #e5e7eb">';
    $html .= '<h3 style="margin:0 0 14px;font-size:16px;color:#111827">You might also like...</h3>';
    $html .= '<div style="display:flex;gap:12px;flex-wrap:wrap">';
    foreach ( $products as $p ) {
        $url = esc_url( get_permalink( $p->get_id() ) );
        $img = wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' );
        $html .= '<div style="flex:1;min-width:140px;max-width:180px;background:#f9fafb;border-radius:8px;padding:12px;text-align:center">';
        if ( $img ) {
            $html .= '<img src="' . esc_url( $img ) . '" alt="" style="width:100%;max-width:120px;height:auto;border-radius:6px;margin-bottom:8px">';
        }
        $html .= '<p style="margin:0 0 4px;font-size:13px;font-weight:600"><a href="' . $url . '" style="color:#111827;text-decoration:none">' . esc_html( $p->get_name() ) . '</a></p>';
        $html .= '<p style="margin:0 0 8px;font-size:13px;color:#2563eb;font-weight:700">' . $p->get_price_html() . '</p>';
        $html .= '<a href="' . $url . '" style="display:inline-block;padding:6px 14px;background:#2563eb;color:#fff;text-decoration:none;border-radius:4px;font-size:12px;font-weight:600">View</a>';
        $html .= '</div>';
    }
    $html .= '</div></div>';
    return $html;
}

// ----------------------------------------------------------------
// FEATURE: POST-PURCHASE FOLLOW-UPS
// ----------------------------------------------------------------
function sre_schedule_followup( $order_id ) {
    if ( ! sre_is_pro() || ! get_option( 'sre_followup_enabled' ) ) {
        return;
    }
    $delay = max( 1, (int) get_option( 'sre_followup_delay', 7 ) );
    $review_delay = max( 1, (int) get_option( 'sre_review_delay', 14 ) );

    wp_schedule_single_event( time() + $delay * 86400, 'sre_send_followup', array( $order_id, 'thankyou' ) );
    wp_schedule_single_event( time() + $review_delay * 86400, 'sre_send_followup', array( $order_id, 'review' ) );
}

function sre_do_followup( $order_id, $type ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    $email = $order->get_billing_email();
    // 5.3.1: skip follow-ups for unsubscribed customers entirely.
    if ( $email && sre_is_unsubscribed( $email ) ) {
        return;
    }
    $name  = $order->get_billing_first_name();
    if ( ! $name ) {
        $name = 'there';
    }

    if ( $type === 'thankyou' ) {
        $subject = str_replace( '{customer_name}', $name, get_option( 'sre_followup_subject', 'Thank you for your purchase, {customer_name}!' ) );
        $body = sre_email_thankyou( $name, $order );
    } else {
        $items = $order->get_items();
        $first_item = reset( $items );
        $product_name = $first_item ? $first_item->get_name() : 'your purchase';
        $subject = str_replace(
            array( '{customer_name}', '{product_name}' ),
            array( $name, $product_name ),
            get_option( 'sre_review_subject', 'How are you enjoying {product_name}, {customer_name}?' )
        );
        $body = sre_email_review_request( $name, $order );
    }

    global $wpdb;
    $lt = $wpdb->prefix . 'sre_email_log';
    $email_type = $type === 'thankyou' ? 'followup_thankyou' : 'followup_review';

    $wpdb->insert( $lt, array(
        'customer_email' => $email,
        'product_id'     => 0,
        'email_type'     => $email_type,
        'sent_date'      => current_time( 'mysql' ),
    ), array( '%s', '%d', '%s', '%s' ) );
    $log_id = $wpdb->insert_id;
    $body = sre_add_tracking( $log_id, $body );
    sre_save_email_content( $log_id, $subject, $body );

    if ( ! sre_safe_mail( $email, $subject, $body, 'one_email' ) ) {
        $wpdb->delete( $lt, array( 'id' => $log_id ), array( '%d' ) );
    }
}

function sre_email_thankyou( $name, $order ) {
    $content = '<h2>Thank you, ' . esc_html( $name ) . '!</h2>';
    $content .= '<p>We truly appreciate your order. Here is a summary of what you purchased:</p>';
    $content .= '<table style="width:100%;border-collapse:collapse;margin:16px 0">';
    $content .= '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb"><th style="padding:8px 12px;text-align:left;font-size:13px">Product</th><th style="padding:8px 12px;text-align:right;font-size:13px">Qty</th><th style="padding:8px 12px;text-align:right;font-size:13px">Total</th></tr></thead><tbody>';
    $cross_sell_pid = 0;
    foreach ( $order->get_items() as $item ) {
        $content .= '<tr style="border-bottom:1px solid #f3f4f6">';
        $content .= '<td style="padding:8px 12px;font-size:14px">' . esc_html( $item->get_name() ) . '</td>';
        $content .= '<td style="padding:8px 12px;text-align:right;font-size:14px">' . (int) $item->get_quantity() . '</td>';
        $content .= '<td style="padding:8px 12px;text-align:right;font-size:14px">' . sre_money( $item->get_total(), 2 ) . '</td>';
        $content .= '</tr>';
        if ( ! $cross_sell_pid ) {
            $cross_sell_pid = $item->get_product_id();
        }
    }
    $content .= '</tbody></table>';
    $content .= '<p style="text-align:right;font-weight:700;font-size:15px">Total: ' . sre_money( $order->get_total(), 2 ) . '</p>';
    if ( $cross_sell_pid ) {
        $content .= sre_cross_sell_html( $cross_sell_pid );
    }
    $content .= '<p style="text-align:center;margin-top:18px"><a href="' . esc_url( home_url( '/shop/' ) ) . '" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600">Continue Shopping</a></p>';
    return sre_email_wrap( $content );
}

function sre_email_review_request( $name, $order ) {
    $content = '<h2>Hi ' . esc_html( $name ) . '!</h2>';
    $content .= '<p>We hope you are enjoying your recent purchase. Your feedback means the world to us! Would you take a moment to leave a review?</p>';
    foreach ( $order->get_items() as $item ) {
        $pid = $item->get_product_id();
        $product = wc_get_product( $pid );
        if ( ! $product ) {
            continue;
        }
        $url = get_permalink( $pid );
        $img = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
        $content .= '<div style="display:flex;align-items:center;gap:14px;padding:12px;margin:10px 0;background:#f9fafb;border-radius:8px">';
        if ( $img ) {
            $content .= '<img src="' . esc_url( $img ) . '" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:6px">';
        }
        $content .= '<div style="flex:1"><p style="margin:0 0 4px;font-weight:600;font-size:14px">' . esc_html( $product->get_name() ) . '</p>';
        $content .= '<a href="' . esc_url( $url ) . '#reviews" style="font-size:13px;color:#2563eb;text-decoration:none;font-weight:600">Write a Review</a></div></div>';
    }
    $content .= '<p style="text-align:center;margin-top:18px;color:#6b7280;font-size:13px">Your reviews help other customers make great choices.</p>';
    return sre_email_wrap( $content );
}

// ----------------------------------------------------------------
// 5.2.0 — REORDER + LOYALTY PANEL ON WC ORDER EDIT SCREEN
// 5.2.2 — same indicator on the orders LIST screen + lazy-stamp on
//         pre-5.2.0 orders that never went through sre_attribute_revenue
// ----------------------------------------------------------------

/**
 * Resolve the SRE email-log row that best attributes this order. Cached on
 * the order via `_sre_attributed_email_id` post meta — first lookup runs
 * the attribution cascade and stamps the result, every subsequent lookup
 * is a single get_post_meta read.
 *
 * Returns the log_id (int > 0) on success, 0 when no SRE email maps to
 * this order. The negative result is NOT cached, because new email_log
 * rows may appear after the order completes (e.g. backfills, manual sends)
 * and we want a future page-load to pick those up.
 *
 * @param WC_Order|null $order
 * @return int log_id, or 0 when not attributable.
 */
function sre_get_order_attribution_id( $order ) {
    if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
        return 0;
    }
    $order_id = (int) $order->get_id();
    $cached   = (int) get_post_meta( $order_id, '_sre_attributed_email_id', true );
    if ( $cached > 0 ) {
        return $cached;
    }
    if ( ! method_exists( $order, 'get_billing_email' ) ) {
        return 0;
    }
    $email = strtolower( trim( (string) $order->get_billing_email() ) );
    if ( ! is_email( $email ) ) {
        return 0;
    }

    $coupons = method_exists( $order, 'get_coupon_codes' ) ? (array) $order->get_coupon_codes() : array();
    $product_ids = array();
    foreach ( $order->get_items() as $item ) {
        if ( method_exists( $item, 'get_product_id' ) ) {
            $pid = (int) $item->get_product_id();
            if ( $pid > 0 ) {
                $product_ids[] = $pid;
            }
        }
    }
    $product_ids = array_values( array_unique( $product_ids ) );

    // Lazy backfill window: 90 days back from the order date (wider than the
    // 30-day live attribution window so we still catch orders that completed
    // before 5.2.0 but had a matching email weeks earlier).
    $order_dt = method_exists( $order, 'get_date_created' ) && $order->get_date_created()
        ? $order->get_date_created()->date( 'Y-m-d H:i:s' )
        : current_time( 'mysql' );
    $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days', strtotime( $order_dt ) ) );

    if ( ! function_exists( 'sre_find_attribution_log' ) ) {
        return 0;
    }
    $row = sre_find_attribution_log( $email, $cutoff, $coupons, $product_ids );
    if ( ! $row ) {
        return 0;
    }
    update_post_meta( $order_id, '_sre_attributed_email_id', (int) $row->id );
    // 5.2.4: also stamp the email_type so the Source filter can use it
    // directly via meta_query. One extra cheap query on lazy-stamp; nothing
    // on subsequent reads (the next call hits the cache via the meta read at
    // the top of this function).
    global $wpdb;
    $row_type = (string) $wpdb->get_var( $wpdb->prepare(
        "SELECT email_type FROM {$wpdb->prefix}sre_email_log WHERE id=%d LIMIT 1",
        (int) $row->id
    ) );
    if ( $row_type !== '' ) {
        update_post_meta( $order_id, '_sre_attributed_email_type', $row_type );
    }
    return (int) $row->id;
}

/**
 * Register the "Source" column on the WC orders list — handles both the
 * legacy posts-based table (manage_edit-shop_order_columns) and the new
 * HPOS table (woocommerce_shop_order_list_table_columns). Inserted right
 * before the standard "Origin" column when present, otherwise appended.
 */
function sre_register_orders_list_column( $columns ) {
    $insert_before = 'wc_order_attribution_origin';
    // 5.2.3: column header carries a tooltip that explains every badge
    // colour/label so admins can self-serve "what is the difference between
    // Reorder Email and AI Reorder?" without leaving the screen.
    $header_tip = 'Which Smart Reorder email drove this order. '
        . 'Reorder Email = standard template (same body for every customer, just name/product swapped in). '
        . 'AI Reorder = OpenAI-personalized body, different prose per customer (Pro). '
        . 'Win-back = multi-step churn-recovery sequence. '
        . 'Tier Upgrade = loyalty-tier promotion email. '
        . 'Review Request / Follow-up / Thank-you = post-purchase emails. '
        . '— means the order did not come from any plugin email.';
    $label = __( 'Source', 'smart-reorder-emailer' ) . ' ' . sre_tip( $header_tip );

    if ( ! is_array( $columns ) || ! array_key_exists( $insert_before, $columns ) ) {
        $columns['sre_source'] = $label;
        return $columns;
    }
    $new = array();
    foreach ( $columns as $key => $val ) {
        if ( $key === $insert_before ) {
            $new['sre_source'] = $label;
        }
        $new[ $key ] = $val;
    }
    return $new;
}

/** Render the column cell — legacy posts table path. */
function sre_render_orders_list_column_legacy( $column, $post_id ) {
    if ( $column !== 'sre_source' ) return;
    if ( ! function_exists( 'wc_get_order' ) ) return;
    $order = wc_get_order( $post_id );
    if ( ! $order ) return;
    echo sre_render_orders_list_cell( $order );
}

/** Render the column cell — HPOS table path. */
function sre_render_orders_list_column_hpos( $column, $order ) {
    if ( $column !== 'sre_source' ) return;
    echo sre_render_orders_list_cell( $order );
}

/**
 * Build the badge HTML for a single row's Source column. Empty string
 * (returns "—") means the order did not come from any SRE email.
 */
/**
 * 5.2.4 — Source filter dropdown + query wiring.
 *
 * Filter values:
 *   ''         → no filter (default)
 *   'any'      → orders with ANY _sre_attributed_email_id meta
 *   'none'     → orders without _sre_attributed_email_id meta
 *   '<type>'   → orders whose _sre_attributed_email_type meta = <type>
 *                (e.g. 'reorder', 'ai_reorder', 'winback', 'tier_upgrade')
 */
function sre_orders_list_source_options() {
    return array(
        ''             => __( 'All sources', 'smart-reorder-emailer' ),
        'any'          => __( 'Any Smart Reorder source', 'smart-reorder-emailer' ),
        'reorder'      => __( '— Reorder Email', 'smart-reorder-emailer' ),
        'ai_reorder'   => __( '— AI Reorder', 'smart-reorder-emailer' ),
        'winback'      => __( '— Win-back', 'smart-reorder-emailer' ),
        'tier_upgrade' => __( '— Tier Upgrade', 'smart-reorder-emailer' ),
        'review'       => __( '— Review Request', 'smart-reorder-emailer' ),
        'followup'     => __( '— Follow-up', 'smart-reorder-emailer' ),
        'thankyou'     => __( '— Thank-you', 'smart-reorder-emailer' ),
        'none'         => __( 'No source (organic / direct)', 'smart-reorder-emailer' ),
    );
}

/** Render the dropdown — legacy posts-based screen (`?post_type=shop_order`). */
function sre_render_orders_list_filter_legacy() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'shop_order' ) {
        return;
    }
    sre_render_orders_list_filter_dropdown();
}

/** Render the dropdown — HPOS screen. WC fires this hook on the right page already. */
function sre_render_orders_list_filter_hpos() {
    sre_render_orders_list_filter_dropdown();
}

function sre_render_orders_list_filter_dropdown() {
    $current = isset( $_GET['sre_source'] ) ? sanitize_key( wp_unslash( $_GET['sre_source'] ) ) : '';
    $opts    = sre_orders_list_source_options();
    echo '<select name="sre_source" id="sre-source-filter" style="margin-right:6px">';
    foreach ( $opts as $val => $label ) {
        echo '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';
}

/** Apply the filter to the legacy posts-based query. */
function sre_apply_orders_list_filter_legacy( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || $screen->post_type !== 'shop_order' ) {
        return;
    }
    $val = isset( $_GET['sre_source'] ) ? sanitize_key( wp_unslash( $_GET['sre_source'] ) ) : '';
    if ( $val === '' ) {
        return;
    }
    $meta_query = (array) $query->get( 'meta_query' );
    foreach ( sre_build_source_meta_query( $val ) as $clause ) {
        $meta_query[] = $clause;
    }
    if ( ! empty( $meta_query ) ) {
        $query->set( 'meta_query', $meta_query );
    }
}

/** Apply the filter to the HPOS query args. */
function sre_apply_orders_list_filter_hpos( $args ) {
    $val = isset( $_GET['sre_source'] ) ? sanitize_key( wp_unslash( $_GET['sre_source'] ) ) : '';
    if ( $val === '' ) {
        return $args;
    }
    if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
        $args['meta_query'] = array();
    }
    foreach ( sre_build_source_meta_query( $val ) as $clause ) {
        $args['meta_query'][] = $clause;
    }
    return $args;
}

/**
 * Build the meta_query clauses for a given filter value. Returns an array
 * of clauses (usually one) — the caller appends to its meta_query.
 */
function sre_build_source_meta_query( $val ) {
    if ( $val === 'any' ) {
        return array( array(
            'key'     => '_sre_attributed_email_id',
            'compare' => 'EXISTS',
        ) );
    }
    if ( $val === 'none' ) {
        return array( array(
            'key'     => '_sre_attributed_email_id',
            'compare' => 'NOT EXISTS',
        ) );
    }
    // Specific email_type. Match the denormalized type meta.
    return array( array(
        'key'     => '_sre_attributed_email_type',
        'value'   => $val,
        'compare' => '=',
    ) );
}

function sre_render_orders_list_cell( $order ) {
    $log_id = sre_get_order_attribution_id( $order );
    if ( $log_id <= 0 ) {
        return '<span style="color:#94a3b8">&mdash;</span>';
    }

    global $wpdb;
    $log = $wpdb->get_row( $wpdb->prepare(
        "SELECT email_type FROM {$wpdb->prefix}sre_email_log WHERE id=%d LIMIT 1",
        $log_id
    ) );
    $type = $log ? (string) $log->email_type : '';

    $type_labels = array(
        'reorder'      => 'Reorder Email',
        'ai_reorder'   => 'AI Reorder',
        'winback'      => 'Win-back',
        'tier_upgrade' => 'Tier Upgrade',
        'review'       => 'Review Request',
        'followup'     => 'Follow-up',
        'thankyou'     => 'Thank-you',
    );
    $type_colors = array(
        'reorder'      => '#2563eb',
        'ai_reorder'   => '#7c3aed',
        'winback'      => '#ea580c',
        'tier_upgrade' => '#f59e0b',
        'review'       => '#0891b2',
        'followup'     => '#0d9488',
        'thankyou'     => '#16a34a',
    );
    $label = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : 'Reorder Email';
    $color = isset( $type_colors[ $type ] ) ? $type_colors[ $type ] : '#2563eb';

    // 5.2.5: badge is a button — clicking it pops up the saved subject + body
    // for this email_log row in a modal. The actual email payload is fetched
    // on demand via wp_ajax_sre_view_email; we only emit the id + nonce here.
    $nonce = wp_create_nonce( 'sre_view_email_' . (int) $log_id );
    $html = '<button type="button" class="sre-source-badge" data-log-id="' . (int) $log_id . '" data-nonce="' . esc_attr( $nonce ) . '" title="' . esc_attr( 'Click to view the ' . $label . ' email this customer received' ) . '" style="display:inline-block;padding:2px 9px;background:' . esc_attr( $color ) . '15;color:' . esc_attr( $color ) . ';border:1px solid ' . esc_attr( $color ) . '40;border-radius:10px;font-size:11px;font-weight:600;white-space:nowrap;cursor:pointer;font-family:inherit;line-height:inherit">' . esc_html( $label ) . '</button>';

    // 5.3.1: Show coupon code + savings if the customer redeemed an SRE coupon.
    // Lazy-stamp: backfill _sre_coupon_code for orders that completed before
    // this version by checking the order's used coupons against sre_discounts.
    $order_id    = (int) $order->get_id();
    $coupon_code = get_post_meta( $order_id, '_sre_coupon_code', true );
    $savings     = (float) get_post_meta( $order_id, '_sre_coupon_savings', true );
    if ( ! $coupon_code && method_exists( $order, 'get_coupon_codes' ) ) {
        $used = $order->get_coupon_codes();
        if ( ! empty( $used ) ) {
            global $wpdb;
            $disc_tbl = $wpdb->prefix . 'sre_discounts';
            foreach ( $used as $uc ) {
                $sre_match = $wpdb->get_row( $wpdb->prepare(
                    "SELECT code FROM {$disc_tbl} WHERE code=%s LIMIT 1", $uc
                ) );
                if ( $sre_match ) {
                    $coupon_code = $sre_match->code;
                    // Calculate savings from coupon items
                    foreach ( $order->get_items( 'coupon' ) as $ci ) {
                        if ( strtolower( $ci->get_code() ) === strtolower( $coupon_code ) ) {
                            $savings = (float) $ci->get_discount();
                        }
                    }
                    update_post_meta( $order_id, '_sre_coupon_code', $coupon_code );
                    update_post_meta( $order_id, '_sre_coupon_savings', $savings );
                    break;
                }
            }
        }
    }
    if ( $coupon_code ) {
        $html .= '<div style="margin-top:4px;font-size:10px;line-height:1.4">';
        $html .= '<span style="color:#16a34a;font-weight:600" title="Customer redeemed this coupon from the campaign email">&#10003; ' . esc_html( $coupon_code ) . '</span>';
        if ( $savings > 0 ) {
            $html .= '<span style="color:#6b7280"> &middot; saved ' . wp_strip_all_tags( wc_price( $savings ) ) . '</span>';
        }
        $html .= '</div>';
    }

    return $html;
}

/**
 * 5.2.5: render the source-email viewer modal + click handler. Only runs on
 * the orders list screens (legacy posts-based and HPOS). Self-skips
 * everywhere else so the markup never bloats other admin screens.
 */
function sre_orders_list_email_viewer_modal() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen ) {
        return;
    }
    $is_legacy = isset( $screen->id ) && $screen->id === 'edit-shop_order';
    $is_hpos   = isset( $screen->id ) && $screen->id === 'woocommerce_page_wc-orders';
    if ( ! $is_legacy && ! $is_hpos ) {
        return;
    }
    $ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
    ?>
    <div id="sre-email-viewer" style="display:none;position:fixed;inset:0;z-index:160000;background:rgba(15,23,42,.55);backdrop-filter:blur(2px)">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(820px,92vw);max-height:88vh;background:#fff;border-radius:10px;box-shadow:0 30px 60px rgba(0,0,0,.35);display:flex;flex-direction:column;overflow:hidden">
            <div style="padding:14px 18px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;background:#f8fafc">
                <div style="flex:1;min-width:0">
                    <div id="sre-ev-type" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#2563eb;margin-bottom:2px">Email</div>
                    <div id="sre-ev-subject" style="font-size:15px;font-weight:600;color:#0f172a;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">Loading…</div>
                </div>
                <button type="button" id="sre-ev-close" style="background:transparent;border:0;font-size:22px;color:#6b7280;cursor:pointer;padding:4px 10px;line-height:1" aria-label="Close">&times;</button>
            </div>
            <div id="sre-ev-meta" style="padding:8px 18px;font-size:12px;color:#64748b;background:#fff;border-bottom:1px solid #f1f5f9;display:flex;flex-wrap:wrap;gap:14px"></div>
            <div style="flex:1;background:#f1f5f9;overflow:hidden;min-height:300px">
                <iframe id="sre-ev-frame" sandbox="allow-same-origin" style="width:100%;height:100%;border:0;background:#fff;min-height:300px"></iframe>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var ajax = '<?php echo $ajax_url; // already escaped ?>';
        var modal = document.getElementById('sre-email-viewer');
        if (!modal) return;
        var subjEl = document.getElementById('sre-ev-subject');
        var typeEl = document.getElementById('sre-ev-type');
        var metaEl = document.getElementById('sre-ev-meta');
        var frame  = document.getElementById('sre-ev-frame');

        function open(){ modal.style.display = 'block'; }
        function close(){
            modal.style.display = 'none';
            frame.srcdoc = '';
            metaEl.innerHTML = '';
        }
        document.getElementById('sre-ev-close').addEventListener('click', close);
        modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal.style.display === 'block') close(); });

        document.addEventListener('click', function(e){
            var btn = e.target.closest && e.target.closest('.sre-source-badge');
            if (!btn) return;
            e.preventDefault();
            var logId = btn.getAttribute('data-log-id');
            var nonce = btn.getAttribute('data-nonce');
            if (!logId) return;

            subjEl.textContent = 'Loading…';
            typeEl.textContent = btn.textContent.trim();
            metaEl.innerHTML = '';
            frame.srcdoc = '<div style="padding:40px;font-family:system-ui;color:#94a3b8;text-align:center">Loading email…</div>';
            open();

            var fd = new FormData();
            fd.append('action', 'sre_view_email');
            fd.append('log_id', logId);
            fd.append('nonce',  nonce);

            fetch(ajax, { method:'POST', credentials:'same-origin', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(j){
                    if (!j || !j.success) {
                        subjEl.textContent = 'Could not load email';
                        frame.srcdoc = '<div style="padding:40px;font-family:system-ui;color:#dc2626">'+ ((j && j.data) || 'Unknown error') +'</div>';
                        return;
                    }
                    var d = j.data;
                    typeEl.textContent = d.type_label || 'Email';
                    subjEl.textContent = d.subject || '(no subject saved)';
                    var meta = [];
                    if (d.customer_email) meta.push('<span><strong>To:</strong> '+ d.customer_email +'</span>');
                    if (d.sent_date)      meta.push('<span><strong>Sent:</strong> '+ d.sent_date +'</span>');
                    if (d.discount_code)  meta.push('<span><strong>Coupon:</strong> <code style="background:#f1f5f9;padding:1px 6px;border-radius:3px">'+ d.discount_code +'</code></span>');
                    if (d.ab_variant)     meta.push('<span><strong>A/B:</strong> '+ d.ab_variant +'</span>');
                    metaEl.innerHTML = meta.join('');
                    if (d.body) {
                        frame.srcdoc = d.body;
                    } else {
                        frame.srcdoc = '<div style="padding:40px;font-family:system-ui;color:#94a3b8;text-align:center">This email was sent before body archiving was enabled — no copy was saved.</div>';
                    }
                })
                .catch(function(err){
                    subjEl.textContent = 'Could not load email';
                    frame.srcdoc = '<div style="padding:40px;font-family:system-ui;color:#dc2626">'+ String(err) +'</div>';
                });
        });
    })();
    </script>
    <?php
}

/**
 * 5.2.5: AJAX handler returning the saved subject/body for one email_log row.
 * Cap-checked (manage_woocommerce) + per-row nonce.
 */
function sre_ajax_view_email() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied.', 403 );
    }
    $log_id = isset( $_POST['log_id'] ) ? (int) $_POST['log_id'] : 0;
    $nonce  = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( $log_id <= 0 || ! wp_verify_nonce( $nonce, 'sre_view_email_' . $log_id ) ) {
        wp_send_json_error( 'Invalid request.', 400 );
    }

    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT customer_email, email_type, subject, body, sent_date, ab_variant, discount_code
         FROM {$wpdb->prefix}sre_email_log WHERE id=%d LIMIT 1",
        $log_id
    ) );
    if ( ! $row ) {
        wp_send_json_error( 'Email not found.', 404 );
    }

    $type_labels = array(
        'reorder'           => 'Reorder Email',
        'ai_reorder'        => 'AI Reorder',
        'winback'           => 'Win-back',
        'winback_step1'     => 'Win-back · Step 1',
        'winback_step2'     => 'Win-back · Step 2',
        'winback_step3'     => 'Win-back · Step 3',
        'tier_upgrade'      => 'Tier Upgrade',
        'review'            => 'Review Request',
        'followup'          => 'Follow-up',
        'thankyou'          => 'Thank-you',
        'followup_thankyou' => 'Thank-you',
        'followup_review'   => 'Review Request',
    );
    $type = (string) $row->email_type;
    $type_label = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );

    $sent_human = '';
    if ( $row->sent_date && $row->sent_date !== '0000-00-00 00:00:00' ) {
        $sent_human = wp_date( 'M j, Y g:i A', strtotime( $row->sent_date ) );
    }

    // 5.3.1: substitute the unsub placeholder so the modal preview matches
    // what the customer actually received. Stored rows keep the placeholder
    // rather than the per-recipient signed URL so future token-key rotations
    // do not break the archive — the URL is rebuilt on display.
    $body_html = str_replace( '{{SRE_UNSUB_URL}}', esc_url( sre_unsub_url( (string) $row->customer_email ) ), (string) $row->body );

    wp_send_json_success( array(
        'subject'        => (string) $row->subject,
        'body'           => $body_html,
        'customer_email' => (string) $row->customer_email,
        'sent_date'      => $sent_human,
        'type_label'     => $type_label,
        'ab_variant'     => (string) $row->ab_variant,
        'discount_code'  => (string) $row->discount_code,
    ) );
}



/**
 * 5.2.4: side-column meta box on the WC single-order edit screen.
 *
 * Replaces the old `woocommerce_admin_order_data_after_order_details` hook,
 * which rendered the panel INSIDE the General data column — visually
 * crammed and out of place. The meta box now sits in the side column
 * alongside Order actions / Order notes / Order attribution, which is the
 * native location for this kind of summary card.
 *
 * Conditional registration: the meta box is only added when the order has
 * an SRE attribution. Non-attributed orders see no extra box (no noisy
 * "this order did not come from an email" placeholder).
 *
 * Handles both the legacy posts-based shop_order screen AND the HPOS
 * woocommerce_page_wc-orders screen — `add_meta_boxes` fires on both. The
 * 2nd-arg type differs (WP_Post vs WC_Order); we coerce defensively.
 */
function sre_register_order_meta_box( $post_type, $post_or_order ) {
    $screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
    if ( ! in_array( $post_type, $screens, true ) ) {
        return;
    }
    $order = null;
    if ( $post_or_order instanceof \WP_Post ) {
        if ( function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $post_or_order->ID );
        }
    } elseif ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) ) {
        $order = $post_or_order;
    }
    if ( ! $order ) {
        return;
    }
    $log_id = sre_get_order_attribution_id( $order );
    if ( $log_id <= 0 ) {
        return; // No SRE attribution → skip the meta box entirely.
    }

    add_meta_box(
        'sre_order_source',
        __( 'Smart Reorder Source', 'smart-reorder-emailer' ),
        'sre_render_order_meta_box',
        $post_type,
        'side',
        'high'
    );
}

/**
 * Render the side meta-box body. Compact vertical layout: each fact gets its
 * own labeled row instead of an inline-flow paragraph (looks better in the
 * narrow side column).
 *
 * @param WP_Post|WC_Order $post_or_order
 */
function sre_render_order_meta_box( $post_or_order ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return;
    }
    $order = ( $post_or_order instanceof \WP_Post )
        ? wc_get_order( $post_or_order->ID )
        : $post_or_order;
    if ( ! $order ) {
        return;
    }

    $log_id = sre_get_order_attribution_id( $order );
    if ( $log_id <= 0 ) {
        return;
    }

    global $wpdb;
    $log = $wpdb->get_row( $wpdb->prepare(
        "SELECT customer_email, email_type, sent_date, ab_variant, discount_code FROM {$wpdb->prefix}sre_email_log WHERE id=%d LIMIT 1",
        $log_id
    ) );
    if ( ! $log ) {
        return;
    }
    $email = (string) $log->customer_email;

    $loyalty = $wpdb->get_row( $wpdb->prepare(
        "SELECT tier, points, total_spent, total_orders FROM {$wpdb->prefix}sre_loyalty WHERE customer_email=%s LIMIT 1",
        $email
    ) );
    $segment = $wpdb->get_var( $wpdb->prepare(
        "SELECT segment FROM {$wpdb->prefix}sre_segments WHERE customer_email=%s LIMIT 1",
        $email
    ) );

    // Friendly email-type label + brand color (matches the orders-list badge).
    $type_labels = array(
        'reorder'      => 'Reorder reminder',
        'ai_reorder'   => 'AI reorder reminder',
        'winback'      => 'Win-back campaign',
        'tier_upgrade' => 'Loyalty tier upgrade',
        'review'       => 'Review request',
        'followup'     => 'Post-purchase follow-up',
        'thankyou'     => 'Thank-you',
    );
    $type_colors = array(
        'reorder'      => '#2563eb',
        'ai_reorder'   => '#7c3aed',
        'winback'      => '#ea580c',
        'tier_upgrade' => '#f59e0b',
        'review'       => '#0891b2',
        'followup'     => '#0d9488',
        'thankyou'     => '#16a34a',
    );
    $type_human = isset( $type_labels[ $log->email_type ] ) ? $type_labels[ $log->email_type ] : ucfirst( str_replace( '_', ' ', (string) $log->email_type ) );
    $accent     = isset( $type_colors[ $log->email_type ] ) ? $type_colors[ $log->email_type ] : '#2563eb';

    // Compact side-column layout. Each row: small uppercase label + value.
    $row_style    = 'margin:0 0 8px;font-size:12px;line-height:1.5';
    $label_style  = 'display:block;color:#6b7280;font-size:10px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;margin-bottom:1px';
    $value_style  = 'color:#0f172a';

    echo '<div style="padding-top:4px">';

    // Email-type badge (same colour scheme as orders-list column).
    echo '<div style="margin:0 0 12px">';
    echo '<span style="display:inline-block;padding:3px 10px;background:' . esc_attr( $accent ) . '15;color:' . esc_attr( $accent ) . ';border:1px solid ' . esc_attr( $accent ) . '40;border-radius:10px;font-size:11px;font-weight:700">' . esc_html( $type_human ) . '</span>';
    echo '</div>';

    if ( $log->sent_date ) {
        echo '<p style="' . $row_style . '"><span style="' . $label_style . '">Sent</span><span style="' . $value_style . '">' . esc_html( wp_date( 'M j, Y g:i A', strtotime( $log->sent_date ) ) ) . '</span></p>';
    }
    if ( $log->ab_variant ) {
        echo '<p style="' . $row_style . '"><span style="' . $label_style . '">A/B variant</span><span style="' . $value_style . '"><code style="background:#f1f5f9;padding:1px 6px;border-radius:3px;font-size:11px">' . esc_html( $log->ab_variant ) . '</code></span></p>';
    }
    if ( $log->discount_code ) {
        // 5.3.1: Check if the coupon was actually redeemed on this order
        $order_id     = (int) $order->get_id();
        $redeemed     = get_post_meta( $order_id, '_sre_coupon_code', true );
        $coupon_saved = (float) get_post_meta( $order_id, '_sre_coupon_savings', true );

        if ( $redeemed && stripos( $redeemed, $log->discount_code ) !== false ) {
            // Coupon was redeemed — show green checkmark
            echo '<p style="' . $row_style . '"><span style="' . $label_style . '">Coupon redeemed</span>';
            echo '<span style="color:#16a34a;font-weight:600">&#10003; </span>';
            echo '<code style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:3px;font-size:11px">' . esc_html( $log->discount_code ) . '</code>';
            if ( $coupon_saved > 0 ) {
                echo '<span style="' . $value_style . ';margin-left:4px;font-size:11px"> &mdash; saved ' . wp_strip_all_tags( wc_price( $coupon_saved ) ) . '</span>';
            }
            echo '</p>';
        } else {
            // Coupon was sent but NOT redeemed on this order
            echo '<p style="' . $row_style . '"><span style="' . $label_style . '">Coupon sent</span><span style="' . $value_style . '"><code style="background:#f1f5f9;padding:1px 6px;border-radius:3px;font-size:11px">' . esc_html( $log->discount_code ) . '</code> <span style="color:#94a3b8;font-size:10px">(not used)</span></span></p>';
        }
    }

    if ( $loyalty || $segment ) {
        echo '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #e5e7eb">';

        if ( $segment ) {
            $seg_badge = function_exists( 'sre_segment_badge' ) ? sre_segment_badge( $segment ) : esc_html( ucfirst( str_replace( '_', ' ', $segment ) ) );
            echo '<p style="' . $row_style . '"><span style="' . $label_style . '">Segment</span><span style="' . $value_style . '">' . $seg_badge . '</span></p>';
        }

        if ( $loyalty ) {
            $tier_badge = function_exists( 'sre_tier_badge' ) ? sre_tier_badge( $loyalty->tier ) : esc_html( ucfirst( (string) $loyalty->tier ) );
            echo '<p style="' . $row_style . '"><span style="' . $label_style . '">Loyalty tier</span><span style="' . $value_style . '">' . $tier_badge . ' &middot; ' . esc_html( number_format( (int) $loyalty->points ) ) . ' pts</span></p>';
            echo '<p style="' . $row_style . '"><span style="' . $label_style . '">Lifetime spend</span><span style="' . $value_style . '">' . sre_money( (float) $loyalty->total_spent, 0 ) . ' across ' . (int) $loyalty->total_orders . ' orders</span></p>';
        }

        echo '</div>';
    }

    echo '</div>';
}

// ----------------------------------------------------------------
// FEATURE: CUSTOMER LOYALTY TIERS
// ----------------------------------------------------------------
function sre_award_loyalty_points( $order_id ) {
    if ( ! sre_is_pro() || ! get_option( 'sre_loyalty_enabled' ) ) {
        return;
    }
    if ( ! function_exists( 'wc_get_order' ) ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    $email = $order->get_billing_email();
    if ( ! $email ) {
        return;
    }
    $total = (float) $order->get_total();
    $ppd   = max( 1, (int) get_option( 'sre_loyalty_points_per_dollar', 1 ) );
    $points = (int) round( $total * $ppd );

    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_loyalty';
    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE customer_email=%s", $email ) );

    if ( $existing ) {
        $new_points = (int) $existing->points + $points;
        $new_spent  = (float) $existing->total_spent + $total;
        $new_orders = (int) $existing->total_orders + 1;
        $wpdb->update( $tbl, array(
            'points'       => $new_points,
            'total_spent'  => $new_spent,
            'total_orders' => $new_orders,
        ), array( 'id' => $existing->id ), array( '%d', '%f', '%d' ), array( '%d' ) );
        $old_tier = $existing->tier;
    } else {
        $new_points = $points;
        $new_spent  = $total;
        $new_orders = 1;
        $wpdb->insert( $tbl, array(
            'customer_email' => $email,
            'tier'           => 'bronze',
            'points'         => $new_points,
            'total_spent'    => $new_spent,
            'total_orders'   => $new_orders,
            'tier_updated_at'=> current_time( 'mysql' ),
        ), array( '%s', '%s', '%d', '%f', '%d', '%s' ) );
        $old_tier = 'bronze';
    }

    $plat_t  = (int) get_option( 'sre_loyalty_platinum_threshold', 5000 );
    $gold_t  = (int) get_option( 'sre_loyalty_gold_threshold', 1500 );
    $silver_t = (int) get_option( 'sre_loyalty_silver_threshold', 500 );

    $new_tier = 'bronze';
    if ( $new_points >= $plat_t ) {
        $new_tier = 'platinum';
    } elseif ( $new_points >= $gold_t ) {
        $new_tier = 'gold';
    } elseif ( $new_points >= $silver_t ) {
        $new_tier = 'silver';
    }

    if ( $new_tier !== $old_tier ) {
        $tiers_order = array( 'bronze' => 0, 'silver' => 1, 'gold' => 2, 'platinum' => 3 );
        if ( isset( $tiers_order[$new_tier] ) && isset( $tiers_order[$old_tier] ) && $tiers_order[$new_tier] > $tiers_order[$old_tier] ) {
            $wpdb->update( $tbl, array(
                'tier'           => $new_tier,
                'tier_updated_at'=> current_time( 'mysql' ),
            ), array( 'customer_email' => $email ), array( '%s', '%s' ), array( '%s' ) );

            $disc_map = array(
                'silver'   => (int) get_option( 'sre_loyalty_silver_discount', 5 ),
                'gold'     => (int) get_option( 'sre_loyalty_gold_discount', 10 ),
                'platinum' => (int) get_option( 'sre_loyalty_platinum_discount', 15 ),
            );
            $disc_pct = isset( $disc_map[$new_tier] ) ? $disc_map[$new_tier] : 0;
            if ( $disc_pct > 0 ) {
                sre_email_tier_upgrade( $email, $new_tier, $disc_pct );
            }
        }
    }
}

function sre_email_tier_upgrade( $email, $new_tier, $discount_pct ) {
    $name = sre_get_name( $email );
    $badge = sre_tier_badge( $new_tier );
    $content = '<div style="text-align:center;padding:20px 0">';
    $content .= '<h2 style="margin:0 0 10px">Congratulations, ' . esc_html( $name ) . '!</h2>';
    $content .= '<p style="font-size:16px;margin:0 0 16px">You have been upgraded to <strong>' . ucfirst( $new_tier ) . '</strong> tier!</p>';
    $content .= '<div style="display:inline-block;margin:0 auto 16px">' . $badge . '</div>';
    $content .= '<div style="background:#f0fdf4;border:2px solid #16a34a;border-radius:8px;padding:16px;margin:18px auto;max-width:300px">';
    $content .= '<p style="margin:0 0 6px;font-size:13px;color:#166534;font-weight:600">Your new benefit</p>';
    $content .= '<p style="margin:0;font-size:28px;font-weight:800;color:#16a34a">' . $discount_pct . '% OFF</p>';
    $content .= '<p style="margin:6px 0 0;font-size:12px;color:#6b7280">On all future orders</p>';
    $content .= '</div>';
    $content .= '<p><a href="' . esc_url( home_url( '/shop/' ) ) . '" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600">Shop Now</a></p>';
    $content .= '</div>';
    $wrapped = sre_email_wrap( $content );
    $subject = 'You have been upgraded to ' . ucfirst( $new_tier ) . ' tier!';

    global $wpdb;
    $lt = $wpdb->prefix . 'sre_email_log';
    $wpdb->insert( $lt, array(
        'customer_email' => $email,
        'product_id'     => 0,
        'email_type'     => 'tier_upgrade',
        'sent_date'      => current_time( 'mysql' ),
    ), array( '%s', '%d', '%s', '%s' ) );
    $log_id = $wpdb->insert_id;
    $wrapped = sre_add_tracking( $log_id, $wrapped );
    sre_save_email_content( $log_id, $subject, $wrapped );

    if ( ! sre_safe_mail( $email, $subject, $wrapped, 'tier_upgrade' ) ) {
        $wpdb->delete( $lt, array( 'id' => $log_id ), array( '%d' ) );
    }
}

function sre_tier_badge( $tier ) {
    $colors = array(
        'bronze'   => array( 'bg' => '#CD7F32', 'text' => '#fff' ),
        'silver'   => array( 'bg' => '#C0C0C0', 'text' => '#333' ),
        'gold'     => array( 'bg' => '#FFD700', 'text' => '#333' ),
        'platinum' => array( 'bg' => '#E5E4E2', 'text' => '#333' ),
    );
    $c = isset( $colors[$tier] ) ? $colors[$tier] : $colors['bronze'];
    return '<span style="display:inline-block;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700;background:' . $c['bg'] . ';color:' . $c['text'] . '">' . ucfirst( $tier ) . '</span>';
}

function sre_loyalty_widget() {
    if ( ! sre_is_pro() || ! get_option( 'sre_loyalty_enabled' ) ) {
        return;
    }
    $user = wp_get_current_user();
    if ( ! $user || ! $user->user_email ) {
        return;
    }
    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_loyalty';
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE customer_email=%s", $user->user_email ) );
    if ( ! $row ) {
        return;
    }
    $disc_map = array(
        'bronze'   => 0,
        'silver'   => (int) get_option( 'sre_loyalty_silver_discount', 5 ),
        'gold'     => (int) get_option( 'sre_loyalty_gold_discount', 10 ),
        'platinum' => (int) get_option( 'sre_loyalty_platinum_discount', 15 ),
    );
    $current_disc = isset( $disc_map[$row->tier] ) ? $disc_map[$row->tier] : 0;

    $next_tier = '';
    $next_threshold = 0;
    $tiers_list = array( 'bronze', 'silver', 'gold', 'platinum' );
    $thresholds = array(
        'silver'   => (int) get_option( 'sre_loyalty_silver_threshold', 500 ),
        'gold'     => (int) get_option( 'sre_loyalty_gold_threshold', 1500 ),
        'platinum' => (int) get_option( 'sre_loyalty_platinum_threshold', 5000 ),
    );
    $idx = array_search( $row->tier, $tiers_list );
    if ( $idx !== false && $idx < 3 ) {
        $next_tier = $tiers_list[$idx + 1];
        $next_threshold = $thresholds[$next_tier];
    }

    echo '<div style="background:linear-gradient(135deg,#f8fafc,#f0f9ff);border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin:16px 0">';
    echo '<h3 style="margin:0 0 12px;font-size:16px;color:#1e293b">Your Loyalty Status</h3>';
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">';
    echo sre_tier_badge( $row->tier );
    echo '<span style="font-size:14px;font-weight:600;color:#334155">' . number_format( $row->points ) . ' points</span>';
    if ( $current_disc > 0 ) {
        echo '<span style="font-size:13px;color:#16a34a;font-weight:600">' . $current_disc . '% discount</span>';
    }
    echo '</div>';
    if ( $next_tier && $next_threshold > 0 ) {
        $progress = min( 100, round( ( $row->points / $next_threshold ) * 100 ) );
        $remaining = max( 0, $next_threshold - (int) $row->points );
        echo '<div style="margin-bottom:8px">';
        echo '<div style="display:flex;justify-content:space-between;font-size:12px;color:#64748b;margin-bottom:4px">';
        echo '<span>Progress to ' . ucfirst( $next_tier ) . '</span>';
        echo '<span>' . $remaining . ' points to go</span>';
        echo '</div>';
        echo '<div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden">';
        echo '<div style="height:100%;width:' . $progress . '%;background:linear-gradient(90deg,#2563eb,#7c3aed);border-radius:4px"></div>';
        echo '</div></div>';
    }
    echo '</div>';
}

function sre_page_loyalty() {
    if ( ! sre_is_pro() ) {
        wp_redirect( admin_url( 'admin.php?page=smart-reorder-upgrade' ) );
        exit;
    }
    global $wpdb;
    $tbl = $wpdb->prefix . 'sre_loyalty';

    $f_tier   = isset( $_GET['tier'] ) ? sanitize_text_field( $_GET['tier'] ) : '';
    $f_search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
    $per      = 50;
    $pg       = max( 1, (int) ( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );

    $where = '1=1';
    if ( $f_tier ) {
        $where .= $wpdb->prepare( ' AND tier=%s', $f_tier );
    }
    if ( $f_search ) {
        $where .= $wpdb->prepare( ' AND customer_email LIKE %s', '%' . $wpdb->esc_like( $f_search ) . '%' );
    }
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE {$where}" );
    $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE {$where} ORDER BY points DESC LIMIT %d OFFSET %d", $per, ( $pg - 1 ) * $per ) );

    $total_members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
    $bronze_cnt    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE tier='bronze'" );
    $silver_cnt    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE tier='silver'" );
    $gold_cnt      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE tier='gold'" );
    $plat_cnt      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE tier='platinum'" );

    sre_page_header( 'Loyalty Tiers' );
    sre_page_intro( 'Bronze / Silver / Gold / Platinum tiers based on lifetime spend. Customers earn points on every order (configurable rate), and tier upgrades trigger an automatic congratulations email with a tier-exclusive discount.' );
    sre_explainer(
        'How tiers and points work',
        'Points are awarded at <strong>1 point per dollar spent</strong> by default (configurable in Settings). Tier thresholds are total-spend-based, also editable in Settings. Tier upgrades fire on the next order completion that crosses a threshold; a "Welcome to Gold" email goes out automatically with a tier-specific discount code attached.'
    );

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:18px">';
    $cards = array(
        array( 'Total Members', $total_members, '#2563eb' ),
        array( 'Bronze', $bronze_cnt, '#CD7F32' ),
        array( 'Silver', $silver_cnt, '#808080' ),
        array( 'Gold', $gold_cnt, '#d97706' ),
        array( 'Platinum', $plat_cnt, '#6b7280' ),
    );
    foreach ( $cards as $card ) {
        echo '<div style="background:#fff;border-radius:8px;padding:18px;border-left:4px solid ' . $card[2] . ';box-shadow:0 1px 4px rgba(0,0,0,.07)">';
        echo '<p style="margin:0 0 4px;font-size:12px;color:#6b7280">' . $card[0] . '</p>';
        echo '<p style="margin:0;font-size:24px;font-weight:700;color:' . $card[2] . '">' . number_format( $card[1] ) . '</p></div>';
    }
    echo '</div>';

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">';
    echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-loyalty' ) ) . '" class="button' . ( ! $f_tier ? ' button-primary' : '' ) . '">All</a>';
    foreach ( array( 'bronze', 'silver', 'gold', 'platinum' ) as $t ) {
        $active = $f_tier === $t ? ' button-primary' : '';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-loyalty&tier=' . $t ) ) . '" class="button' . $active . '">' . sre_tier_badge( $t ) . '</a>';
    }
    echo '</div>';

    echo '<form method="get" style="background:#fff;border-radius:8px;padding:12px 18px;margin-bottom:14px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;gap:10px;align-items:flex-end">';
    echo '<input type="hidden" name="page" value="smart-reorder-loyalty">';
    if ( $f_tier ) {
        echo '<input type="hidden" name="tier" value="' . esc_attr( $f_tier ) . '">';
    }
    echo '<div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Search Email</label>';
    echo '<input type="text" name="search" value="' . esc_attr( $f_search ) . '" placeholder="email@..." style="padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;width:220px"></div>';
    echo '<button type="submit" class="button button-primary">Search</button>';
    echo '</form>';

    echo '<p style="color:#6b7280;font-size:13px;margin-bottom:10px">' . number_format( $total ) . ' member' . ( $total !== 1 ? 's' : '' ) . '</p>';

    echo '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07)">';
    echo '<table class="wp-list-table widefat" style="font-size:13px;margin:0;border-collapse:collapse">';
    echo '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
    echo '<th style="padding:10px 14px">Customer</th><th style="padding:10px;width:90px">Tier</th>';
    echo '<th style="padding:10px;width:80px">Points</th><th style="padding:10px;width:100px">Total Spent</th>';
    echo '<th style="padding:10px;width:60px">Orders</th><th style="padding:10px;width:120px">Tier Updated</th>';
    echo '</tr></thead><tbody>';

    if ( empty( $rows ) ) {
        echo '<tr><td colspan="6" style="padding:20px;text-align:center;color:#6b7280">No loyalty members found.</td></tr>';
    } else {
        $ri = 0;
        foreach ( $rows as $r ) {
            $row_bg = $ri % 2 === 0 ? '#fff' : '#f9fafb';
            $ri++;
            $cname = sre_get_name( $r->customer_email );
            echo '<tr style="background:' . $row_bg . ';border-bottom:1px solid #f3f4f6">';
            echo '<td style="padding:10px 14px">' . esc_html( $r->customer_email ) . ' <span style="color:#6b7280;font-size:12px">(' . esc_html( $cname ) . ')</span></td>';
            echo '<td style="padding:10px">' . sre_tier_badge( $r->tier ) . '</td>';
            echo '<td style="padding:10px;font-weight:600">' . number_format( $r->points ) . '</td>';
            echo '<td style="padding:10px">' . sre_money( $r->total_spent, 2 ) . '</td>';
            echo '<td style="padding:10px">' . (int) $r->total_orders . '</td>';
            echo '<td style="padding:10px;font-size:12px;color:#6b7280">' . ( $r->tier_updated_at !== '2000-01-01 00:00:00' ? esc_html( wp_date( 'M j, Y', strtotime( $r->tier_updated_at ) ) ) : '&mdash;' ) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';
    echo '<div style="margin-top:12px">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $pg, 'total' => ceil( $total / $per ) ) ) . '</div>';
    echo '</div>';
}

// ----------------------------------------------------------------
// FEATURE: CHURN RISK ALERTS
// ----------------------------------------------------------------
function sre_check_churn_alerts() {
    if ( ! sre_is_pro() || ! get_option( 'sre_churn_alerts_enabled' ) ) {
        return;
    }
    global $wpdb;
    $seg_tbl   = $wpdb->prefix . 'sre_segments';
    $threshold = (int) get_option( 'sre_churn_alert_threshold', 70 );
    $min_rev   = (float) get_option( 'sre_churn_alert_min_revenue', 100 );

    $customers = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$seg_tbl}
         WHERE risk_score >= %d
         AND total_revenue >= %f
         AND segment IN ('at_risk','churned')
         ORDER BY risk_score DESC, total_revenue DESC
         LIMIT 50",
        $threshold, $min_rev
    ) );

    if ( empty( $customers ) ) {
        return;
    }

    // 5.1.0: per-recipient suppression. Previously a single 30-day transient
    // suppressed ALL alerts after the first digest; brand-new high-risk
    // customers appearing in the same month never got surfaced. We now track
    // already-alerted emails individually and only filter those out.
    $sent_log = get_option( 'sre_churn_alert_sent_log', array() );
    if ( ! is_array( $sent_log ) ) {
        $sent_log = array();
    }
    $cutoff = time() - 30 * DAY_IN_SECONDS;
    // Prune old entries so the option does not grow unbounded.
    $sent_log = array_filter( $sent_log, function ( $ts ) use ( $cutoff ) {
        return (int) $ts >= $cutoff;
    } );

    $fresh_customers = array();
    foreach ( $customers as $c ) {
        if ( ! isset( $sent_log[ $c->customer_email ] ) ) {
            $fresh_customers[] = $c;
        }
    }
    if ( empty( $fresh_customers ) ) {
        // Every high-risk customer was already alerted within the window.
        return;
    }

    $alert_email = get_option( 'sre_churn_alert_email', '' );
    if ( empty( $alert_email ) ) {
        $alert_email = get_option( 'admin_email' );
    }
    if ( ! $alert_email ) {
        return;
    }

    $body  = sre_email_churn_alert( $fresh_customers );
    // 5.3.1: this is an internal admin digest, not a customer email — strip
    // the unsub placeholder so the admin does not see {{SRE_UNSUB_URL}} or
    // an irrelevant unsubscribe link in their own inbox.
    $body  = str_replace( '{{SRE_UNSUB_URL}}', esc_url( home_url( '/' ) ), $body );
    $count = count( $fresh_customers );
    $store = get_bloginfo( 'name' );
    $subject = '[' . $store . '] Churn Alert: ' . $count . ' high-value customer' . ( $count !== 1 ? 's' : '' ) . ' at risk';

    wp_mail( $alert_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

    // Mark each surfaced customer as alerted now.
    $now = time();
    foreach ( $fresh_customers as $c ) {
        $sent_log[ $c->customer_email ] = $now;
    }
    update_option( 'sre_churn_alert_sent_log', $sent_log, false );
}

function sre_email_churn_alert( $customers ) {
    $content = '<h2>Churn Risk Alert</h2>';
    $content .= '<p>The following high-value customers are at risk of churning. Consider reaching out with a personalized offer.</p>';
    $content .= '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:13px">';
    $content .= '<thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb">';
    $content .= '<th style="padding:10px;text-align:left">Customer</th>';
    $content .= '<th style="padding:10px;text-align:left">Segment</th>';
    $content .= '<th style="padding:10px;text-align:right">Risk Score</th>';
    $content .= '<th style="padding:10px;text-align:right">Revenue</th>';
    $content .= '<th style="padding:10px;text-align:right">Days Since Last</th>';
    $content .= '<th style="padding:10px;text-align:left">Action</th>';
    $content .= '</tr></thead><tbody>';
    foreach ( $customers as $c ) {
        $action = sre_segment_action( $c->segment, (int) $c->days_since_last, (int) $c->total_orders, $c->risk_score );
        $risk_color = $c->risk_score >= 80 ? '#dc2626' : ( $c->risk_score >= 60 ? '#d97706' : '#059669' );
        $content .= '<tr style="border-bottom:1px solid #f3f4f6">';
        $content .= '<td style="padding:10px">' . esc_html( $c->customer_email ) . '</td>';
        $content .= '<td style="padding:10px">' . esc_html( ucfirst( str_replace( '_', ' ', $c->segment ) ) ) . '</td>';
        $content .= '<td style="padding:10px;text-align:right;font-weight:700;color:' . $risk_color . '">' . (int) $c->risk_score . '%</td>';
        $content .= '<td style="padding:10px;text-align:right">' . sre_money( $c->total_revenue, 2 ) . '</td>';
        $content .= '<td style="padding:10px;text-align:right">' . (int) $c->days_since_last . 'd</td>';
        $content .= '<td style="padding:10px;font-size:12px">' . wp_strip_all_tags( $action ) . '</td>';
        $content .= '</tr>';
    }
    $content .= '</tbody></table>';
    $content .= '<p style="text-align:center;margin-top:18px"><a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder-customers&tab=segments&segment=at_risk' ) ) . '" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600">View At-Risk Customers</a></p>';
    return sre_email_wrap( $content );
}

// ----------------------------------------------------------------
// DASHBOARD WIDGET
// ----------------------------------------------------------------
function sre_register_dashboard_widget() {
    wp_add_dashboard_widget(
        'sre_dashboard_widget',
        '<span class="dashicons dashicons-superhero-alt" style="margin-right:6px;color:#2563eb"></span> Smart Reorder Emailer Pro',
        'sre_render_dashboard_widget'
    );
}

function sre_render_dashboard_widget() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        echo '<p style="color:#dc2626;font-weight:600;">WooCommerce is required for Smart Reorder Emailer Pro.</p>';
        return;
    }

    global $wpdb;

    $is_pro = sre_is_pro();
    $license_label = $is_pro ? 'Pro Active' : 'Free';
    $license_color = $is_pro ? '#059669' : '#6b7280';

    $total_customers = 0;
    $segments = array();
    $emails_sent = 0;
    $upcoming_reorders = 0;
    $active_ab_tests = 0;

    $seg_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . 'sre_segments' ) );
    if ( $seg_table_exists ) {
        $total_customers = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT customer_email) FROM {$wpdb->prefix}sre_segments" );
        $segments_raw = $wpdb->get_results( "SELECT segment, COUNT(*) as cnt FROM {$wpdb->prefix}sre_segments GROUP BY segment" );
        if ( $segments_raw ) {
            foreach ( $segments_raw as $s ) {
                $segments[ $s->segment ] = (int) $s->cnt;
            }
        }
        $upcoming_reorders = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sre_segments WHERE predicted_next IS NOT NULL AND predicted_next BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
        );
    }

    $segment_colors = array(
        'vip'     => '#7c3aed',
        'loyal'   => '#059669',
        'at_risk' => '#d97706',
        'churned' => '#dc2626',
        'new'     => '#2563eb',
    );

    $log_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . 'sre_email_log' ) );
    if ( $log_table_exists ) {
        $emails_sent = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sre_email_log" );
    }

    $ab_table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . 'sre_ab_tests' ) );
    if ( $ab_table_exists ) {
        $active_ab_tests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sre_ab_tests WHERE status='running'" );
    }

    echo '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif">';

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">';

    echo '<div style="padding:12px;background:#f8fafc;border-radius:6px">';
    echo '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">License</div>';
    echo '<div style="font-size:14px;font-weight:600;color:' . esc_attr( $license_color ) . '">' . esc_html( $license_label ) . '</div>';
    echo '</div>';

    echo '<div style="padding:12px;background:#f8fafc;border-radius:6px">';
    echo '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Customers Tracked</div>';
    echo '<div style="font-size:20px;font-weight:700;color:#1e293b">' . number_format( $total_customers ) . '</div>';
    echo '</div>';

    echo '<div style="padding:12px;background:#f8fafc;border-radius:6px">';
    echo '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Emails Sent</div>';
    echo '<div style="font-size:20px;font-weight:700;color:#1e293b">' . number_format( $emails_sent ) . '</div>';
    echo '</div>';

    echo '<div style="padding:12px;background:#f8fafc;border-radius:6px">';
    echo '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px">Upcoming Reorders (7d)</div>';
    echo '<div style="font-size:20px;font-weight:700;color:#2563eb">' . number_format( $upcoming_reorders ) . '</div>';
    echo '</div>';

    echo '</div>';

    if ( ! empty( $segments ) ) {
        echo '<div style="margin-bottom:16px">';
        echo '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px">Segments</div>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:6px">';
        foreach ( $segments as $seg => $count ) {
            $color = isset( $segment_colors[ $seg ] ) ? $segment_colors[ $seg ] : '#6b7280';
            $label = ucfirst( str_replace( '_', ' ', $seg ) );
            echo '<span style="display:inline-block;padding:4px 10px;background:' . esc_attr( $color ) . '1a;color:' . esc_attr( $color ) . ';border-radius:12px;font-size:12px;font-weight:600">';
            echo esc_html( $label ) . ' ' . number_format( $count );
            echo '</span>';
        }
        echo '</div>';
        echo '</div>';
    }

    echo '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-top:1px solid #e2e8f0;font-size:13px;color:#475569">';
    echo '<span>Active A/B Tests</span>';
    echo '<span style="font-weight:600;color:#1e293b">' . (int) $active_ab_tests . '</span>';
    echo '</div>';

    echo '<div style="text-align:center;margin-top:16px">';
    echo '<a href="' . esc_url( admin_url( 'admin.php?page=smart-reorder' ) ) . '" style="display:inline-block;padding:8px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-size:13px;font-weight:600">';
    echo 'Open AI Reorder Dashboard &rarr;';
    echo '</a>';
    echo '</div>';

    echo '</div>';
}

