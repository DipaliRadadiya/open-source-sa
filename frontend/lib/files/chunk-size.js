/**
 * How large an upload chunk should be, by file size.
 *
 * Chunks go up one at a time, so every chunk costs a full round trip. On a
 * 200ms link that is 200ms of waiting each, whatever the bandwidth: a 5 GB
 * file at 8 MB is 640 chunks and over two minutes spent purely on latency.
 * The same file at 32 MB is 160 chunks and about thirty seconds. Bandwidth
 * does not change — this buys back the waiting, which is what dominates a
 * large upload over a distant or congested link.
 *
 * Sequential is deliberate and stays: the server appends each chunk to one
 * part file, so out-of-order arrivals would corrupt it, and the panel's FPM
 * pool is ten workers wide — parallel chunks would let one upload crowd out
 * the rest of the panel. Larger chunks are the lever that does not cost
 * either of those.
 *
 * **The ceiling is nginx, not the file.** Every value here must stay under
 * `client_body_buffer_size` on the panel vhost (36M, set by install.sh).
 * Above it nginx spills the request body to client_body_temp and every
 * uploaded byte is written twice — the exact cost the chunked design exists
 * to avoid, and it would land hardest on the largest files, which are the
 * ones this ladder is for. Raising a step means raising that buffer in the
 * same change.
 *
 * Kept in its own module so it can be tested without pulling in the API
 * client, which the test runner cannot resolve.
 */

/** @type {Array<{ upTo: number, chunk: number }>} */
const LADDER = [
  { upTo: 512 * 1024 * 1024, chunk: 8 * 1024 * 1024 },
  { upTo: 5 * 1024 * 1024 * 1024, chunk: 16 * 1024 * 1024 },
];

/** Anything past the ladder's last step. Must stay under the nginx buffer. */
export const MAX_CHUNK_BYTES = 32 * 1024 * 1024;

/**
 * Files at or under this go in a single request instead. Three extra round
 * trips to move a 2 KB file is worse than the thing chunking avoids, and it
 * sits below the panel pool's post_max_size (64M) with room to spare, so a
 * single-shot upload can never be the thing that fails.
 */
export const CHUNK_THRESHOLD_BYTES = 32 * 1024 * 1024;

export function chunkSizeFor(fileSize) {
  return LADDER.find((step) => fileSize <= step.upTo)?.chunk ?? MAX_CHUNK_BYTES;
}
