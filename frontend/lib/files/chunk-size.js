/**
 * Upload chunk size by file size. Larger files get larger chunks to cut
 * per-chunk round trips. Chunks stay sequential: the server appends to one
 * part file, and the panel's FPM pool is small.
 *
 * Every value MUST stay under nginx `client_body_buffer_size` on the panel
 * vhost (36M, set by install.sh), or bodies spill to disk. Raise both together.
 *
 * Own module so it can be tested without the API client.
 */

/** @type {Array<{ upTo: number, chunk: number }>} */
const LADDER = [
  { upTo: 512 * 1024 * 1024, chunk: 8 * 1024 * 1024 },
  { upTo: 5 * 1024 * 1024 * 1024, chunk: 16 * 1024 * 1024 },
];

/** Anything past the ladder's last step. Must stay under the nginx buffer. */
export const MAX_CHUNK_BYTES = 32 * 1024 * 1024;

/**
 * Files at or under this go in a single request. Must stay below the panel
 * pool's post_max_size (64M).
 */
export const CHUNK_THRESHOLD_BYTES = 32 * 1024 * 1024;

export function chunkSizeFor(fileSize) {
  return LADDER.find((step) => fileSize <= step.upTo)?.chunk ?? MAX_CHUNK_BYTES;
}
