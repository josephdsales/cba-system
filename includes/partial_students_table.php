<?php
// Partial template for students table (used by AJAX)
// sort_link() is defined in admin_students.php
?>
<tr>
  <th><?= sort_link('Fullname', 'name') ?></th>
  <th><?= sort_link('Gender', 'gender') ?></th>
  <th><?= sort_link('Section', 'section') ?></th>
  <th><?= sort_link('Username', 'username') ?></th>
  <th>Actions</th>
</tr>
<?php foreach ($students as $s): ?>
<tr>
  <td><?= e($s['fullname']) ?></td><td><?= e($s['gender']) ?></td>
  <td><?= e($s['section_name'] ?? '—') ?></td><td><?= e($s['username']) ?></td>
  <td>
    <div class="btnrow" style="margin:0">
      <a class="btn small ghost" href="admin_students.php?edit=<?= $s['id'] ?>">Edit</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Reset password for <?= e($s['username']) ?>?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <input type="text" name="new_password" placeholder="new pass" required style="width:110px;display:inline-block" minlength="6">
        <button class="btn small ok" type="submit">Reset PW</button>
      </form>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete this student and all their attempts?')">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button class="btn small danger" type="submit">Delete</button>
      </form>
    </div>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$students): ?><tr><td colspan="5" class="hint">No students found.</td></tr><?php endif; ?>