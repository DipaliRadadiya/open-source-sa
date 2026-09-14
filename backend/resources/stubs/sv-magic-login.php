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

    // Consumed before it is checked, not after. Deleting on the way out leaves
    // a window in which two concurrent requests both read a valid token; the
    // second one should find nothing whatever the first one decides.
    delete_option(SV_MAGIC_LOGIN_OPTION);

    if (! is_array($stored) || empty($stored['hash']) || empty($stored['user_id']) || empty($stored['expires_at'])) {
        return;
    }

    if (time() > (int) $stored['expires_at']) {
        return;
    }

    // Constant time: a timing-comparable check on a secret this valuable is
    // worth avoiding even though the token is single-use and short-lived.
    if (! hash_equals((string) $stored['hash'], hash('sha256', $presented))) {
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
