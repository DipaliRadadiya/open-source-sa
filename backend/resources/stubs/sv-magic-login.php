<?php
/**
 * Plugin Name: ServerAvatar Magic Login
 * Description: Consumes a single-use, short-lived token minted by the panel and signs the named administrator in. Inert without one.
 * Author: ServerAvatar
 *
 * Managed by the panel. Manual edits are overwritten.
 *
 * ── Why this file is permanent ──────────────────────────────────────────────
 * It stays in mu-plugins after a login rather than being written and deleted
 * per use. A file that is usually absent is worse: any crash between writing
 * and deleting leaves a live loader behind that nothing is tracking, and the
 * window where it exists is exactly the window nobody is looking. Permanent,
 * readable and version-controlled beats intermittent and untracked.
 *
 * It is also inert on its own. With no token option in the database this file
 * does nothing at all — the security boundary is the token, not the presence
 * of the loader, which is the same model as a password-reset link.
 *
 * ── Why POST ────────────────────────────────────────────────────────────────
 * The token arrives as a form POST, never a query string. A token in the URL is
 * written to the web server's access log, the browser's history and any
 * outbound Referer header, and this token is worth full administrator access.
 * The legacy implementation put it in the URL; this does not.
 */

if (! defined('ABSPATH')) {
    exit;
}

const SV_MAGIC_LOGIN_OPTION = 'sv_magic_login_token';
const SV_MAGIC_LOGIN_FIELD = 'sv_magic_login';

add_action('init', 'sv_magic_login_maybe_authenticate', 1);

function sv_magic_login_maybe_authenticate()
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST[SV_MAGIC_LOGIN_FIELD])) {
        return;
    }

    $presented = (string) $_POST[SV_MAGIC_LOGIN_FIELD];
    $stored = get_option(SV_MAGIC_LOGIN_OPTION);

    if (! is_array($stored) || empty($stored['hash']) || empty($stored['user_id']) || empty($stored['expires_at'])) {
        return;
    }

    // A spent token is cleaned up, and nothing more.
    if (time() > (int) $stored['expires_at']) {
        delete_option(SV_MAGIC_LOGIN_OPTION);

        return;
    }

    // Constant time: a timing-comparable check on a secret this valuable is
    // worth avoiding even though the token is single-use and short-lived.
    //
    // A wrong value leaves the token where it is. It used to be deleted
    // before this check, so any visitor posting the field with any value
    // cancelled the administrator's pending login (bug #97). Guessing is not
    // the risk that ordering guarded against: the token is 64 random
    // characters and lives for seconds.
    if (! hash_equals((string) $stored['hash'], hash('sha256', $presented))) {
        return;
    }

    // Consumed here, and only one request can consume it: delete_option()
    // is true only for the request whose DELETE removed the row. Two
    // concurrent requests with the right token both get this far, and the
    // second finds nothing to delete.
    if (! delete_option(SV_MAGIC_LOGIN_OPTION)) {
        return;
    }

    $user = get_user_by('id', (int) $stored['user_id']);

    // Re-checked at the moment of use, not trusted from when the token was
    // minted. A role can be demoted in the seconds between, and the panel's
    // answer to "is this an administrator" must not outlive the fact.
    if (! $user || ! user_can($user, 'manage_options')) {
        return;
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, false, is_ssl());
    do_action('wp_login', $user->user_login, $user);

    wp_safe_redirect(admin_url());
    exit;
}
