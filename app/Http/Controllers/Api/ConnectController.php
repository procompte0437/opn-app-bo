<?php

namespace App\Http\Controllers\Api;

use App\Events\ConnectMessageCreated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectConversationResource;
use App\Http\Resources\ConnectMessageResource;
use App\Models\ConnectConversation;
use App\Models\ConnectConversationMember;
use App\Models\ConnectMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ConnectController extends Controller
{
    private const MAX_BODY_LENGTH = 5000;

    /** Visio native OPN (WebRTC) — plus de meet.jit.si */

    /**
     * GET /connect/conversations — list only conversations where the auth user is a member.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = ConnectConversation::query()
            ->forUser($user->id)
            ->with(['latestMessage.sender', 'creator'])
            ->withCount('members')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ConnectConversation $conversation) use ($user) {
                $membership = ConnectConversationMember::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $user->id)
                    ->first();

                $conversation->my_role = $membership?->role;
                $conversation->unread_count = $this->computeUnreadCount($conversation, $membership);

                return $conversation;
            });

        return response()->json([
            'success' => true,
            'data' => ConnectConversationResource::collection($conversations),
        ]);
    }

    /**
     * POST /connect/conversations — create private conversation; creator = owner.
     * Only listed member_ids + creator become members (no public channel).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'type' => ['required', Rule::in(['direct', 'group', 'channel'])],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $memberIds = collect($validated['member_ids'])
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === (int) $user->id)
            ->unique()
            ->values()
            ->all();

        if ($validated['type'] === 'direct' && count($memberIds) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Une conversation directe doit inclure exactement un autre membre.',
            ], 422);
        }

        if ($validated['type'] === 'direct') {
            $existing = $this->findExistingDirect($user->id, $memberIds[0]);
            if ($existing) {
                $existing->load(['members.user', 'latestMessage.sender', 'creator']);
                $existing->loadCount('members');

                return response()->json([
                    'success' => true,
                    'message' => 'Conversation directe existante.',
                    'data' => new ConnectConversationResource($existing),
                ]);
            }
        }

        $conversation = DB::transaction(function () use ($validated, $user, $memberIds) {
            $title = $validated['title'] ?? null;
            if ($validated['type'] === 'direct' && empty($title)) {
                $peer = User::find($memberIds[0]);
                $title = $peer?->name;
            }

            $conversation = ConnectConversation::create([
                'type' => $validated['type'],
                'title' => $title,
                'description' => $validated['description'] ?? null,
                'created_by' => $user->id,
                'jitsi_room' => 'opn-connect-'.Str::lower(Str::random(24)),
            ]);

            ConnectConversationMember::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'joined_at' => now(),
                'last_read_at' => now(),
            ]);

            foreach ($memberIds as $memberId) {
                ConnectConversationMember::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $memberId,
                    'role' => 'member',
                    'joined_at' => now(),
                ]);
            }

            return $conversation;
        });

        $conversation->load(['members.user', 'latestMessage.sender', 'creator']);
        $conversation->loadCount('members');
        $conversation->my_role = 'owner';
        $conversation->unread_count = 0;

        return response()->json([
            'success' => true,
            'message' => 'Conversation créée.',
            'data' => new ConnectConversationResource($conversation),
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /connect/conversations/{id} — detail + members (403 if not member).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        $conversation->load(['members.user', 'latestMessage.sender', 'creator']);
        $conversation->loadCount('members');

        $membership = ConnectConversationMember::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        $conversation->my_role = $membership?->role;
        $conversation->unread_count = $this->computeUnreadCount($conversation, $membership);

        return response()->json([
            'success' => true,
            'data' => new ConnectConversationResource($conversation),
        ]);
    }

    /**
     * POST /connect/conversations/{id}/members — owner/admin only.
     */
    public function addMembers(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        if (! $conversation->isOwnerOrAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Seuls le propriétaire ou un administrateur peuvent ajouter des membres.',
            ], 403);
        }

        $validated = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $existingIds = $conversation->members()->pluck('user_id')->all();
        $added = [];

        foreach ($validated['member_ids'] as $memberId) {
            $memberId = (int) $memberId;
            if (in_array($memberId, $existingIds, true)) {
                continue;
            }

            ConnectConversationMember::create([
                'conversation_id' => $conversation->id,
                'user_id' => $memberId,
                'role' => 'member',
                'joined_at' => now(),
            ]);
            $added[] = $memberId;
        }

        $conversation->load(['members.user']);
        $conversation->loadCount('members');

        return response()->json([
            'success' => true,
            'message' => count($added) > 0 ? 'Membres ajoutés.' : 'Aucun nouveau membre à ajouter.',
            'added_user_ids' => $added,
            'data' => new ConnectConversationResource($conversation),
        ]);
    }

    /**
     * DELETE /connect/conversations/{id}/members/{userId}
     * Owner/admin can remove others; any member can leave (self).
     */
    public function removeMember(Request $request, int $id, int $userId): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        $isSelf = (int) $user->id === (int) $userId;
        if (! $isSelf && ! $conversation->isOwnerOrAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez retirer que vous-même, ou être owner/admin.',
            ], 403);
        }

        $target = ConnectConversationMember::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->first();

        if (! $target) {
            return response()->json([
                'success' => false,
                'message' => 'Cet utilisateur n\'est pas membre de la conversation.',
            ], 404);
        }

        if ($target->role === 'owner' && ! $isSelf) {
            return response()->json([
                'success' => false,
                'message' => 'Le propriétaire ne peut pas être retiré par un autre membre.',
            ], 403);
        }

        $target->delete();

        return response()->json([
            'success' => true,
            'message' => $isSelf ? 'Vous avez quitté la conversation.' : 'Membre retiré.',
        ]);
    }

    /**
     * GET /connect/conversations/{id}/messages?after_id=&limit=50
     * Polling-friendly cursor via after_id for near-realtime without Reverb.
     */
    public function messages(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);

        $query = ConnectMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with(['sender']);

        if (! empty($validated['after_id'])) {
            $query->where('id', '>', (int) $validated['after_id'])->orderBy('id');
        } elseif (! empty($validated['before_id'])) {
            $query->where('id', '<', (int) $validated['before_id'])->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        $messages = $query->limit($limit)->get();

        if (empty($validated['after_id'])) {
            $messages = $messages->sortBy('id')->values();
        }

        return response()->json([
            'success' => true,
            'data' => ConnectMessageResource::collection($messages),
            'meta' => [
                'limit' => $limit,
                'after_id' => $validated['after_id'] ?? null,
                'count' => $messages->count(),
            ],
        ]);
    }

    /**
     * POST /connect/conversations/{id}/messages — text or emoji.
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['text', 'emoji', 'system'])],
            'body' => ['nullable', 'string', 'max:'.self::MAX_BODY_LENGTH],
            'emoji_code' => ['nullable', 'string', 'max:100'],
            'meta' => ['nullable', 'array'],
        ]);

        if ($validated['type'] === 'text' && empty($validated['body'])) {
            return response()->json([
                'success' => false,
                'message' => 'Le corps du message texte est requis.',
            ], 422);
        }

        if ($validated['type'] === 'emoji' && empty($validated['emoji_code']) && empty($validated['body'])) {
            return response()->json([
                'success' => false,
                'message' => 'emoji_code ou body est requis pour un message emoji.',
            ], 422);
        }

        if ($validated['type'] === 'system') {
            $kind = is_array($validated['meta'] ?? null)
                ? ($validated['meta']['kind'] ?? null)
                : null;
            // Invitation visio : tout membre peut notifier les autres (pas seulement owner/admin)
            if ($kind !== 'visio_invite' && ! $conversation->isOwnerOrAdmin($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seuls owner/admin peuvent envoyer des messages système.',
                ], 403);
            }
            if (empty($validated['body'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le corps du message système est requis.',
                ], 422);
            }
        }

        $message = $this->persistMessage($conversation, $user->id, [
            'type' => $validated['type'],
            'body' => $validated['body'] ?? null,
            'emoji_code' => $validated['emoji_code'] ?? null,
            'meta' => $validated['meta'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message envoyé.',
            'data' => new ConnectMessageResource($message),
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /connect/conversations/{id}/messages/audio
     * Multipart: audio file + duration_sec.
     * Stored at storage/app/public/connect/audio — run: php artisan storage:link
     */
    public function sendAudio(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        $validated = $request->validate([
            'audio' => ['required', 'file', 'mimetypes:audio/mpeg,audio/mp4,audio/wav,audio/x-wav,audio/webm,audio/ogg,audio/aac,audio/x-m4a', 'max:10240'],
            'duration_sec' => ['required', 'integer', 'min:1', 'max:600'],
            'body' => ['nullable', 'string', 'max:'.self::MAX_BODY_LENGTH],
        ]);

        $path = $request->file('audio')->store('connect/audio', 'public');

        $message = $this->persistMessage($conversation, $user->id, [
            'type' => 'audio',
            'body' => $validated['body'] ?? null,
            'audio_path' => $path,
            'audio_duration_sec' => (int) $validated['duration_sec'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message audio envoyé.',
            'data' => new ConnectMessageResource($message),
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /connect/conversations/{id}/read — mark last_read_at = now.
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        ConnectConversationMember::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Conversation marquée comme lue.',
            'last_read_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /connect/directory — users available to invite (private directory).
     */
    public function directory(Request $request): JsonResponse
    {
        $user = $request->user();
        $since = now()->subMinutes(PresenceController::ONLINE_WINDOW_MINUTES);

        $users = User::query()
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'code_role', 'role', 'entite', 'last_seen_at'])
            ->map(function (User $u) use ($since) {
                $online = $u->last_seen_at !== null && $u->last_seen_at->gte($since);

                return [
                    'id' => (int) $u->id,
                    'name' => $u->name,
                    'nom' => $u->name,
                    'email' => $u->email,
                    'code_role' => $u->code_role,
                    'role' => $u->role,
                    'entite' => $u->entite,
                    'online' => $online,
                    'last_seen_at' => $u->last_seen_at?->toIso8601String(),
                ];
            })
            ->sortBy([
                ['online', 'desc'],
                ['name', 'asc'],
            ])
            ->values();

        return response()->json([
            'success' => true,
            'online_window_minutes' => PresenceController::ONLINE_WINDOW_MINUTES,
            'online_count' => $users->where('online', true)->count(),
            'data' => $users,
        ]);
    }

    /**
     * POST /connect/messages/{messageId}/reactions
     * Réaction WhatsApp : 1 emoji par utilisateur et par message (toggle).
     * Stocké dans meta.reactions (pas de table dédiée requise).
     */
    public function react(Request $request, int $messageId): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:16'],
        ]);

        $emoji = trim($validated['emoji']);
        if ($emoji === '') {
            return response()->json([
                'success' => false,
                'message' => 'Emoji requis.',
            ], 422);
        }

        $message = ConnectMessage::query()->with('conversation')->findOrFail($messageId);
        $message->conversation->ensureMemberOrFail($user);

        $meta = is_array($message->meta) ? $message->meta : [];
        $reactions = [];
        if (isset($meta['reactions']) && is_array($meta['reactions'])) {
            foreach ($meta['reactions'] as $key => $value) {
                // Formats supportés : { "👍": [1,2] } ou [{emoji,user_ids}]
                if (is_string($key) && ! is_int($key)) {
                    $reactions[$key] = array_values(array_map('intval', (array) $value));
                } elseif (is_array($value) && ! empty($value['emoji'])) {
                    $reactions[$value['emoji']] = array_values(array_map(
                        'intval',
                        (array) ($value['user_ids'] ?? [])
                    ));
                }
            }
        }

        $uid = (int) $user->id;
        // Retirer l'utilisateur de toutes les réactions
        foreach ($reactions as $em => $ids) {
            $reactions[$em] = array_values(array_filter(
                $ids,
                fn ($id) => (int) $id !== $uid
            ));
            if ($reactions[$em] === []) {
                unset($reactions[$em]);
            }
        }

        $already = false;
        if (isset($meta['reactions']) && is_array($meta['reactions'])) {
            // Déterminer si c'était déjà cet emoji (toggle off)
            $prev = $meta['reactions'][$emoji] ?? null;
            if (is_array($prev) && in_array($uid, array_map('intval', $prev), true)) {
                $already = true;
            }
        }

        if (! $already) {
            $reactions[$emoji] = array_values(array_unique(array_merge(
                $reactions[$emoji] ?? [],
                [$uid]
            )));
        }

        $meta['reactions'] = $reactions;
        $message->forceFill(['meta' => $meta])->save();
        $message->load(['sender']);
        event(new ConnectMessageCreated($message));

        return response()->json([
            'success' => true,
            'message' => 'Réaction enregistrée.',
            'data' => new ConnectMessageResource($message),
        ]);
    }

    /**
     * GET /connect/conversations/{id}/jitsi
     * (conservé pour compat) — renvoie la salle WebRTC native OPN.
     */
    public function jitsi(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        return response()->json([
            'success' => true,
            'data' => [
                'room' => $conversation->jitsi_room,
                'displayName' => $user->name,
                'provider' => 'opn_webrtc',
                'user_id' => (int) $user->id,
            ],
        ]);
    }

    /**
     * POST /connect/conversations/{id}/visio/signal
     * Signalisation WebRTC (offer / answer / ice / hangup / join).
     */
    public function visioSignal(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['join', 'offer', 'answer', 'ice', 'hangup', 'renegotiate'])],
            'payload' => ['nullable', 'array'],
            'target_user_id' => ['nullable', 'integer'],
        ]);

        $signal = [
            'id' => (string) Str::uuid(),
            'conversation_id' => (int) $conversation->id,
            'from_user_id' => (int) $user->id,
            'from_name' => $user->name,
            'target_user_id' => isset($validated['target_user_id'])
                ? (int) $validated['target_user_id']
                : null,
            'type' => $validated['type'],
            'payload' => $validated['payload'] ?? null,
            'created_at' => now()->toIso8601String(),
        ];

        $key = 'opn_visio_signals:'.$conversation->id;
        $list = Cache::get($key, []);
        if (! is_array($list)) {
            $list = [];
        }

        // Un join démarre une nouvelle négociation : purger offer/answer/ice obsolètes
        // (évite de rejouer un SDP Chrome « repair-window » invalide).
        if ($validated['type'] === 'join') {
            $uid = (int) $user->id;
            $list = array_values(array_filter($list, static function ($item) use ($uid) {
                if (! is_array($item)) {
                    return false;
                }
                $type = (string) ($item['type'] ?? '');
                if (in_array($type, ['offer', 'answer', 'ice', 'hangup', 'renegotiate'], true)) {
                    return false;
                }
                // Remplacer l'ancien join du même utilisateur
                if ($type === 'join' && (int) ($item['from_user_id'] ?? 0) === $uid) {
                    return false;
                }

                return true;
            }));
        }

        $list[] = $signal;
        // garder les 80 derniers signaux (fenêtre courte d'appel)
        $list = array_slice($list, -80);
        Cache::put($key, $list, now()->addMinutes(30));

        return response()->json([
            'success' => true,
            'data' => $signal,
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /connect/conversations/{id}/visio/signals?after=
     * Polling des signaux WebRTC (exclut les signaux de l'appelant).
     */
    public function visioSignals(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        $key = 'opn_visio_signals:'.$conversation->id;
        $list = Cache::get($key, []);
        if (! is_array($list)) {
            $list = [];
        }

        $afterTs = $request->query('after_ts');
        $uid = (int) $user->id;
        $out = [];
        foreach ($list as $signal) {
            if (! is_array($signal)) {
                continue;
            }
            if ((int) ($signal['from_user_id'] ?? 0) === $uid) {
                continue;
            }
            $target = $signal['target_user_id'] ?? null;
            if ($target !== null && (int) $target !== $uid) {
                continue;
            }
            if ($afterTs && strcmp((string) ($signal['created_at'] ?? ''), (string) $afterTs) <= 0) {
                continue;
            }
            $out[] = $signal;
        }

        return response()->json([
            'success' => true,
            'data' => $out,
            'room' => $conversation->jitsi_room,
            'user_id' => $uid,
            'provider' => 'opn_webrtc',
        ]);
    }

    /**
     * POST /connect/conversations/{id}/visio/start
     * Démarre/annonce une visioconférence : salle + message système d'invitation.
     */
    public function startVisio(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = ConnectConversation::query()->findOrFail($id);
        $conversation->ensureMemberOrFail($user);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        if (empty($conversation->jitsi_room)) {
            $conversation->forceFill([
                'jitsi_room' => 'opn-connect-'.Str::lower(Str::random(24)),
            ])->save();
        }

        // Nouvelle session : purger les signaux WebRTC obsolètes (évite offres/ICE orphelines)
        Cache::forget('opn_visio_signals:'.$conversation->id);

        $title = $validated['title'] ?? $conversation->title ?? 'Visioconférence OPN';
        $body = $user->name.' vous invite à rejoindre la visioconférence « '.$title.' ».';

        $message = $this->persistMessage($conversation, (int) $user->id, [
            'type' => 'system',
            'body' => $body,
            'meta' => [
                'kind' => 'visio_invite',
                'room' => $conversation->jitsi_room,
                'conversation_id' => (int) $conversation->id,
                'caller_name' => $user->name,
                'caller_email' => $user->email,
                'title' => $title,
                'started_at' => now()->toIso8601String(),
            ],
        ]);

        $conversation->load(['members.user']);
        $targets = $conversation->members
            ->filter(fn ($m) => (int) $m->user_id !== (int) $user->id && $m->user)
            ->map(fn ($m) => [
                'id' => (int) $m->user->id,
                'email' => $m->user->email,
                'name' => $m->user->name,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Invitation visioconférence envoyée.',
            'data' => [
                'room' => $conversation->jitsi_room,
                'displayName' => $user->name,
                'provider' => 'opn_webrtc',
                'user_id' => (int) $user->id,
                'conversation_id' => (int) $conversation->id,
                'title' => $title,
                'targets' => $targets,
                'invite' => new ConnectMessageResource($message),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /connect/visio/incoming
     * Invitations visio actives destinées à l'utilisateur connecté.
     */
    public function incomingVisio(Request $request): JsonResponse
    {
        $user = $request->user();
        $since = now()->subMinutes(20);

        $messages = ConnectMessage::query()
            ->where('type', 'system')
            ->where('user_id', '!=', $user->id)
            ->where('created_at', '>=', $since)
            ->whereHas('conversation', function ($q) use ($user) {
                $q->forUser((int) $user->id);
            })
            ->with(['sender', 'conversation'])
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->filter(function (ConnectMessage $message) {
                return ($message->meta['kind'] ?? null) === 'visio_invite';
            })
            ->values();

        $invites = $messages->map(function (ConnectMessage $message) {
            $meta = $message->meta ?? [];

            return [
                'message_id' => $message->id,
                'conversation_id' => (int) $message->conversation_id,
                'room' => $meta['room'] ?? $message->conversation?->jitsi_room,
                'title' => $meta['title'] ?? $message->conversation?->title ?? 'Visioconférence OPN',
                'caller_name' => $meta['caller_name'] ?? $message->sender?->name ?? 'Collègue OPN',
                'caller_email' => $meta['caller_email'] ?? $message->sender?->email,
                'body' => $message->body,
                'started_at' => $meta['started_at'] ?? optional($message->created_at)->toIso8601String(),
                'created_at' => optional($message->created_at)->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $invites,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistMessage(ConnectConversation $conversation, int $userId, array $payload): ConnectMessage
    {
        $message = DB::transaction(function () use ($conversation, $userId, $payload) {
            $message = ConnectMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'type' => $payload['type'],
                'body' => $payload['body'] ?? null,
                'emoji_code' => $payload['emoji_code'] ?? null,
                'audio_path' => $payload['audio_path'] ?? null,
                'audio_duration_sec' => $payload['audio_duration_sec'] ?? null,
                'meta' => $payload['meta'] ?? null,
            ]);

            $conversation->update(['last_message_at' => $message->created_at]);

            return $message;
        });

        $message->load(['sender']);

        // Le temps réel (Reverb) est optionnel : ne jamais faire échouer
        // l'invitation visio / le message si le broker WebSocket est down.
        try {
            event(new ConnectMessageCreated($message));
        } catch (\Throwable $broadcastError) {
            report($broadcastError);
        }

        return $message;
    }

    private function computeUnreadCount(
        ConnectConversation $conversation,
        ?ConnectConversationMember $membership
    ): int {
        if (! $membership) {
            return 0;
        }

        $query = ConnectMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $membership->user_id);

        if ($membership->last_read_at) {
            $query->where('created_at', '>', $membership->last_read_at);
        }

        return $query->count();
    }

    private function findExistingDirect(int $userId, int $peerId): ?ConnectConversation
    {
        return ConnectConversation::query()
            ->where('type', 'direct')
            ->whereHas('members', fn ($q) => $q->where('user_id', $userId))
            ->whereHas('members', fn ($q) => $q->where('user_id', $peerId))
            ->whereDoesntHave('members', function ($q) use ($userId, $peerId) {
                $q->whereNotIn('user_id', [$userId, $peerId]);
            })
            ->first();
    }
}
