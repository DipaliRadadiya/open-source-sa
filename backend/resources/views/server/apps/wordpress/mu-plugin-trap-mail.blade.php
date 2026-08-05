{!! '<?php' !!}
/**
 * Plugin Name: Panel Staging Mail Trap
 * Description: Written by the panel onto staging sites only. Short-circuits
 * every outbound email so a clone that inherited real customer data can
 * never actually send to them — WooCommerce order emails, drip campaigns,
 * password resets, anything wp_mail() would normally deliver.
 */

add_filter('pre_wp_mail', function ($null, $atts) {
    // Returning non-null skips wp_mail()'s own send path entirely — this
    // runs before any transport (PHPMailer, an SMTP plugin) is touched, so
    // there is no configuration on the staging site that could accidentally
    // re-enable delivery.
    return true;
}, 10, 2);
