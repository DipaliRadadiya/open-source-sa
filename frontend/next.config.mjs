import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import createNextIntlPlugin from 'next-intl/plugin';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const withNextIntl = createNextIntlPlugin('./i18n/request.js');

/**
 * How many workers `next build` may fork. Next defaults to the CPU count, and each worker is a
 * full Node process: a 2 vCPU / 2.5 GB box was OOM-killed at the default and built in 41s with
 * one. So the count is derived from MemAvailable. PANEL_BUILD_CPUS overrides it:
 *   unset -> derived from available memory
 *   0     -> Next's own default
 *   n     -> exactly n workers
 */
function buildWorkers() {
  const override = process.env.PANEL_BUILD_CPUS;

  if (override !== undefined && override !== '') {
    const parsed = Number.parseInt(override, 10);

    if (Number.isNaN(parsed) || parsed < 1) {
      return undefined; // `0` (or junk) means: do not set the option at all.
    }

    return parsed;
  }

  // Not readable off Linux, and on a machine whose memory we cannot measure a
  // guess is worse than Next's default -- it would cap a build server too.
  let availableMb;

  try {
    const meminfo = fs.readFileSync('/proc/meminfo', 'utf8');
    const match = meminfo.match(/^MemAvailable:\s+(\d+) kB$/m);

    if (!match) {
      return undefined;
    }

    availableMb = Math.floor(Number(match[1]) / 1024);
  } catch {
    return undefined;
  }

  const cores = Math.max(1, os.cpus()?.length ?? 1);

  // Roughly a worker per spare gigabyte, floored at one and never more than
  // the box has cores. Above ~6 GB the fan-out is not what is at risk, so
  // Next's default is left in place rather than second-guessed.
  if (availableMb >= 6144) {
    return undefined;
  }

  if (availableMb >= 3072) {
    return Math.min(2, cores);
  }

  return 1;
}

const workers = buildWorkers();

/** @type {import('next').NextConfig} */
const nextConfig = {
  output: 'standalone',
  outputFileTracingRoot: __dirname,
  ...(workers === undefined ? {} : { experimental: { cpus: workers } }),
};

export default withNextIntl(nextConfig);
