<?php

declare(strict_types=1);

namespace Meydan\Core\Rest;

use Meydan\Core\Domain\InitiativeMembership;
use Meydan\Core\Domain\WorkActions;
use Meydan\Core\Domain\WorkGroups;
use Meydan\Core\Domain\WorkMessages;
use Meydan\Core\Domain\WorkQueries;
use Meydan\Core\Support\Response;
use WP_Error;
use WP_REST_Request;

/** HTTP layer for «کارها». All rules live in the Domain\Work* classes. */
final class WorkController extends BaseController
{
    private function reply(mixed $result, int $status = 200): mixed
    {
        return $result instanceof WP_Error ? $this->error($result) : Response::ok($result, [], $status);
    }

    private function notFound(): mixed
    {
        return Response::error('work_not_found', 'کار پیدا نشد.', 404);
    }

    /* ------------------------- works & members ----------------------- */

    public function list(WP_REST_Request $r): mixed
    {
        $filter = sanitize_key((string) $r->get_param('filter'));
        $page = WorkQueries::listWorks(
            get_current_user_id(),
            $filter,
            (string) ($r->get_param('q') ?? ''),
            (string) ($r->get_param('cursor') ?? ''),
            (int) ($r->get_param('limit') ?: WorkQueries::PAGE)
        );
        return Response::ok($page['items'], ['next_cursor' => $page['next_cursor']]);
    }

    public function summary(): mixed
    {
        return Response::ok(WorkQueries::summary(get_current_user_id()));
    }

