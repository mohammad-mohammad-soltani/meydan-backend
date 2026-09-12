import http from "node:http";
import mysql from "mysql2/promise";
import { Server } from "socket.io";
import { attachmentForStorage, normalizeAttachment } from "./attachments.mjs";
import { verifySocketTicket } from "./auth.mjs";
import { notificationForSocket, notificationRowsAfter } from "./notifications.mjs";

const port = Number(process.env.PORT || 3001);
const prefix = process.env.DB_PREFIX || "wp_";
if (!/^[A-Za-z0-9_]+$/.test(prefix)) throw new Error("invalid DB_PREFIX");
const table = (name) => `${prefix}meydan_chat_${name}`;
const notificationsTable = `${prefix}meydan_notifications`;
const socketSecret = process.env.MEYDAN_CHAT_SOCKET_SECRET || "";
if (!socketSecret) throw new Error("MEYDAN_CHAT_SOCKET_SECRET is required");

const pool = mysql.createPool({
  host: process.env.DB_HOST || "db",
  port: Number(process.env.DB_PORT || 3306),
  user: process.env.DB_USER || "meydan",
  password: process.env.DB_PASSWORD || "",
  database: process.env.DB_NAME || "meydan",
  connectionLimit: 10,
  timezone: "Z",
  charset: "utf8mb4",
});

const origins = (process.env.MEYDAN_CHAT_CORS_ORIGIN || "*").split(",").map((item) => item.trim()).filter(Boolean);
const server = http.createServer((req, res) => {
  if (req.url === "/health") {
    res.writeHead(200, { "content-type": "application/json" });
    res.end(JSON.stringify({ ok: true }));
    return;
  }
  res.writeHead(404).end();
});
const io = new Server(server, {
  cors: { origin: origins.length === 1 && origins[0] === "*" ? "*" : origins, methods: ["GET", "POST"] },
  transports: ["websocket", "polling"],
  pingInterval: 25000,
  pingTimeout: 20000,
  maxHttpBufferSize: 1_000_000,
});

const presence = new Map();
const notificationCursors = new Map();
const room = (conversationId) => `conversation:${conversationId}`;
const userRoom = (userId) => `user:${userId}`;
const asId = (value) => {
  const id = Number(value);
  return Number.isInteger(id) && id > 0 ? id : 0;
};
const ack = (callback, payload) => typeof callback === "function" && callback(payload);

async function isMember(conversationId, userId) {
  const [rows] = await pool.execute(
    `SELECT 1 FROM ${table("participants")} WHERE conversation_id=? AND user_id=? AND archived_at IS NULL LIMIT 1`,
    [conversationId, userId],
  );
  return rows.length > 0;
}

async function notifyConversationUsers(conversationId, event, payload) {
  const [rows] = await pool.execute(
    `SELECT user_id FROM ${table("participants")} WHERE conversation_id=? AND archived_at IS NULL`,
    [conversationId],
  );
  for (const participant of rows) io.to(userRoom(participant.user_id)).emit(event, payload);
}

async function latestNotificationId(userId) {
  const [rows] = await pool.execute(
    `SELECT COALESCE(MAX(id),0) AS id FROM ${notificationsTable} WHERE recipient_user_id=?`,
    [userId],
  );
  return Number(rows[0]?.id || 0);
}

async function flushUserNotifications(userId) {
  const cursor = notificationCursors.get(userId);
  if (cursor === undefined || cursor === null) return;
  const [rows] = await pool.execute(
    `SELECT id,type,title,body,deep_link,created_at,read_at FROM ${notificationsTable} WHERE recipient_user_id=? AND id>? ORDER BY id ASC LIMIT 100`,
    [userId, cursor],
  );
  const fresh = notificationRowsAfter(rows, cursor);
  for (const row of fresh) io.to(userRoom(userId)).emit("notification:created", notificationForSocket(row));
  if (fresh.length) notificationCursors.set(userId, Number(fresh[fresh.length - 1].id));
}

async function flushNotifications() {
  for (const userId of presence.keys()) await flushUserNotifications(userId);
}

const notificationTimer = setInterval(() => {
  void flushNotifications().catch((error) => console.error("notification:poll", error));
}, 2000);
notificationTimer.unref?.();

