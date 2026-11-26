import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import createNextIntlPlugin from 'next-intl/plugin';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const withNextIntl = createNextIntlPlugin('./i18n/request.js');

function buildWorkers() {
  const override = process.env.PANEL_BUILD_CPUS;

  if (override !== undefined && override !== '') {
    const parsed = Number.parseInt(override, 10);

    if (Number.isNaN(parsed) || parsed < 1) {
      return undefined;
    }

    return parsed;
  }

  // Can't read memory off Linux; leave Next's default.
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

  // About one worker per spare GB, at least 1 and at most the core count. Above ~6 GB use Next's default.
  if (availableMb >= 6144) {
    return undefined;
  }

  if (availableMb >= 3072) {
    return Math.min(2, cores);
  }

  return 1;
}

const workers = buildWorkers();

const nextConfig = {
  output: 'standalone',
  outputFileTracingRoot: __dirname,
  ...(workers === undefined ? {} : { experimental: { cpus: workers } }),
};

export default withNextIntl(nextConfig);
