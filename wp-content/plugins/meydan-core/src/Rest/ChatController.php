<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Support\ChatRepository;
use Meydan\Core\Support\Response;
use Meydan\Core\Support\SoketiRealtime;
use WP_Error;
use WP_REST_Request;

final class ChatController extends BaseController
{
    private ChatRepository $chat;

    public function __construct()
    {
        $this->chat = new ChatRepository();
    }

    public function conversations(): mixed
    {
        return Response::ok($this->chat->listConversations(get_current_user_id()));
    }

    public function createConversation(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = $this->chat->createDirect(get_current_user_id(), (int) ($input['participant_user_id'] ?? 0));
        if ($result instanceof WP_Error) return $this->error($result);
        SoketiRealtime::publishToConversation((int) $result['id'], 'conversation:updated', ['conversationId' => (string) $result['id']]);
        return Response::ok($result, [], 201);
    }

    public function conversation(WP_REST_Request $request): mixed
    {
        $result = $this->chat->conversation((int) $request['id'], get_current_user_id());
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result);
    }

    public function messages(WP_REST_Request $request): mixed
    {
        $result = $this->chat->messages(
            (int) $request['id'],
            get_current_user_id(),
            max(0, (int) $request->get_param('before_id')),
            min(100, max(1, (int) ($request->get_param('limit') ?: 50)))
        );
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result);
    }

    public function search(WP_REST_Request $request): mixed
    {
        $result = $this->chat->searchMessages(
            (int) $request['id'],
            get_current_user_id(),
            (string) ($request->get_param('q') ?? ''),
            min(100, max(1, (int) ($request->get_param('limit') ?: 100)))
        );
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result);
    }

    public function mute(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = $this->chat->setMuted(
            (int) $request['id'],
            get_current_user_id(),
            filter_var($input['muted'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result);
    }

    public function send(WP_REST_Request $request): mixed
    {
        $conversationId = (int) $request['id'];
        $result = $this->chat->send($conversationId, get_current_user_id(), $this->json($request));
        if ($result instanceof WP_Error) return $this->error($result);
        SoketiRealtime::publishToConversation($conversationId, 'message:created', $result);
        SoketiRealtime::publishToConversation($conversationId, 'conversation:updated', [
            'conversationId' => (string) $conversationId,
            'message' => $result,
        ]);
        return Response::ok($result, [], 201);
    }

    public function edit(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = $this->chat->edit((int) $request['id'], get_current_user_id(), (string) ($input['body'] ?? ''));
        if ($result instanceof WP_Error) return $this->error($result);
        SoketiRealtime::publishToConversation((int) $result['conversation_id'], 'message:updated', $result);
        return Response::ok($result);
    }

    public function delete(WP_REST_Request $request): mixed
    {
        $messageId = (int) $request['id'];
        $userId = get_current_user_id();
        $existing = $this->chat->message($messageId, $userId);
        if ($existing instanceof WP_Error) return $this->error($existing);
        $result = $this->chat->delete($messageId, $userId);
        if ($result instanceof WP_Error) return $this->error($result);
        SoketiRealtime::publishToConversation((int) $existing['conversation_id'], 'message:deleted', [
            'messageId' => (string) $messageId,
            'conversationId' => (string) $existing['conversation_id'],
        ]);
        return Response::ok(['deleted' => true]);
    }

    public function react(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = $this->chat->react((int) $request['id'], get_current_user_id(), (string) ($input['reaction'] ?? ''), true);
        if ($result instanceof WP_Error) return $this->error($result);
        $this->publishReaction($result);
        return Response::ok($result);
    }

    public function unreact(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = $this->chat->react((int) $request['id'], get_current_user_id(), (string) ($input['reaction'] ?? ''), false);
        if ($result instanceof WP_Error) return $this->error($result);
        $this->publishReaction($result);
        return Response::ok($result);
    }

    public function read(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $conversationId = (int) $request['id'];
        $messageId = max(0, (int) ($input['message_id'] ?? 0));
        $userId = get_current_user_id();
        $result = $this->chat->markRead($conversationId, $userId, $messageId);
        if ($result instanceof WP_Error) return $this->error($result);
        SoketiRealtime::publishToConversation($conversationId, 'receipt:read', [
            'conversationId' => (string) $conversationId,
            'messageId' => (string) $messageId,
            'userId' => (string) $userId,
        ]);
        return Response::ok(['read' => true]);
    }

    public function realtimeConfig(): mixed
    {
        return Response::ok([
            ...SoketiRealtime::publicConfig(),
            'user_id' => (string) get_current_user_id(),
        ]);
    }

    public function realtimeAuth(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = SoketiRealtime::authorize(
            sanitize_text_field((string) ($input['socket_id'] ?? '')),
            sanitize_text_field((string) ($input['channel_name'] ?? '')),
            get_current_user_id()
        );
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result);
    }

    public function typing(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $conversationId = (int) $request['id'];
        $userId = get_current_user_id();
        if (!$this->chat->isMember($conversationId, $userId)) {
            return $this->error(new WP_Error('chat_not_found', 'گفتگو پیدا نشد.', ['status' => 404]));
        }
        $typing = filter_var($input['typing'] ?? false, FILTER_VALIDATE_BOOLEAN);
        SoketiRealtime::publishToConversation($conversationId, 'typing:changed', [
            'conversationId' => (string) $conversationId,
            'userId' => (string) $userId,
            'typing' => $typing,
        ]);
        return Response::ok(['typing' => $typing]);
    }

    private function publishReaction(array $message): void
    {
        SoketiRealtime::publishToConversation((int) $message['conversation_id'], 'message:reaction', [
            'messageId' => (string) $message['id'],
            'conversationId' => (string) $message['conversation_id'],
            'reactions' => array_values((array) ($message['reactions'] ?? [])),
        ]);
    }
}
