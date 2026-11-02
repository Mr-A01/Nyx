<?php
declare(strict_types=1);
use Nyx\Modules\Identity\Presentation\Http\Controllers\SecurityController;
use Nyx\Modules\Messaging\Presentation\Http\Controllers\MessageExtrasController;
/** @var \Nyx\Core\Routing\Router $router */
use Nyx\Modules\Administration\Presentation\Http\Controllers\OpsController;
use Nyx\Modules\Messaging\Presentation\Http\Controllers\MessageController;
use Nyx\Modules\Identity\Presentation\Http\Controllers\IdentityController;
use Nyx\Modules\Devices\Presentation\Http\Controllers\DeviceController;
use Nyx\Modules\Presence\Presentation\Http\Controllers\PresenceController;
use Nyx\Modules\Federation\Presentation\Http\Controllers\FederationController;
use Nyx\Modules\Conversations\Presentation\Http\Controllers\SpaceController;
use Nyx\Modules\Crypto\Presentation\Http\Controllers\CryptoController;
use Nyx\Modules\Attachments\Presentation\Http\Controllers\AttachmentController;
use Nyx\Modules\Notifications\Presentation\Http\Controllers\PushController;
use Nyx\Modules\Social\Presentation\Http\Controllers\ProfileController;
use Nyx\Modules\Social\Presentation\Http\Controllers\PrefsController;
use Nyx\Modules\Social\Presentation\Http\Controllers\ContactsController;
use Nyx\Modules\Moderation\Presentation\Http\Controllers\ModerationController;
use Nyx\Modules\Search\Presentation\Http\Controllers\SearchController;
$ops=OpsController::class; $msg=MessageController::class; $id=IdentityController::class;
$dev=DeviceController::class; $sec=SecurityController::class; $msgx=MessageExtrasController::class; $pres=PresenceController::class; $fed=FederationController::class;
$space=SpaceController::class; $crypto=CryptoController::class; $att=AttachmentController::class;
$push=PushController::class;
$profile=ProfileController::class;
$prefs=PrefsController::class;
$contacts=ContactsController::class; $mod=ModerationController::class; $search=SearchController::class;
$router->get('/', [$ops,'root']);
$router->get('/api/v3/ops/info', [$ops,'info']);
$router->get('/api/v3/ops/health', [$ops,'health']);
$router->get('/api/v3/health', [$ops,'health']);
$router->get('/api/v3/ops/selftest', [$ops,'selftest']);
$router->post('/api/v3/ops/gc', [$ops,'gc']);
$router->get('/api/v3/ops/metrics', [$ops,'metrics']);
$router->get('/api/v3/ops/network', [$ops,'network']);
$router->get('/api/v3/health', [$ops,'health']);
$router->post('/api/v3/messages/send', [$msg,'send']);
$router->get('/api/v3/messages/sync', [$msg,'sync']);
$router->post('/api/v3/messages/sync', [$msg,'sync']);
$router->get('/api/v3/messages/wait', [$msg,'wait']);
$router->post('/api/v3/messages/wait', [$msg,'wait']);
$router->post('/api/v3/messages/edit', [$msg,'edit']);
$router->post('/api/v3/messages/delete', [$msg,'delete']);
$router->post('/api/v3/messages/clear', [$msg,'clear']);
$router->post('/api/v3/messages/react', [$msg,'react']);
$router->post('/api/v3/messages/pin', [$msg,'pin']);
$router->post('/api/v3/messages/unpin', [$msg,'unpin']);
$router->post('/api/v3/messages/receipt', [$msg,'receipt']);
$router->get('/api/v3/messages/events', [$msg,'events']);
$router->get('/api/v3/messages/read-state', [$msg,'readState']);
$router->post('/api/v3/messages/mark-read', [$msg,'markRead']);
$router->get('/api/v3/search/messages', [$search,'messages']);
$router->get('/api/v3/auth/challenge', [$id,'challenge']);
$router->post('/api/v3/auth/challenge', [$id,'challenge']);
$router->post('/api/v3/auth/register', [$id,'register']);
$router->post('/api/v1/auth/register', [$id,'register']);
$router->post('/api/v3/identity/register', [$id,'register']);
$router->post('/api/v1/identity/register', [$id,'register']);
$router->post('/api/v3/auth/session', [$id,'session']);
$router->post('/api/v1/auth/session', [$id,'session']);
$router->post('/api/v3/auth/login', [$id,'login']);
$router->post('/api/v1/auth/login', [$id,'login']);
$router->get('/api/v3/auth/me', [$id,'me']);
$router->get('/api/v1/auth/me', [$id,'me']);
$router->post('/api/v3/auth/logout', [$id,'logout']);
$router->post('/api/v1/auth/logout', [$id,'logout']);
$router->get('/api/v1/identity/username', [$id,'usernameLookup']);
$router->get('/api/v3/identity/username', [$id,'usernameLookup']);
$router->post('/api/v3/devices/register', [$dev,'register']);
$router->post('/api/v3/messages/reaction', [$msg,'react']);
// --- Security ---
$router->get('/api/v3/security/sessions', [$sec,'sessions']);
$router->post('/api/v3/security/sessions/revoke', [$sec,'revokeSession']);
$router->get('/api/v3/security/recovery-codes', [$sec,'recoveryCodes']);
$router->post('/api/v3/security/recovery-codes', [$sec,'recoveryCodes']);
$router->post('/api/v3/security/recovery-codes/use', [$sec,'useRecoveryCode']);
$router->post('/api/v3/security/freeze', [$sec,'freeze']);
$router->get('/api/v3/security/freeze', [$sec,'freezeStatus']);
$router->get('/api/v3/security/login-alerts', [$sec,'loginAlerts']);
$router->post('/api/v3/security/login-alerts', [$sec,'recordLoginAlert']);
$router->post('/api/v3/security/step-up', [$sec,'stepUp']);
// --- Crypto E2EE ---
$router->get('/api/v3/crypto/status', [$crypto,'status']);
$router->post('/api/v3/crypto/keys', [$crypto,'registerKey']);
$router->get('/api/v3/crypto/keys', [$crypto,'getKey']);
$router->get('/api/v3/crypto/safety-number', [$crypto,'safetyNumber']);
$router->post('/api/v3/crypto/safety-number', [$crypto,'safetyNumber']);
$router->post('/api/v3/crypto/verify', [$crypto,'verifySafety']);
$router->get('/api/v3/crypto/alerts', [$crypto,'keyChangeAlerts']);
$router->get('/api/v3/crypto/conversation', [$crypto,'conversationStatus']);
$router->post('/api/v3/crypto/sealed', [$crypto,'sealedSender']);
$router->get('/api/v3/crypto/fs-audit', [$crypto,'fsAudit']);
// --- Message extras ---
$router->post('/api/v3/messages/bookmark', [$msgx,'bookmark']);
$router->get('/api/v3/messages/bookmarks', [$msgx,'bookmarks']);
$router->post('/api/v3/messages/schedule', [$msgx,'schedule']);
$router->get('/api/v3/messages/scheduled', [$msgx,'scheduledList']);
$router->post('/api/v3/messages/schedule/cancel', [$msgx,'scheduleCancel']);
$router->get('/api/v3/messages/schedule/due', [$msgx,'scheduleDue']);
$router->get('/api/v3/messages/thread', [$msgx,'thread']);
// --- Global search ---
$router->get('/api/v3/search/global', [$search,'globalSearch']);

