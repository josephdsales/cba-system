<?php
// Shared section manager used by BOTH admin and teacher.
// New model: sections can have multiple teachers (co-teachers).
// Admin manages all assignments via multi-select.
// Teachers see ALL sections but can only edit sections assigned to them.
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/auth.php';

$me = require_login();
if (!in_array($me['role'], ['admin', 'teacher'], true)) { http_response_code(403); exit('Forbidden'); }
$is_admin_page = ($me['role'] === 'admin');
$user = $me;

// Get all teachers for assignment UI
$teachers = db()->query("SELECT id, fullname FROM users WHERE role='teacher' ORDER BY (lastname IS NULL), lastname, firstname, fullname")->fetchAll();

// Get section-teacher assignments for display
$section_teachers = [];
$concatFn = (db_driver() === 'pgsql') ? 'STRING_AGG(teacher_id::text, \',\')' : 'GROUP_CONCAT(teacher_id)';
$st = db()->prepare("SELECT section_id, $concatFn as teacher_ids FROM section_teachers GROUP BY section_id");
$st->execute();
foreach ($st->fetchAll() as $row) {
    $section_teachers[$row['section_id']] = explode(',', $row['teacher_ids']);
}

// Check if teacher can edit a section (admin or assigned teacher)
function can_edit_section(array $s, array $me, array $section_teachers): bool {
    if ($me['role'] === 'admin') return true;
    $sid = $s['id'];
    return isset($section_teachers[$sid]) && in_array($me['id'], $section_teachers[$sid]);
}

// Check if teacher can delete (admin only)
function can_delete_section(array $s, array $me): bool {
    return $me['role'] === 'admin';
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT s.* FROM sections s WHERE s.id=?');
    $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
    if ($edit && !can_edit_section($edit, $me, $section_teachers)) { http_response_code(403); exit('Forbidden: only assigned teachers can edit this section.'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $back = $is_admin_page ? 'admin_sections.php' : 'teacher_sections.php';
    if (($_POST['action'] ?? '') === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        
        // Get assigned teacher IDs
        $assigned_teacher_ids = [];
        if ($is_admin_page) {
            $assigned_teacher_ids = array_map('intval', $_POST['assigned_teacher_ids'] ?? []);
        } else {
            // Teachers can only assign to themselves
            $assigned_teacher_ids = [$me['id']];
        }

        if ($name === '') {
            set_flash('Section name is required.');
        } else {
            try {
                if ($id > 0) {
                    $st = db()->prepare('SELECT * FROM sections WHERE id=?');
                    $st->execute([$id]); $cur = $st->fetch();
                    if (!$cur || !can_edit_section($cur, $me, $section_teachers)) {
                        set_flash('Not allowed to edit this section.');
                    } else {
                        $st = db()->prepare('UPDATE sections SET name=?, description=? WHERE id=?');
                        $st->execute([$name, $desc ?: null, $id]);
                        
                        // Update teacher assignments (admin only)
                        if ($is_admin_page) {
                            db()->prepare('DELETE FROM section_teachers WHERE section_id=?')->execute([$id]);
                            if (!empty($assigned_teacher_ids)) {
                                $ins = db()->prepare('INSERT IGNORE INTO section_teachers (section_id, teacher_id) VALUES (?, ?)');
                                foreach ($assigned_teacher_ids as $tid) {
                                    $ins->execute([$id, $tid]);
                                }
                            }
                        }
                        set_flash('Section updated.');
                    }
                } else {
                    // New section - creator is always assigned
                    $st = db()->prepare('INSERT INTO sections (name, description, created_by) VALUES (?, ?, ?)');
                    $st->execute([$name, $desc ?: null, $me['id']]);
                    $id = (int)db()->lastInsertId();
                    
                    // Assign teachers
                    if ($is_admin_page && !empty($assigned_teacher_ids)) {
                        $ins = db()->prepare('INSERT IGNORE INTO section_teachers (section_id, teacher_id) VALUES (?, ?)');
                        foreach ($assigned_teacher_ids as $tid) {
                            $ins->execute([$id, $tid]);
                        }
                    } else {
                        // Teacher creates section - assign to themselves
                        $ins = db()->prepare('INSERT IGNORE INTO section_teachers (section_id, teacher_id) VALUES (?, ?)');
                        $ins->execute([$id, $me['id']]);
                    }
                    set_flash($is_admin_page ? 'Section added.' : 'Section added (assigned to you).');
                }
            } catch (PDOException $ex) { set_flash('Error: section name already exists.'); }
        }
        header("Location: $back"); exit;
    }
    
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int)$_POST['id'];
        $st = db()->prepare('SELECT * FROM sections WHERE id=?');
        $st->execute([$id]); $cur = $st->fetch();
        if (!$cur || !can_delete_section($cur, $me)) {
            set_flash('Not allowed to delete this section.');
        } else {
            try {
                $st = db()->prepare('DELETE FROM sections WHERE id=?');
                $st->execute([$id]);
                set_flash('Section deleted.');
            } catch (PDOException $ex) { set_flash('Cannot delete: section is in use by students/exams.'); }
        }
        header("Location: $back"); exit;
    }
}

