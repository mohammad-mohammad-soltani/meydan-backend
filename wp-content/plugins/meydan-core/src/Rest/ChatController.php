<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Support\ChatRepository;
use Meydan\Core\Support\ChatSocketTicket;
use Meydan\Core\Support\Response;
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
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result, [], 201);
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
        $result = $this->chat->send((int) $request['id'], get_current_user_id(), $this->json($request));
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result, [], 201);
    }

    public function edit(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = $this->chat->edit((int) $request['id'], get_current_user_id(), (string) ($input['body'] ?? ''));
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result);
    }

    public function delete(WP_REST_Request $request): mixed
    {
        $result = $this->chat->delete((int) $request['id'], get_current_user_id());
        return $result instanceof WP_Error ? $this->error($result) : Response::ok(['deleted' => true]);
    }

    public function react(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = $this->chat->react((int) $request['id'], get_current_user_id(), (string) ($input['reaction'] ?? ''), true);
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result);
    }

    public function unreact(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = $this->chat->react((int) $request['id'], get_current_user_id(), (string) ($input['reaction'] ?? ''), false);
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result);
    }

    public function read(WP_REST_Request $request): mixed
    {
        $input = $this->json($request);
        $result = $this->chat->markRead((int) $request['id'], get_current_user_id(), max(0, (int) ($input['message_id'] ?? 0)));
        return $result instanceof WP_Error ? $this->error($result) : Response::ok(['read' => true]);
    }

    public function socketTicket(): mixed
    {
        try {
            return Response::ok(ChatSocketTicket::issue(get_current_user_id()));
        } catch (\RuntimeException $e) {
            return Response::error('chat_realtime_unavailable', $e->getMessage(), 503);
        }
    }
}
