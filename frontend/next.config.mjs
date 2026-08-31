import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import createNextIntlPlugin from 'next-intl/plugin';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const withNextIntl = createNextIntlPlugin('./i18n/request.js');

/**
 * How many workers `next build` may fork for page-data collection and static
 * generation. Next defaults this to the CPU count, which is the right answer
 * on a build server and the wrong one on the 1-2 GB VPS this panel is meant to
 * run on: every worker is a full Node process holding its own copy of the
 * compiled app, so the fan-out is what turns a build that compiled fine into a
 * build the kernel kills with a bare "Killed".
 *
 * Measured on this app (725 files, 58 routes) inside a memory cgroup: a
 * 2 vCPU / 2.5 GB box is OOM-killed during static generation at the default
 * worker count, and finishes in 41s with one worker. Two seconds of wall clock
 * on a big box buys the small box the difference between working and not.
 *
 * Derived from MemAvailable, not MemTotal or the core count: what matters is
 * the memory the kernel believes it can hand out right now, on a box already
 * running the API, the database and every hosted site.
 *
 * The escape hatch is PANEL_BUILD_CPUS, so this can be undone on a server
 * without editing code or shipping a release:
 *   unset -> derived from available memory (the behaviour described above)
 *   0     -> leave Next's own default entirely alone
 *   n     -> use exactly n workers
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