async function messageById(messageId) {
  const [rows] = await pool.execute(`SELECT * FROM ${table("messages")} WHERE id=? LIMIT 1`, [messageId]);
  const row = rows[0];
  if (!row) return null;
  const [reactionRows] = await pool.execute(`SELECT reaction FROM ${table("reactions")} WHERE message_id=? ORDER BY created_at ASC`, [messageId]);
  let attachment;
  if (row.attachment_json && !row.deleted_at) {
    try { attachment = normalizeAttachment(JSON.parse(row.attachment_json)); } catch { attachment = undefined; }
  }
  let replyTo;
  if (row.reply_to_id) {
    const [replyRows] = await pool.execute(
      `SELECT m.id,m.body,u.display_name FROM ${table("messages")} m LEFT JOIN ${prefix}users u ON u.ID=m.sender_user_id WHERE m.id=? LIMIT 1`,
      [row.reply_to_id],
    );
    const reply = replyRows[0];
    if (reply) replyTo = { id: String(reply.id), body: reply.body || "", senderName: reply.display_name || "کاربر" };
  }
  return {
    id: String(row.id),
    conversationId: String(row.conversation_id),
    senderId: String(row.sender_user_id),
    clientId: row.client_id,
    body: row.deleted_at ? "" : row.body || "",
    sentAt: new Date(`${row.created_at}Z`).toISOString(),
    editedAt: row.edited_at ? new Date(`${row.edited_at}Z`).toISOString() : undefined,
    deletedAt: row.deleted_at ? new Date(`${row.deleted_at}Z`).toISOString() : undefined,
    attachment,
    replyTo,
    reactions: reactionRows.map((item) => item.reaction),
  };
}

async function requireMessageMember(messageId, userId) {
  const [rows] = await pool.execute(
    `SELECT m.conversation_id,m.sender_user_id FROM ${table("messages")} m INNER JOIN ${table("participants")} p ON p.conversation_id=m.conversation_id AND p.user_id=? AND p.archived_at IS NULL WHERE m.id=? LIMIT 1`,
    [userId, messageId],
  );
  return rows[0] || null;
}

io.use((socket, next) => {
  try {
    const payload = verifySocketTicket(socket.handshake.auth?.ticket, socketSecret);
    socket.data.userId = payload.uid;
    next();
  } catch (error) {
    next(new Error(error instanceof Error ? error.message : "unauthorized"));
  }
});

