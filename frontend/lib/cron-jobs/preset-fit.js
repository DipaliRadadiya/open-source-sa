const PHP_SERVED = (application) => application.serving_profile === "php";
const OF_TYPE = (type) => (application) => PHP_SERVED(application) && application.site_type === type;

const FITS = {
  laravel: (application) => PHP_SERVED(application) && ["php", "git"].includes(application.site_type),
  wordpress: OF_TYPE("wordpress"),
  moodle: OF_TYPE("moodle"),
  joomla: OF_TYPE("joomla"),
  nextcloud: OF_TYPE("nextcloud"),
  craftcms: OF_TYPE("craftcms"),
  php_script: PHP_SERVED,
};

export function presetFits(key, application) {
  return (FITS[key] ?? PHP_SERVED)(application ?? {});
}

export function availablePresets(presets = [], applications = [], known = true) {
  if (!known) return presets;
  return presets.filter((preset) => !preset.command || applications.some((application) => presetFits(preset.key, application)));
}
