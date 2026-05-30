<?php
/**
 * Reusable "Attachments" field for the New-task popups (attach-on-create).
 *
 * Drop this inside a task-create <form> (it must contain a hidden
 * <input name="csrf">). Files upload immediately to
 * upload_task_attachment_stage.php with an Anton-chat-style progress bar; each
 * staged file id is recorded in a hidden <input name="attachment_ids[]"> that
 * the form posts, and the create handler claims them via
 * claim_staged_task_attachments(). Behaviour is wired by styles/task_attachments.js,
 * which auto-initialises every [data-ta-uploader] on the page.
 */
?>
<div class="at-section ta-uploader" data-ta-uploader>
  <div class="at-section-label">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"></path></svg>
    Attachments
    <span class="ta-hint">Any file · up to 500 MB each</span>
  </div>
  <label class="ta-dropzone" tabindex="0" role="button" aria-label="Add files — click to browse or drop files here">
    <input type="file" class="ta-file-input" multiple hidden>
    <svg class="ta-dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
    <span class="ta-dropzone-text"><strong>Click to upload</strong> or drag &amp; drop</span>
  </label>
  <div class="ta-pending" hidden></div>
</div>