$router->get('/api/v3/devices/list', [$dev,'list']);
$router->get('/api/v3/devices', [$dev,'list']);
$router->get('/api/v3/notifications/list', [$push,'list']);
$router->get('/api/v3/notifications', [$push,'list']);
$router->post('/api/v3/notifications/read', [$push,'markRead']);
$router->post('/api/v3/notifications/read-all', [$push,'markRead']);
$router->post('/api/v3/notifications/mark-read', [$push,'markRead']);
$router->post('/api/v3/attachments/init', [$att,'init']);
$router->post('/api/v3/devices/touch', [$dev,'touch']);
$router->post('/api/v3/devices/revoke', [$dev,'revoke']);
$router->post('/api/v3/presence/heartbeat', [$pres,'heartbeat']);
$router->post('/api/v3/presence/batch', [$pres,'batch']);
$router->get('/api/v3/presence/batch', [$pres,'batch']);
$router->post('/api/v3/presence/typing', [$pres,'typing']);
$router->get('/api/v3/presence/typing', [$pres,'typingGet']);
$router->get('/api/v3/federation/peers', [$fed,'peers']);
$router->get('/api/v3/federation/topology', [$fed,'topology']);
$router->post('/api/v3/federation/peers/register', [$fed,'registerPeer']);
$router->post('/api/v3/federation/gossip', [$fed,'gossip']);
$router->post('/api/v3/federation/heal', [$fed,'heal']);
$router->post('/api/v3/spaces', [$space,'create']);
$router->post('/api/v3/spaces/create', [$space,'create']);
$router->post('/api/v3/spaces/join', [$space,'join']);
$router->post('/api/v3/spaces/leave', [$space,'leave']);
$router->post('/api/v3/spaces/mute', [$space,'mute']);
$router->post('/api/v3/spaces/pin', [$space,'pinConv']);
$router->post('/api/v3/spaces/archive', [$space,'archive']);
$router->post('/api/v3/spaces/join/invite', [$space,'joinInvite']);

