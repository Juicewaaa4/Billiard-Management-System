<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin']);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'add_user') {
      $username = trim((string)($_POST['username'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      $role = (string)($_POST['role'] ?? 'cashier');
      if ($username === '' || $password === '') throw new RuntimeException('Username and password are required.');
      if (!in_array($role, ['admin', 'cashier'], true)) $role = 'cashier';

      db()->prepare("INSERT INTO users (username, password_hash, role) VALUES (?,?,?)")
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
      flash_set('ok', 'User created.');
      redirect('users.php');
    }

    if ($action === 'edit_user') {
      $id = (int)($_POST['id'] ?? 0);
      $newUsername = trim((string)($_POST['username'] ?? ''));
      if ($id <= 0 || $newUsername === '') throw new RuntimeException('Username is required.');

      $existing = db()->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
      $existing->execute([$newUsername, $id]);
      if ($existing->fetch()) throw new RuntimeException('Username already taken.');

      db()->prepare("UPDATE users SET username=? WHERE id=?")
        ->execute([$newUsername, $id]);

      // Update session if editing own account
      if ((int)current_user()['id'] === $id) {
        $_SESSION['user']['username'] = $newUsername;
      }

      flash_set('ok', 'Username updated.');
      redirect('users.php');
    }

    if ($action === 'reset_password') {
      $id = (int)($_POST['id'] ?? 0);
      $password = (string)($_POST['password'] ?? '');
      if ($id <= 0 || $password === '') throw new RuntimeException('Invalid request.');

      db()->prepare("UPDATE users SET password_hash=? WHERE id=?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
      flash_set('ok', 'Password updated.');
      redirect('users.php');
    }

    if ($action === 'delete_user') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid user.');
      if ((int)current_user()['id'] === $id) throw new RuntimeException('You cannot delete your own account.');

      db()->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
      flash_set('ok', 'User deleted.');
      redirect('users.php');
    }
  } catch (Throwable $e) {
    flash_set('danger', $e->getMessage());
    redirect('users.php');
  }
}

$flash = flash_get();
$users = db()->query("SELECT id, username, role, created_at FROM users ORDER BY id ASC")->fetchAll();

render_header('Users', 'users');
?>

<?php if ($flash): ?>
  <div class="alert alert--<?php echo h($flash['type']); ?>" style="margin-bottom:14px;">
    <?php echo h($flash['message']); ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="row">
    <div>
      <div class="card__title">Add User</div>
      <div style="margin-top:6px;color:var(--muted);">Admin only.</div>
    </div>
  </div>

  <form method="post" class="form" style="margin-top:12px;">
    <input type="hidden" name="action" value="add_user">
    <div class="row">
      <div class="field" style="min-width:180px; flex:1;">
        <div class="label">Username</div>
        <input name="username" required>
      </div>
      <div class="field" style="min-width:180px; flex:1;">
        <div class="label">Password</div>
        <input name="password" type="password" required>
      </div>
      <div class="field" style="min-width:160px;">
        <div class="label">Role</div>
        <select name="role">
          <option value="cashier">Cashier</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="field" style="align-self:end;">
        <button class="btn" type="submit">Create</button>
      </div>
    </div>
  </form>
</div>

<div class="card" style="margin-top:14px;">
  <div class="card__title">Users</div>
  <div style="overflow:auto; margin-top:12px;">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Username</th>
          <th>Role</th>
          <th>Created</th>
          <th style="width:340px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?php echo (int)$u['id']; ?></td>
            <td><strong><?php echo h($u['username']); ?></strong></td>
            <td><span class="badge"><?php echo h($u['role']); ?></span></td>
            <td><?php echo h($u['created_at']); ?></td>
            <td>
              <div class="row" style="gap:8px;">
                <details>
                  <summary class="btn btn--ghost">Edit</summary>
                  <div class="card" style="margin-top:10px; min-width:300px;">
                    <form method="post" class="form">
                      <input type="hidden" name="action" value="edit_user">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <div class="field">
                        <div class="label">Username</div>
                        <input name="username" value="<?php echo h($u['username']); ?>" required>
                      </div>
                      <button class="btn" type="submit">Save</button>
                    </form>
                  </div>
                </details>

                <details>
                  <summary class="btn btn--ghost">Reset password</summary>
                  <div class="card" style="margin-top:10px; min-width:300px;">
                    <form method="post" class="form">
                      <input type="hidden" name="action" value="reset_password">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <div class="field">
                        <div class="label">New password</div>
                        <input name="password" type="password" required>
                      </div>
                      <button class="btn" type="submit">Update</button>
                    </form>
                  </div>
                </details>

                <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete this user?');">
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                  <button class="btn btn--danger" type="submit" <?php echo ((int)current_user()['id'] === (int)$u['id']) ? 'disabled' : ''; ?>>
                    Delete
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>

