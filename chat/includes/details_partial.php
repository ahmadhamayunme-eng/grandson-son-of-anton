<?php
// chat/includes/details_partial.php — right-side channel details panel (Phase 10).
//
// Expects in scope:
//   $detailsChannel       channel row (with topic, description, is_private, ...)
//   $detailsMembers       array of member rows (id, name, role_name)
//   $detailsMemberCount   total member count
//   $uid                  current user id
//
// JS toggles `.details[hidden]` and the `.cx-app.with-details` class on the
// outer grid. Tabs (About / Members / Pinned) use plain anchors so URL state
// is reflected in the back/forward stack.

$ch = $detailsChannel;
$createdBy = (int)($ch['created_by'] ?? 0);
$createdAt = (string)($ch['created_at'] ?? '');
?>
<aside class="details">
  <div class="details-head">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v.01M12 11v5"/></svg>
    <span class="title"><?= $ch['is_private'] ? '🔒' : '#' ?> <?= h($ch['slug']) ?></span>
    <button class="icon-btn" data-action="toggle-details" title="Close" aria-label="Close">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>

  <div class="details-tabs">
    <button class="details-tab active" data-details-tab="about">About</button>
    <button class="details-tab" data-details-tab="members">Members <span class="count"><?= (int)$detailsMemberCount ?></span></button>
    <button class="details-tab" data-details-tab="pinned">Pinned</button>
  </div>

  <div class="details-body" id="detailsBody">
    <!-- About -->
    <div class="details-pane" data-details-pane="about">
      <div class="section-title">Topic</div>
      <div class="details-text"><?= h((string)($ch['topic'] ?? '')) !== '' ? h((string)$ch['topic']) : '<span class="muted">No topic set</span>' ?></div>
      <?php if (!empty($ch['description'])): ?>
        <div class="section-title">Description</div>
        <div class="details-text"><?= h((string)$ch['description']) ?></div>
      <?php endif; ?>

      <div class="section-title">Details</div>
      <div class="about-grid">
        <div class="k">Created</div><div class="v"><?= h(date('M j, Y', strtotime($createdAt))) ?></div>
        <div class="k">Type</div><div class="v"><?= $ch['is_private'] ? 'Private' : 'Public' ?></div>
        <div class="k">Members</div><div class="v"><?= (int)$detailsMemberCount ?></div>
      </div>
    </div>

    <!-- Members -->
    <div class="details-pane" data-details-pane="members" hidden>
      <div class="section-title"><?= count($detailsMembers) ?> member<?= count($detailsMembers) === 1 ? '' : 's' ?></div>
      <?php foreach ($detailsMembers as $mu):
        $name = (string)$mu['name'];
        $initials = function_exists('user_initials') ? user_initials($name) : strtoupper(substr($name, 0, 2));
      ?>
        <div class="member-row" data-user-id="<?= (int)$mu['id'] ?>">
          <span class="av av-sm" data-initials="<?= h($initials) ?>"><?= h($initials) ?><span class="presence dot-presence" data-user-id="<?= (int)$mu['id'] ?>"></span></span>
          <div class="info">
            <div class="nm"><?= h($name) ?></div>
            <div class="rl"><?= h((string)($mu['role_name'] ?? 'Member')) ?></div>
          </div>
          <?php if (in_array((string)($mu['role_name'] ?? ''), ['Super Admin', 'CEO', 'Manager'], true)): ?>
            <span class="member-badge">Admin</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Pinned -->
    <div class="details-pane" data-details-pane="pinned" hidden>
      <div id="detailsPinnedList">
        <div class="cx-modal-loading">Loading…</div>
      </div>
    </div>
  </div>
</aside>