$router->post('/api/v3/spaces/kick', [$space,'kick']);
$router->post('/api/v3/spaces/set-role', [$space,'setRole']);
$router->post('/api/v3/spaces/transfer', [$space,'transfer']);
$router->post('/api/v3/spaces/ban', [$space,'ban']);
$router->post('/api/v3/spaces/unban', [$space,'unban']);
$router->get('/api/v3/spaces/bans', [$space,'bans']);
$router->post('/api/v3/spaces/bans', [$space,'bans']);
$router->post('/api/v3/spaces/settings', [$space,'settings']);
$router->post('/api/v3/spaces/update', [$space,'update']);
$router->get('/api/v3/spaces/join-requests', [$space,'joinRequests']);
$router->post('/api/v3/spaces/join-request/decide', [$space,'decideJoinRequest']);
$router->post('/api/v3/spaces/join-requests/decide', [$space,'decideJoinRequest']);
$router->get('/api/v3/spaces/audit', [$space,'auditLog']);
$router->post('/api/v3/spaces/audit', [$space,'auditLog']);

$router->post('/api/v3/spaces/invite', [$space,'invite']);

$router->get('/api/v3/spaces/invites', [$space,'listInvites']);
$router->post('/api/v3/spaces/invites', [$space,'listInvites']);
$router->post('/api/v3/spaces/invite/revoke', [$space,'revokeInvite']);
$router->post('/api/v3/spaces/invite/list', [$space,'listInvites']);
$router->post('/api/v3/spaces/mute-member', [$space,'muteMember']);
$router->post('/api/v3/spaces/mute_member', [$space,'muteMember']);
$router->post('/api/v3/groups/invite', [$space,'invite']);
$router->post('/api/v3/groups/invite/revoke', [$space,'revokeInvite']);
$router->get('/api/v3/groups/invites', [$space,'listInvites']);
$router->post('/api/v3/groups/mute-member', [$space,'muteMember']);
$router->post('/api/v3/groups/ban', [$space,'ban']);
$router->post('/api/v3/groups/unban', [$space,'unban']);
$router->post('/api/v3/groups/kick', [$space,'kick']);
$router->post('/api/v3/groups/set-role', [$space,'setRole']);
$router->get('/api/v3/groups/audit', [$space,'auditLog']);
$router->post('/api/v3/groups/settings', [$space,'settings']);