// Fetch sections with teacher assignments
$sections = db()->query("
    SELECT s.*, 
           (SELECT COUNT(*) FROM users u WHERE u.section_id=s.id) AS student_count,
           (SELECT GROUP_CONCAT(t.fullname ORDER BY t.fullname) 
            FROM section_teachers st 
            JOIN users t ON t.id=st.teacher_id 
            WHERE st.section_id=s.id) AS teacher_names
    FROM sections s 
    ORDER BY s.name
")->fetchAll();

// Get students for each section for the modal
$section_students = [];
if ($sections) {
    $section_ids = array_column($sections, 'id');
    $placeholders = implode(',', array_fill(0, count($section_ids), '?'));
    $st = db()->prepare("SELECT u.*, s.name AS section_name FROM users u LEFT JOIN sections s ON s.id=u.section_id WHERE u.role='student' AND u.section_id IN ($placeholders)");
    $st->execute($section_ids);
    $students = $st->fetchAll();
    foreach ($students as $stu) {
        $section_students[$stu['section_id']][] = $stu;
    }
}

$title = 'Manage Sections';
include __DIR__ . '/includes/header.php';
$this_page = $is_admin_page ? 'admin_sections.php' : 'teacher_sections.php';
?>
<div class="card">
  <h3 style="margin-top:0"><?= $edit ? 'Edit section' : 'Add section' ?></h3>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="grid two">
      <div><label>Section name</label><input type="text" name="name" required value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. BSIT-1A"></div>
      <div><label>Description (optional)</label><input type="text" name="description" value="<?= e($edit['description'] ?? '') ?>"></div>
    </div>
    <?php if ($is_admin_page): ?>
      <label>Assign teachers</label>
      <select name="assigned_teacher_ids[]" multiple size="5" style="height:auto">
        <option value="0">— Shared (all teachers) —</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?= $t['id'] ?>" 
            <?= ($edit && isset($section_teachers[$edit['id']]) && in_array($t['id'], $section_teachers[$edit['id']])) ? 'selected' : '' ?>>
            <?= e($t['fullname']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small class="hint">Hold Ctrl/Cmd to select multiple. Leave empty for shared (all teachers).</small>
    <?php else: ?>
      <p class="hint">This section will be assigned to you.</p>
    <?php endif; ?>
    <div class="btnrow"><button class="btn" type="submit"><?= $edit ? 'Save' : 'Add section' ?></button>
    <?php if ($edit): ?><a class="btn ghost" href="<?= $this_page ?>">Cancel</a><?php endif; ?></div>
  </form>
</div>
<div class="card"><div class="table-wrap"><table>
  <tr><th>Section</th><th>Description</th><th>Assigned Teachers</th><th>Students</th><th>Actions</th></tr>
  <?php foreach ($sections as $s): ?>
  <tr>
    <td><b><?= e($s['name']) ?></b></td><td><?= e($s['description'] ?? '') ?></td>
    <td><?= e($s['teacher_names'] ?? 'Shared (all)') ?></td>
    <td><?= $s['student_count'] ?></td>
    <td><?php if (can_edit_section($s, $me, $section_teachers)): ?><div class="btnrow" style="margin:0">
      <a class="btn small ghost" href="<?= $this_page ?>?edit=<?= $s['id'] ?>">Edit</a>
      <button type="button" class="btn small" onclick="openStudentsModal(<?= $s['id'] ?>)">👥 Students</button>
      <?php if ($is_admin_page): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete section <?= e($s['name']) ?>?')">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
          <button class="btn small danger" type="submit">Delete</button>
        </form>
      <?php endif; ?>
    </div><?php else: ?><small class="hint">read-only</small><?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$sections): ?><tr><td colspan="5" class="hint">No sections yet.</td></tr><?php endif; ?>
</table></div></div>

<div id="students-modal" class="modal" style="display:none">
  <div class="modal-backdrop" onclick="closeStudentsModal()"></div>
  <div class="modal-content card" style="max-width:800px;width:90%;max-height:80vh;overflow:auto" onclick="event.stopPropagation()">
    <h3 style="margin-top:0" id="students-modal-title">👥 Students in Section</h3>
    <div style="max-height:60vh;overflow:auto">
      <table style="width:100%;border-collapse:collapse;font-size:.85rem">
        <thead style="position:sticky;top:0;background:var(--card);z-index:1">
          <tr style="border-bottom:2px solid var(--line)">
            <th style="text-align:left;padding:8px">Name</th>
            <th style="text-align:center;padding:8px">Gender</th>
            <th style="text-align:center;padding:8px">Username</th>
            <th style="text-align:center;padding:8px">Registered</th>
          </tr>
        </thead>
        <tbody id="students-modal-body">
        </tbody>
      </table>
    </div>
    <div class="btnrow" style="margin-top:12px">
      <button class="btn ghost" onclick="closeStudentsModal()">Close</button>
    </div>
  </div>
</div>

<script>
(function () {
  var studentsBySection = <?= json_encode($section_students) ?>;
  var modal = document.getElementById('students-modal');
  var title = document.getElementById('students-modal-title');
  var tbody = document.getElementById('students-modal-body');

  window.openStudentsModal = function (sectionId) {
    var students = studentsBySection[sectionId] || [];
    var section = students[0]?.section_name || 'Section';
    title.textContent = '👥 Students in ' + section;
    tbody.innerHTML = students.map(function (s) {
      return '<tr style="border-bottom:1px solid var(--line)">' +
        '<td style="padding:8px">' + (s.fullname || '') + '</td>' +
        '<td style="text-align:center;padding:8px">' + (s.gender || '') + '</td>' +
        '<td style="text-align:center;padding:8px">' + (s.username || '') + '</td>' +
        '<td style="text-align:center;padding:8px">' + (s.created_at ? new Date(s.created_at).toLocaleDateString() : '') + '</td>' +
      '</tr>';
    }).join('') || '<tr><td colspan="4" class="hint" style="padding:16px;text-align:center">No students in this section</td></tr>';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  };
  window.closeStudentsModal = function () {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  };
})();
</script>
<style>
.modal { position:fixed; top:0; left:0; right:0; bottom:0; z-index:100; display:flex; align-items:center; justify-content:center; padding:20px; }
.modal-backdrop { position:absolute; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); }
.modal-content { position:relative; background:var(--card); border-radius:var(--radius); box-shadow:0 20px 40px rgba(0,0,0,.2); }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>