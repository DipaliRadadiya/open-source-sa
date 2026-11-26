// Every chunk size must stay under nginx client_body_buffer_size (36M) on the panel vhost.

const LADDER = [
  { upTo: 512 * 1024 * 1024, chunk: 8 * 1024 * 1024 },
  { upTo: 5 * 1024 * 1024 * 1024, chunk: 16 * 1024 * 1024 },
];

// Must stay under the nginx buffer.
export const MAX_CHUNK_BYTES = 32 * 1024 * 1024;

// Must stay below post_max_size (64M).
export const CHUNK_THRESHOLD_BYTES = 32 * 1024 * 1024;

export function chunkSizeFor(fileSize) {
  return LADDER.find((step) => fileSize <= step.upTo)?.chunk ?? MAX_CHUNK_BYTES;
}