$router->post('/api/v3/spaces/join-invite', [$space,'joinInvite']);
$router->post('/api/v3/spaces/join_invite', [$space,'joinInvite']);
$router->post('/api/v3/spaces/add-member', [$space,'addMember']);
$router->post('/api/v3/spaces/add_member', [$space,'addMember']);
$router->get('/api/v3/spaces/mine', [$space,'mine']);
$router->post('/api/v3/conversations/mute', [$space,'mute']);
$router->post('/api/v3/conversations/pin', [$space,'pinConv']);
$router->post('/api/v3/conversations/archive', [$space,'archive']);
$router->post('/api/v3/conversations/clear', [$space,'clearHistory']);
$router->post('/api/v3/conversations/delete', [$space,'deleteConversation']);
$router->post('/api/v3/conversations/hide', [$space,'deleteConversation']);
$router->post('/api/v3/crypto/prekeys', [$crypto,'publishPrekeys']);
$router->get('/api/v3/crypto/prekeys', [$crypto,'fetchPrekeys']);
$router->get('/api/v3/crypto/status', [$crypto,'status']);
$router->put('/api/v3/keys/ecdh', [$crypto,'putEcdh']);
$router->post('/api/v3/keys/ecdh', [$crypto,'putEcdh']);
$router->get('/api/v3/keys/ecdh', [$crypto,'getEcdh']);
$router->post('/api/v3/attachments/meta', [$att,'meta']);
$router->post('/api/v3/attachments/upload', [$att,'upload']);
$router->get('/api/v3/attachments/get', [$att,'get']);
$router->post('/api/v3/push/subscribe', [$push,'subscribe']);
$router->post('/api/v3/push/unsubscribe', [$push,'unsubscribe']);
$router->get('/api/v3/push/prefs', [$push,'prefs']);
$router->post('/api/v3/push/prefs', [$push,'prefs']);
$router->post('/api/v3/moderation/report', [$mod,'report']);
$router->post('/api/v3/moderation/block', [$mod,'block']);
$router->post('/api/v3/moderation/unblock', [$mod,'unblock']);
$router->get('/api/v3/moderation/blocks', [$mod,'listBlocks']);
$router->get('/api/v3/events/poll', [$msg,'events']);

$router->post('/api/v3/messages/forward',[$msg,'forward']);
$router->post('/api/v3/dm', [$space,'openDm']);
$router->post('/api/v3/dm/open', [$space,'openDm']);
$router->get('/api/v3/conversations', [$space,'conversations']);
$router->get('/api/v3/spaces/members', [$space,'members']);
$router->post('/api/v3/spaces/members', [$space,'members']);
$router->get('/api/v3/spaces/get', [$space,'get']);
$router->post('/api/v3/spaces/update', [$space,'update']);
$router->post('/api/v3/typing', [$pres,'typing']);
$router->get('/api/v3/typing', [$pres,'typingGet']);
$router->get('/api/v3/events/stream', [$msg,'stream']);
$router->get('/api/v3/updates/wait', [$msg,'events']);
$router->post('/api/v3/messages/read', [$msg,'markRead']);
$router->post('/api/v3/receipts', [$msg,'receipt']);
$router->get('/api/v3/search/users', [$search,'users']);
$router->get('/api/v3/users/search', [$search,'users']);
$router->post('/api/v3/uploads/raw', [$att,'uploadRaw']);
$router->post('/api/v3/uploads', [$att,'upload']);
$router->get('/api/v3/attachments/download', [$att,'download']);
$router->get('/api/v3/uploads/{id}', [$att,'download']);

