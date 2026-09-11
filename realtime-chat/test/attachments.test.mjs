import test from "node:test";
import assert from "node:assert/strict";
import { attachmentForStorage, normalizeAttachment } from "../src/attachments.mjs";

test("normalizes legacy camelCase attachment rows", () => {
  assert.deepEqual(normalizeAttachment({ id: "5", name: "photo.jpg", mimeType: "image/jpeg", size: 12, url: "https://cdn/photo.jpg" }), {
    id: "5",
    name: "photo.jpg",
    mimeType: "image/jpeg",
    size: 12,
    url: "https://cdn/photo.jpg",
  });
});

test("normalizes canonical snake_case attachment rows", () => {
  assert.equal(normalizeAttachment({ mime_type: "video/mp4" })?.mimeType, "video/mp4");
});

test("stores attachment metadata in REST-compatible snake_case", () => {
  assert.deepEqual(attachmentForStorage({ id: "9", name: "voice.ogg", mimeType: "audio/ogg", size: 42, url: "https://cdn/voice.ogg" }), {
    id: "9",
    name: "voice.ogg",
    mime_type: "audio/ogg",
    size: 42,
    url: "https://cdn/voice.ogg",
  });
});