    public function get(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkQueries::detail((int) $r['id'], get_current_user_id()));
    }

    public function update(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkQueries::updateInfo((int) $r['id'], get_current_user_id(), $this->json($r)));
    }

    public function members(WP_REST_Request $r): mixed
    {
        $id = (int) $r['id'];
        if (!WorkQueries::conversation($id)) {
            return $this->notFound();
        }
        $page = WorkQueries::members($id, (string) ($r->get_param('q') ?? ''), (string) ($r->get_param('cursor') ?? ''), (int) ($r->get_param('limit') ?: 50));
        return Response::ok($page['items'], ['next_cursor' => $page['next_cursor']]);
    }

    public function setRole(WP_REST_Request $r): mixed
    {
        $conv = WorkQueries::conversation((int) $r['id']);
        if (!$conv) {
            return $this->notFound();
        }
        $uid = get_current_user_id();
        $input = $this->json($r);
        $result = WorkActions::setRole($conv, $uid, WorkGroups::role((int) $conv['id'], $uid), (int) $r['user_id'], sanitize_key((string) ($input['role'] ?? '')));
        return $this->reply($result === true ? ['role' => sanitize_key((string) ($input['role'] ?? ''))] : $result);
    }

    public function join(WP_REST_Request $r): mixed
    {
        return $this->setMembership((int) $r['id'], true);
    }

    public function leave(WP_REST_Request $r): mixed
    {
        return $this->setMembership((int) $r['id'], false);
    }

    private function setMembership(int $id, bool $on): mixed
    {
        $conv = WorkQueries::conversation($id);
        if (!$conv || $conv['initiative_id'] === null) {
            return $this->notFound();
        }
        $uid = get_current_user_id();
        if (!$on && WorkGroups::role($id, $uid) === WorkGroups::ROLE_OWNER) {
            return Response::error('forbidden', 'مدیر کار نمی‌تواند از کار خود خارج شود.', 403);
        }
        InitiativeMembership::setForUser((int) $conv['initiative_id'], $uid, $on);
        return $this->reply(WorkQueries::detail($id, $uid));
    }

    public function read(WP_REST_Request $r): mixed
    {
        $input = $this->json($r);
        return $this->reply(WorkQueries::markRead((int) $r['id'], get_current_user_id(), (int) ($input['message_id'] ?? 0)) === true ? ['read' => true] : new WP_Error('invalid_message', 'پیام معتبر نیست.', ['status' => 422]));
    }

    /* ----------------------------- messages -------------------------- */

    public function messages(WP_REST_Request $r): mixed
    {
        $id = (int) $r['id'];
        if (!WorkQueries::conversation($id)) {
            return $this->notFound();
        }
        $uid = get_current_user_id();
        $manager = WorkGroups::canManage(WorkGroups::role($id, $uid), $uid);
        $page = WorkMessages::page($id, $uid, $manager, [
            'before_id' => (int) $r->get_param('before_id'),
            'after_id' => (int) $r->get_param('after_id'),
            'limit' => (int) ($r->get_param('limit') ?: 50),
            'kind' => sanitize_key((string) $r->get_param('kind')),
            'mine' => $this->bool($r->get_param('mine')),
        ]);
        return Response::ok($page['items'], ['next_cursor' => $page['next_cursor']]);
    }

    public function send(WP_REST_Request $r): mixed
    {
        $conv = WorkQueries::conversation((int) $r['id']);
        if (!$conv) {
            return $this->notFound();
        }
        $uid = get_current_user_id();
        $role = WorkGroups::role((int) $conv['id'], $uid);
        if ($role === null && !WorkGroups::isSiteAdmin($uid)) {
            return Response::error('work_join_required', 'اول به این کار بپیوندید.', 403);
        }
        return $this->reply(WorkMessages::create($conv, $uid, $role, $this->json($r)), 201);
    }

    public function message(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkActions::message((int) $r['id'], get_current_user_id()));
    }

    public function edit(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkActions::edit((int) $r['id'], get_current_user_id(), $this->json($r)));
    }

    public function delete(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkActions::delete((int) $r['id'], get_current_user_id()) === true ? ['deleted' => true] : new WP_Error('message_not_found', 'پیام پیدا نشد.', ['status' => 404]));
    }

    public function react(WP_REST_Request $r): mixed
    {
        $input = $this->json($r);
        return $this->reply(WorkActions::react((int) $r['id'], get_current_user_id(), (string) ($input['reaction'] ?? ''), true));
    }

    public function unreact(WP_REST_Request $r): mixed
    {
        $input = $this->json($r);
        return $this->reply(WorkActions::react((int) $r['id'], get_current_user_id(), (string) ($input['reaction'] ?? $r->get_param('reaction') ?? ''), false));
    }

    /* ------------------------------ tasks ---------------------------- */

    public function claim(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkActions::claim((int) $r['id'], get_current_user_id(), true));
    }

    public function unclaim(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkActions::claim((int) $r['id'], get_current_user_id(), false));
    }

    public function taskStatus(WP_REST_Request $r): mixed
    {
        $input = $this->json($r);
        return $this->reply(WorkActions::status((int) $r['id'], get_current_user_id(), sanitize_key((string) ($input['action'] ?? ''))));
    }

    public function taskPeople(WP_REST_Request $r): mixed
    {
        $input = $this->json($r);
        return $this->reply(WorkActions::setAssignees((int) $r['id'], get_current_user_id(), (array) ($input['assignee_ids'] ?? [])));
    }

    public function nudge(WP_REST_Request $r): mixed
    {
        $res = WorkActions::nudge((int) $r['id'], get_current_user_id());
        return $this->reply($res === true ? ['sent' => true] : $res);
    }

    public function addItem(WP_REST_Request $r): mixed
    {
        $input = $this->json($r);
        return $this->reply(WorkActions::addItem((int) $r['id'], get_current_user_id(), (string) ($input['title'] ?? '')), 201);
    }

    public function updateItem(WP_REST_Request $r): mixed
    {
        $input = $this->json($r);
        $changes = [];
        if (array_key_exists('done', $input)) {
            $changes['done'] = $this->bool($input['done']);
        }
        if (array_key_exists('title', $input)) {
            $changes['title'] = (string) $input['title'];
        }
        return $this->reply(WorkActions::updateItem((int) $r['id'], get_current_user_id(), $changes));
    }

    public function deleteItem(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkActions::deleteItem((int) $r['id'], get_current_user_id()));
    }

    /* ------------------- meetings / announcements / polls ------------- */

    public function rsvp(WP_REST_Request $r): mixed
    {
        $input = $this->json($r);
        $response = isset($input['response']) ? sanitize_key((string) $input['response']) : null;
        return $this->reply(WorkActions::rsvp((int) $r['id'], get_current_user_id(), $response ?: null));
    }

    public function seen(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkActions::markSeen((int) $r['id'], get_current_user_id()));
    }

    public function seenList(WP_REST_Request $r): mixed
    {
        $res = WorkActions::seenList((int) $r['id'], get_current_user_id(), (string) ($r->get_param('cursor') ?? ''), (int) ($r->get_param('limit') ?: 50));
        return $res instanceof WP_Error ? $this->error($res) : Response::ok($res['items'], ['next_cursor' => $res['next_cursor']]);
    }

    public function remind(WP_REST_Request $r): mixed
    {
        return $this->reply(WorkActions::remind((int) $r['id'], get_current_user_id()));
    }

    public function vote(WP_REST_Request $r): mixed
    {
        $input = $this->json($r);
        return $this->reply(WorkActions::vote((int) $r['id'], get_current_user_id(), (int) ($input['option'] ?? -1)));
    }
}