// Aliases & soft endpoints (audit fixes)
$router->get('/api/v3/typing', [$pres,'typingGet']);
$router->post('/api/v3/typing', [$pres,'typing']);
$router->get('/api/v3/search', [$search,'users']);
$router->get('/api/v3/messages/search', [$search,'messages']);
$router->get('/api/v3/search/global', [$search,'users']);
$router->get('/api/v3/spaces/info', [$space,'get']);
$router->get('/api/v3/spaces/get', [$space,'get']);
$router->post('/api/v3/spaces/admin', function($r) {
  return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['ok' => true, 'actions' => ['list','kick','ban','promote','demote','update'], 'note' => 'use spaces/update and members endpoints']);
});
$router->get('/api/v3/spaces/admin', function($r) {
  return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['ok' => true, 'actions' => ['list','kick','ban','promote','demote','update']]);
});
$router->get('/api/v3/crypto/keys', [$crypto,'fetchPrekeys']);
$router->post('/api/v3/crypto/keys', [$crypto,'publishPrekeys']);

// Soft stubs & aliases (required by client bootstrap)
$router->post('/api/v1/account/ensure-messaging', function() {
  return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['ok'=>true,'messaging'=>true]);
});
$router->get('/api/v1/account/ensure-messaging', function() {
  return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['ok'=>true,'messaging'=>true]);
});
$router->post('/api/v3/account/ensure-messaging', function() {
  return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['ok'=>true,'messaging'=>true]);
});
$router->get('/api/v3/spaces/discover', function() {
  $store = new \Nyx\Shared\Infrastructure\Storage\FileStore(defined('DATA_DIR') ? DATA_DIR : '');
  $root = $store->root() . '/spaces';
  $items = [];
  if (is_dir($root)) {
    foreach (glob($root . '/*.json') ?: [] as $f) {
      $sp = json_decode((string)@file_get_contents($f), true);
      if (!is_array($sp)) continue;
      $pub = !empty($sp['is_public']) || !empty($sp['public']) || (($sp['type'] ?? '') === 'channel' && empty($sp['private']));
      if (!$pub) continue;
      $sid = (string)($sp['space_id'] ?? $sp['id'] ?? basename($f, '.json'));
      $mc = 0;
      $mdir = $store->root() . '/memberships/' . $sid;
      if (is_dir($mdir)) {
        $mc = count(glob($mdir . '/*.json') ?: []);
      } elseif (is_array($sp['member_ids'] ?? null)) {
        $mc = count($sp['member_ids']);
      } elseif (is_array($sp['members'] ?? null)) {
        $mc = count($sp['members']);
      } else {
        $mc = (int)($sp['member_count'] ?? 0);
      }
      
      $ownerId = strtolower((string)($sp['owner_id'] ?? ''));
      $ownerUsername = '';
      $ownerDisplay = '';
      if ($ownerId !== '') {
        $urow = $store->get("users/{$ownerId}.json");
        if (!is_array($urow)) {
          $urow = $store->get("identity/usernames/{$ownerId}.json");
        }
        if (is_array($urow)) {
          $ownerUsername = (string)($urow['username'] ?? '');
          $ownerDisplay = (string)($urow['display_name'] ?? $urow['displayName'] ?? $ownerUsername);
          if ($ownerUsername === '' && !empty($urow['user_id'])) {
            $uid = (string)$urow['user_id'];
            $byId = $store->get("users/{$uid}.json");
            if (is_array($byId)) {
              $ownerUsername = (string)($byId['username'] ?? '');
              $ownerDisplay = (string)($byId['display_name'] ?? $byId['displayName'] ?? $ownerUsername);
            }
          }
        }
        if ($ownerUsername === '') {
          // owner_id may already be a username
          $ownerUsername = preg_match('/^[a-z0-9_]{3,32}$/', $ownerId) ? $ownerId : '';
          $ownerDisplay = $ownerUsername !== '' ? $ownerUsername : 'community';
        }
      }
      if ($ownerUsername === '') {
        $ownerUsername = 'community';
        $ownerDisplay = 'Community';
      }

      $items[] = [
        'id' => $sid, 'space_id' => $sid,
        'name' => (string)($sp['name'] ?? $sid),
        'type' => (string)($sp['type'] ?? 'channel'),
        'is_public' => true,
        'member_count' => $mc,
        'memberCount' => $mc,
        'description' => (string)($sp['description'] ?? ''),
        'owner_id' => $ownerId,
        'owner_username' => $ownerUsername,
        'owner_display' => $ownerDisplay,
        'owner' => $ownerUsername,
        'created_by' => $ownerUsername,
      ];
    }
  }
  return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['items'=>$items,'spaces'=>$items,'discover'=>$items,'count'=>count($items)]);
});
$router->get('/api/v3/discover', function() {
  $store = new \Nyx\Shared\Infrastructure\Storage\FileStore(defined('DATA_DIR') ? DATA_DIR : '');
  $root = $store->root() . '/spaces';
  $items = [];
  if (is_dir($root)) {
    foreach (glob($root . '/*.json') ?: [] as $f) {
      $sp = json_decode((string)@file_get_contents($f), true);
      if (!is_array($sp)) continue;
      $pub = !empty($sp['is_public']) || !empty($sp['public']) || (($sp['type'] ?? '') === 'channel' && empty($sp['private']));
      if (!$pub) continue;
      $sid = (string)($sp['space_id'] ?? $sp['id'] ?? basename($f, '.json'));
      $mc = 0;
      $mdir = $store->root() . '/memberships/' . $sid;
      if (is_dir($mdir)) {
        $mc = count(glob($mdir . '/*.json') ?: []);
      } elseif (is_array($sp['member_ids'] ?? null)) {
        $mc = count($sp['member_ids']);
      } elseif (is_array($sp['members'] ?? null)) {
        $mc = count($sp['members']);
      } else {
        $mc = (int)($sp['member_count'] ?? 0);
      }
      
      $ownerId = strtolower((string)($sp['owner_id'] ?? ''));
      $ownerUsername = '';
      $ownerDisplay = '';
      if ($ownerId !== '') {
        $urow = $store->get("users/{$ownerId}.json");
        if (!is_array($urow)) {
          $urow = $store->get("identity/usernames/{$ownerId}.json");
        }
        if (is_array($urow)) {
          $ownerUsername = (string)($urow['username'] ?? '');
          $ownerDisplay = (string)($urow['display_name'] ?? $urow['displayName'] ?? $ownerUsername);
          if ($ownerUsername === '' && !empty($urow['user_id'])) {
            $uid = (string)$urow['user_id'];
            $byId = $store->get("users/{$uid}.json");
            if (is_array($byId)) {
              $ownerUsername = (string)($byId['username'] ?? '');
              $ownerDisplay = (string)($byId['display_name'] ?? $byId['displayName'] ?? $ownerUsername);
            }
          }
        }
        if ($ownerUsername === '') {
          // owner_id may already be a username
          $ownerUsername = preg_match('/^[a-z0-9_]{3,32}$/', $ownerId) ? $ownerId : '';
          $ownerDisplay = $ownerUsername !== '' ? $ownerUsername : 'community';
        }
      }
      if ($ownerUsername === '') {
        $ownerUsername = 'community';
        $ownerDisplay = 'Community';
      }

      $items[] = [
        'id' => $sid, 'space_id' => $sid,
        'name' => (string)($sp['name'] ?? $sid),
        'type' => (string)($sp['type'] ?? 'channel'),
        'is_public' => true,
        'member_count' => $mc,
        'memberCount' => $mc,
        'description' => (string)($sp['description'] ?? ''),
        'owner_id' => $ownerId,
        'owner_username' => $ownerUsername,
        'owner_display' => $ownerDisplay,
        'owner' => $ownerUsername,
        'created_by' => $ownerUsername,
      ];
    }
  }
  return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['items'=>$items,'spaces'=>$items,'discover'=>$items,'count'=>count($items)]);
});

