{!! '<?php' !!}
/**
 * Plugin Name: Staging Mail Trap
 * Plugin URI:  https://serveravatar.com/docs/staging
 * Description: Blocks ALL outgoing email from this staging site so a copy of a live site cannot email real customers. Added automatically by your hosting control panel when this staging site was created. This is not malware and was not added by an attacker — see the notes below. It is never copied to your live site.
 * Version:     1.0.0
 * Author:      Your hosting control panel
 * License:     GPL-2.0-or-later
 *
 * -------------------------------------------------------------------------
 * IF YOU FOUND THIS FILE AND ARE WORRIED IT IS A HACK — IT IS NOT.
 * -------------------------------------------------------------------------
 *
 * WHAT IT IS
 *   Your hosting control panel wrote this file when it created the staging
 *   copy of your website. A staging site is a full clone, which means it
 *   contains a copy of your real orders, customers and subscribers.
 *
 * WHY IT EXISTS
 *   Without it, ordinary background activity on the clone would send real
 *   email to real people: WooCommerce order and shipping notifications,
 *   password resets, abandoned-cart reminders, newsletter batches. Customers
 *   would receive duplicate or nonsense messages from a site that is only
 *   meant for testing. This file stops every one of them at the door.
 *
 * WHAT IT DOES
 *   Exactly one thing: it makes wp_mail() return without sending. Nothing is
 *   collected, nothing is transmitted anywhere, no data leaves this server,
 *   and nothing else on the site is modified. The whole plugin is the few
 *   lines below — you can read all of it.
 *
 * WHERE IT RUNS
 *   On this staging site only. It is excluded from the push to your live
 *   site, so pushing staging to production can never carry it across and can
 *   never stop your live site sending email.
 *
 * HOW TO REMOVE IT
 *   Delete this file. Email from this staging site will start being
 *   delivered for real immediately afterwards — including to real customers
 *   in the cloned data — so only do that if that is genuinely what you want.
 *
 * WHY IT IS A MUST-USE PLUGIN
 *   Files in wp-content/mu-plugins/ load automatically and cannot be
 *   deactivated from the Plugins screen. That is deliberate: a safety net
 *   that can be switched off by accident on a site full of real customer
 *   addresses is not a safety net.
 */

add_filter('pre_wp_mail', function ($null, $atts) {
    // Returning non-null skips wp_mail()'s own send path entirely — this
    // runs before any transport (PHPMailer, an SMTP plugin) is touched, so
    // there is no configuration on the staging site that could accidentally
    // re-enable delivery.
    return true;
}, 10, 2);
