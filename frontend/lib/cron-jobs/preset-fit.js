// The presets are host commands against an application's folder (`php {path}/…`),
// so each fits only applications whose code is in that folder and run by the
// server's PHP. A Docker application's code is inside its container: none fit it.
const PHP_SERVED = (application) => application.serving_profile === "php";
const OF_TYPE = (type) => (application) => PHP_SERVED(application) && application.site_type === type;

const FITS = {
  // Laravel runs as a custom PHP site or a PHP site deployed from git.
  laravel: (application) => PHP_SERVED(application) && ["php", "git"].includes(application.site_type),
  wordpress: OF_TYPE("wordpress"),
  moodle: OF_TYPE("moodle"),
  joomla: OF_TYPE("joomla"),
  nextcloud: OF_TYPE("nextcloud"),
  craftcms: OF_TYPE("craftcms"),
  php_script: PHP_SERVED,
};

/** Whether `application` can run preset `key`. A preset added later defaults to any PHP-served application. */
export function presetFits(key, application) {
  return (FITS[key] ?? PHP_SERVED)(application ?? {});
}

/**
 * The presets worth offering on this server. Custom (no command) always is.
 * When the applications could not be read, every preset stays: an unanswered
 * request is not evidence that none fit.
 */
export function availablePresets(presets = [], applications = [], known = true) {
  if (!known) return presets;
  return presets.filter((preset) => !preset.command || applications.some((application) => presetFits(preset.key, application)));
}