// Compatibility aliases (groups / sync)
$router->post('/api/v3/groups/create', [$space,'create']);
$router->post('/api/v3/groups', [$space,'create']);
$router->get('/api/v3/groups/mine', [$space,'mine']);
$router->post('/api/v3/group/create', [$space,'create']);
$router->get('/api/v3/sync', [$msg,'sync']);
$router->post('/api/v3/sync', [$msg,'sync']);
$router->get('/api/v3/messages/list', [$msg,'sync']);

$router->get('/api/v3/profile', [$profile,'get']);
$router->post('/api/v3/profile', [$profile,'update']);
$router->put('/api/v3/profile', [$profile,'update']);
$router->get('/api/v3/account/profile', [$profile,'get']);
$router->post('/api/v3/account/profile', [$profile,'update']);
$router->get('/api/v3/prefs', [$prefs,'get']);
$router->post('/api/v3/prefs', [$prefs,'set']);
$router->put('/api/v3/prefs', [$prefs,'set']);
$router->get('/api/v3/account/preferences', [$prefs,'get']);
$router->put('/api/v3/account/preferences', [$prefs,'set']);
$router->post('/api/v3/account/preferences', [$prefs,'set']);
$router->get('/api/v3/contacts', [$contacts,'list']);
$router->post('/api/v3/contacts', [$contacts,'mutate']);
$router->post('/api/v3/contacts/add', [$contacts,'mutate']);
$router->post('/api/v3/contacts/remove', [$contacts,'mutate']);


