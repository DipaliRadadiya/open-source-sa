// Larger files get larger chunks, sent sequentially (the server appends to one part file).
// Every value MUST stay under the panel vhost's nginx `client_body_buffer_size` (36M); raise both together.

/** @type {Array<{ upTo: number, chunk: number }>} */
const LADDER = [
  { upTo: 512 * 1024 * 1024, chunk: 8 * 1024 * 1024 },
  { upTo: 5 * 1024 * 1024 * 1024, chunk: 16 * 1024 * 1024 },
];

/** Anything past the ladder's last step. Must stay under the nginx buffer. */
export const MAX_CHUNK_BYTES = 32 * 1024 * 1024;

// Files at or under this go in one request; must stay below the pool's post_max_size (64M).
export const CHUNK_THRESHOLD_BYTES = 32 * 1024 * 1024;

export function chunkSizeFor(fileSize) {
  return LADDER.find((step) => fileSize <= step.upTo)?.chunk ?? MAX_CHUNK_BYTES;
}
