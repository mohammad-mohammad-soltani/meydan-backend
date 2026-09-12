import test from "node:test";
import assert from "node:assert/strict";
import { notificationForSocket, notificationRowsAfter } from "../src/notifications.mjs";

test("notificationForSocket exposes the fields the frontend notification mapper needs", () => {
  const payload = notificationForSocket({
    id: 31,
    recipient_user_id: 7,
    type: "comment_reply",
    title: "پاسخ جدید",
    body: "علی به نظر شما پاسخ داد.",
    deep_link: "/posts/12#comment-31",
    created_at: "2026-09-12 10:30:00",
    read_at: null,
  });
  assert.deepEqual(payload, {
    id: "31",
    type: "comment_reply",
    title: "پاسخ جدید",
    body: "علی به نظر شما پاسخ داد.",
    deep_link: "/posts/12#comment-31",
    created_at: "2026-09-12T10:30:00.000Z",
    read_at: null,
  });
});

test("notificationRowsAfter selects only rows newer than the per-user cursor", () => {
  const rows = [{ id: 10 }, { id: 11 }, { id: 12 }];
  assert.deepEqual(notificationRowsAfter(rows, 10).map((row) => row.id), [11, 12]);
  assert.deepEqual(notificationRowsAfter(rows, 12), []);
});