$router->get('/api/v3/users/get', function($r) {
  $uid = strtolower(trim((string)($r->query('user_id') ?? $r->query('username') ?? $r->query('id') ?? '')));
  if ($uid === '') return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::error('user_id required', 422);
  $store = new \Nyx\Shared\Infrastructure\Storage\FileStore(defined('DATA_DIR')?DATA_DIR:(defined('NYX_DATA_DIR')?NYX_DATA_DIR:''));
  $row = $store->get('identity/usernames/'.$uid.'.json');
  if (!is_array($row)) $row = $store->get('users/'.$uid.'.json');
  if (!is_array($row)) return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['user_id'=>$uid,'username'=>$uid,'display_name'=>$uid,'displayName'=>$uid]);
  return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['user_id'=>$row['user_id']??$uid,'username'=>$row['username']??$uid,'display_name'=>$row['display_name']??$row['username']??$uid,'displayName'=>$row['display_name']??$row['username']??$uid,'public_key'=>$row['public_key']??'']);
});
$router->get('/api/v1/users/get', function($r) {

  $uid = strtolower(trim((string)($r->query('user_id') ?? $r->query('username') ?? $r->query('id') ?? '')));
  if ($uid === '') return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::error('user_id required', 422);
  $store = new \Nyx\Shared\Infrastructure\Storage\FileStore(defined('DATA_DIR')?DATA_DIR:(defined('NYX_DATA_DIR')?NYX_DATA_DIR:''));
  $row = $store->get('identity/usernames/'.$uid.'.json');
  if (!is_array($row)) $row = $store->get('users/'.$uid.'.json');
  if (!is_array($row)) return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['user_id'=>$uid,'username'=>$uid,'display_name'=>$uid,'displayName'=>$uid]);
  return \Nyx\Shared\Infrastructure\Http\ApiEnvelope::ok(['user_id'=>$row['user_id']??$uid,'username'=>$row['username']??$uid,'display_name'=>$row['display_name']??$row['username']??$uid,'displayName'=>$row['display_name']??$row['username']??$uid,'public_key'=>$row['public_key']??'']);
});

