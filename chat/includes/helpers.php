<?php
// Helpers used across the Anton Chat module.
//
// Pure functions only — no side effects, no DB writes at include time.
// Reuses lib/auth.php and lib/helpers.php (caller is expected to have
// require_once'd them already; this file does not re-require to avoid
// hiding ordering bugs in pages that include it).

/**
 * True if the given user (defaults to the current session user) should be
 * treated as a chat admin. There is no chat-specific role column — admin
 * status is derived from the existing AntonX RBAC, matching the gate used
 * by partials/nav.php for the "Admin" sidebar section.
 */
function chat_is_admin(?array $u = null): bool {
  $u = $u ?? auth_user();
  return in_array($u['role_name'] ?? '', ['Super Admin', 'CEO', 'Manager'], true);
}
