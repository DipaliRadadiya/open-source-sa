import { test } from "node:test";
import assert from "node:assert/strict";
import {
  CHUNK_THRESHOLD_BYTES,
  MAX_CHUNK_BYTES,
  chunkSizeFor,
} from "../lib/files/chunk-size.js";

const MB = 1024 * 1024;
const GB = 1024 * MB;

test("small files use the smallest chunk", () => {
  // Round trips are not the cost at this size, and a bigger chunk cannot make
  // a 40 MB upload meaningfully faster.
  assert.equal(chunkSizeFor(40 * MB), 8 * MB);
  assert.equal(chunkSizeFor(512 * MB), 8 * MB);
});

test("chunk size steps up with the file, because each chunk costs a round trip", () => {
  // Chunks go up one at a time, so latency is per chunk. A 5 GB file at 8 MB
  // is 640 round trips; at 32 MB it is 160.
  assert.equal(chunkSizeFor(512 * MB + 1), 16 * MB);
  assert.equal(chunkSizeFor(5 * GB), 16 * MB);
  assert.equal(chunkSizeFor(5 * GB + 1), 32 * MB);
  assert.equal(chunkSizeFor(50 * GB), 32 * MB);
});

test("never exceeds what nginx will buffer in memory", () => {
  // The ceiling is client_body_buffer_size (36M) on the panel vhost. Above it
  // nginx spills the body to disk and every uploaded byte is written twice —
  // which would land hardest on exactly the large files this ladder is for.
  //
  // If this fails, install.sh's client_body_buffer_size has to move in the
  // same change.
  const NGINX_BODY_BUFFER = 36 * MB;

  for (const size of [1, 40 * MB, 512 * MB, 2 * GB, 5 * GB, 50 * GB, 500 * GB]) {
    assert.ok(
      chunkSizeFor(size) < NGINX_BODY_BUFFER,
      `chunk for ${size} bytes must stay under the nginx buffer`,
    );
  }

  assert.ok(MAX_CHUNK_BYTES < NGINX_BODY_BUFFER);
});

test("never exceeds what nginx will accept at all", () => {
  // client_max_body_size is 64M. A chunk over it would be refused outright
  // with a 413 rather than merely spilling to disk.
  assert.ok(MAX_CHUNK_BYTES < 64 * MB);
});

test("the ladder only ever goes up", () => {
  // A step that went backwards would mean a larger file getting more round
  // trips than a smaller one — the opposite of the point.
  let previous = 0;
  for (const size of [1, 100 * MB, 512 * MB, 513 * MB, 5 * GB, 6 * GB, 100 * GB]) {
    const chunk = chunkSizeFor(size);
    assert.ok(chunk >= previous, `chunk shrank at ${size} bytes`);
    previous = chunk;
  }
});

test("files at or under the threshold do not chunk at all", () => {
  // Documented here because the two constants have to agree: a file that
  // takes the single-shot path never reaches chunkSizeFor.
  assert.equal(CHUNK_THRESHOLD_BYTES, 32 * MB);
});