// --- Unified aliases (cleanup 2026-10-29) ---
$router->get('/api/v3/events', [$msg, 'events']);
$router->get('/api/v3/events/list', [$msg, 'events']);
$router->get('/api/v3/presence/status', [$pres, 'batch']);
$router->get('/api/v3/presence', [$pres, 'batch']);

// Off-chain ledger (token-ready foundation)
$ledger = new \Nyx\Modules\Ledger\Presentation\Http\Controllers\LedgerController();
$router->get('/api/v3/ledger/me', [$ledger, 'me']);
$router->get('/api/v3/ledger/history', [$ledger, 'history']);
$router->post('/api/v3/ledger/transfer', [$ledger, 'transfer']);
$router->post('/api/v3/ledger/faucet', [$ledger, 'faucet']);

// Device continue (session transfer without password re-entry)
$router->post('/api/v3/security/continue/create', [$sec, 'createContinueCode']);
$router->post('/api/v3/auth/continue/create', [$sec, 'createContinueCode']);
$router->post('/api/v3/security/continue/redeem', [$sec, 'redeemContinueCode']);
$router->post('/api/v3/auth/continue/redeem', [$sec, 'redeemContinueCode']);
$router->get('/api/v3/ledger/export', [$ledger, 'exportReceipts']);
$router->post('/api/v3/ledger/export', [$ledger, 'exportReceipts']);

// Work-chain + mining rewards
$mine = new \Nyx\Modules\Mining\Presentation\Http\Controllers\MiningController();
$chain = new \Nyx\Modules\Chain\Presentation\Http\Controllers\ChainController();
$router->get('/api/v3/mining/status', [$mine, 'status']);
$router->post('/api/v3/mining/register', [$mine, 'register']);
$router->post('/api/v3/mining/submit', [$mine, 'submitWork']);
$router->post('/api/v3/mining/tick', [$mine, 'nodeTick']);
$router->get('/api/v3/mining/leaderboard', [$mine, 'leaderboard']);
$router->get('/api/v3/chain/tip', [$chain, 'tip']);
$router->get('/api/v3/chain/block', [$chain, 'block']);
$router->get('/api/v3/chain/verify', [$chain, 'verify']);
$router->get('/api/v3/chain/range', [$chain, 'range']);
$router->get('/api/v3/chain/stats', [$chain, 'stats']);
$router->get('/api/v3/chain/operator', [$chain, 'operator']);
$router->post('/api/v3/chain/reindex', [$chain, 'reindex']);

$router->post('/api/v3/chain/gossip', [$chain, 'gossip']);
$router->get('/api/v3/chain/mesh', [$chain, 'mesh']);
$router->post('/api/v3/chain/announce', [$chain, 'announce']);
$router->post('/api/v3/mining/cron-token', [$mine, 'saveCronToken']);
$router->post('/api/v3/mining/cron', [$mine, 'cronTick']);
$router->get('/api/v3/spaces/admin-summary', [$space, 'adminSummary']);
$router->post('/api/v3/spaces/admin-summary', [$space, 'adminSummary']);
$router->get('/api/v3/groups/admin-summary', [$space, 'adminSummary']);

// Settlement bridge (work-chain → portable batch for L1/L2)
$settle = new \Nyx\Modules\Settlement\Presentation\Http\Controllers\SettlementController();
$router->get('/api/v3/settlement/status', [$settle, 'status']);
$router->get('/api/v3/settlement/batches', [$settle, 'list']);
$router->get('/api/v3/settlement/batch', [$settle, 'get']);
$router->post('/api/v3/settlement/export', [$settle, 'create']);
$router->post('/api/v3/settlement/mark', [$settle, 'mark']);

$router->get('/api/v3/settlement/mesh', [$settle, 'mesh']);
$router->post('/api/v3/settlement/gossip', [$settle, 'gossip']);
$router->post('/api/v3/settlement/announce', [$settle, 'announce']);