io.on("connection", (socket) => {
  const userId = socket.data.userId;
  socket.join(userRoom(userId));
  if (!notificationCursors.has(userId)) {
    notificationCursors.set(userId, null);
    void latestNotificationId(userId)
      .then((id) => {
        if (presence.has(userId)) notificationCursors.set(userId, id);
      })
      .catch((error) => {
        notificationCursors.delete(userId);
        console.error("notification:cursor", error);
      });
  }
  presence.set(userId, (presence.get(userId) || 0) + 1);
  io.emit("presence:changed", { userId: String(userId), online: true });

  socket.on("conversation:join", async ({ conversationId } = {}, callback) => {
    try {
      const id = asId(conversationId);
      if (!id || !(await isMember(id, userId))) return ack(callback, { ok: false, error: "forbidden" });
      await socket.join(room(id));
      ack(callback, { ok: true });
    } catch { ack(callback, { ok: false, error: "internal_error" }); }
  });

  socket.on("conversation:leave", ({ conversationId } = {}) => {
    const id = asId(conversationId);
    if (id) void socket.leave(room(id));
  });

  socket.on("message:send", async (input = {}, callback) => {
    try {
      const conversationId = asId(input.conversationId);
      if (!conversationId || !(await isMember(conversationId, userId))) return ack(callback, { ok: false, error: "forbidden" });
      const clientId = String(input.clientId || "").slice(0, 80);
      const body = String(input.body || "").trim().slice(0, 10000);
      const attachment = attachmentForStorage(input.attachment);
      if (!clientId || (!body && !attachment)) return ack(callback, { ok: false, error: "invalid_message" });
      const replyToId = asId(input.replyToId) || null;
      const forwardedFromMessageId = asId(input.forwardedFromMessageId) || null;
      for (const relatedId of [replyToId, forwardedFromMessageId]) {
        if (!relatedId) continue;
        const [relatedRows] = await pool.execute(`SELECT 1 FROM ${table("messages")} WHERE id=? AND conversation_id=? LIMIT 1`, [relatedId, conversationId]);
        if (!relatedRows.length) return ack(callback, { ok: false, error: "invalid_reference" });
      }
      const [result] = await pool.execute(
        `INSERT INTO ${table("messages")} (conversation_id,sender_user_id,client_id,body,reply_to_id,forwarded_from_message_id,attachment_json,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)`,
        [conversationId, userId, clientId, body, replyToId, forwardedFromMessageId, attachment ? JSON.stringify(attachment) : null],
      );
      let messageId = Number(result.insertId || 0);
      if (!messageId) {
        const [existing] = await pool.execute(`SELECT id FROM ${table("messages")} WHERE sender_user_id=? AND client_id=? LIMIT 1`, [userId, clientId]);
        messageId = Number(existing[0]?.id || 0);
      }
      await pool.execute(`UPDATE ${table("conversations")} SET last_message_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?`, [messageId, conversationId]);
      const message = await messageById(messageId);
      io.to(room(conversationId)).emit("message:created", message);
      await notifyConversationUsers(conversationId, "conversation:updated", { conversationId: String(conversationId), message });
      ack(callback, { ok: true, message });
    } catch (error) {
      console.error("message:send", error);
      ack(callback, { ok: false, error: "internal_error" });
    }
  });

  socket.on("message:edit", async ({ messageId, body } = {}, callback) => {
    try {
      const id = asId(messageId);
      const membership = id ? await requireMessageMember(id, userId) : null;
      if (!membership || Number(membership.sender_user_id) !== userId) return ack(callback, { ok: false, error: "forbidden" });
      const nextBody = String(body || "").trim().slice(0, 10000);
      if (!nextBody) return ack(callback, { ok: false, error: "invalid_message" });
      await pool.execute(`UPDATE ${table("messages")} SET body=?,edited_at=UTC_TIMESTAMP() WHERE id=? AND deleted_at IS NULL`, [nextBody, id]);
      const message = await messageById(id);
      io.to(room(membership.conversation_id)).emit("message:updated", message);
      await notifyConversationUsers(membership.conversation_id, "conversation:updated", { conversationId: String(membership.conversation_id), message });
      ack(callback, { ok: true, message });
    } catch { ack(callback, { ok: false, error: "internal_error" }); }
  });

  socket.on("message:delete", async ({ messageId } = {}, callback) => {
    try {
      const id = asId(messageId);
      const membership = id ? await requireMessageMember(id, userId) : null;
      if (!membership || Number(membership.sender_user_id) !== userId) return ack(callback, { ok: false, error: "forbidden" });
      await pool.execute(`UPDATE ${table("messages")} SET body='',attachment_json=NULL,deleted_at=UTC_TIMESTAMP() WHERE id=?`, [id]);
      io.to(room(membership.conversation_id)).emit("message:deleted", { messageId: String(id), conversationId: String(membership.conversation_id) });
      await notifyConversationUsers(membership.conversation_id, "conversation:updated", { conversationId: String(membership.conversation_id) });
      ack(callback, { ok: true });
    } catch { ack(callback, { ok: false, error: "internal_error" }); }
  });

  socket.on("message:react", async ({ messageId, reaction, active = true } = {}, callback) => {
    try {
      const id = asId(messageId);
      const membership = id ? await requireMessageMember(id, userId) : null;
      const value = String(reaction || "").slice(0, 16);
      if (!membership || !value) return ack(callback, { ok: false, error: "invalid_reaction" });
      if (active) await pool.execute(`INSERT IGNORE INTO ${table("reactions")} (message_id,user_id,reaction,created_at) VALUES (?,?,?,UTC_TIMESTAMP())`, [id, userId, value]);
      else await pool.execute(`DELETE FROM ${table("reactions")} WHERE message_id=? AND user_id=? AND reaction=?`, [id, userId, value]);
      const message = await messageById(id);
      io.to(room(membership.conversation_id)).emit("message:reaction", { messageId: String(id), reactions: message?.reactions || [] });
      ack(callback, { ok: true, message });
    } catch { ack(callback, { ok: false, error: "internal_error" }); }
  });

  const typing = async (event, { conversationId } = {}) => {
    const id = asId(conversationId);
    if (!id || !(await isMember(id, userId))) return;
    socket.to(room(id)).emit("typing:changed", { conversationId: String(id), userId: String(userId), typing: event === "typing:start" });
  };
  socket.on("typing:start", (input) => void typing("typing:start", input));
  socket.on("typing:stop", (input) => void typing("typing:stop", input));

  socket.on("receipt:read", async ({ conversationId, messageId } = {}, callback) => {
    try {
      const cid = asId(conversationId);
      const mid = asId(messageId);
      if (!cid || !mid || !(await isMember(cid, userId))) return ack(callback, { ok: false, error: "forbidden" });
      await pool.execute(`UPDATE ${table("participants")} SET last_read_message_id=GREATEST(COALESCE(last_read_message_id,0),?) WHERE conversation_id=? AND user_id=?`, [mid, cid, userId]);
      socket.to(room(cid)).emit("receipt:read", { conversationId: String(cid), userId: String(userId), messageId: String(mid) });
      await notifyConversationUsers(cid, "conversation:updated", { conversationId: String(cid) });
      ack(callback, { ok: true });
    } catch { ack(callback, { ok: false, error: "internal_error" }); }
  });

  socket.on("disconnect", () => {
    const remaining = Math.max(0, (presence.get(userId) || 1) - 1);
    if (remaining) presence.set(userId, remaining);
    else {
      presence.delete(userId);
      notificationCursors.delete(userId);
      io.emit("presence:changed", { userId: String(userId), online: false });
    }
  });
});

server.listen(port, "0.0.0.0", () => console.log(`meydan realtime chat listening on :${port}`));

async function shutdown() {
  clearInterval(notificationTimer);
  io.close();
  server.close();
  await pool.end();
  process.exit(0);
}
process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);
