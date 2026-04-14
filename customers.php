<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/util.php';

start_app_session();
require_role(['admin', 'cashier']);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'add_customer') {
      $name = trim((string)($_POST['name'] ?? ''));
      if ($name === '') throw new RuntimeException('Name is required.');

      // Check for duplicate name
      $check = db()->prepare("SELECT id FROM customers WHERE LOWER(name) = LOWER(?)");
      $check->execute([$name]);
      if ($check->fetch()) throw new RuntimeException('A customer named "' . $name . '" already exists.');

      db()->prepare("INSERT INTO customers (name) VALUES (?)")->execute([$name]);
      flash_set('ok', 'Customer added.');
      redirect('customers.php');
    }

    if ($action === 'edit_customer') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      if ($id <= 0) throw new RuntimeException('Invalid customer.');
      if ($name === '') throw new RuntimeException('Name is required.');

      db()->prepare("UPDATE customers SET name=? WHERE id=?")->execute([$name, $id]);
      flash_set('ok', 'Customer updated.');
      redirect('customers.php');
    }

    if ($action === 'delete_customer') {
      require_role(['admin']);
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid customer.');

      db()->prepare("DELETE FROM customers WHERE id=?")->execute([$id]);
      flash_set('ok', 'Customer deleted.');
      redirect('customers.php');
    }
  } catch (Throwable $e) {
    flash_set('danger', $e->getMessage());
    redirect('customers.php');
  }
}

$flash = flash_get();
$q = trim((string)($_GET['q'] ?? ''));

if ($q !== '') {
  $stmt = db()->prepare("SELECT * FROM customers WHERE name LIKE ? ORDER BY name ASC");
  $like = '%' . $q . '%';
  $stmt->execute([$like]);
  $customers = $stmt->fetchAll();
} else {
  $customers = db()->query("SELECT * FROM customers ORDER BY name ASC")->fetchAll();
}

render_header('Customers', 'customers');
?>

<?php if ($flash): ?>
  <div class="alert alert--<?php echo h($flash['type']); ?>" style="margin-bottom:14px;">
    <?php echo h($flash['message']); ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="row">
    <div>
      <div class="card__title">Add Customer</div>
    </div>
    <div class="spacer"></div>
  </div>

  <form method="post" class="form" style="margin-top:12px;">
    <input type="hidden" name="action" value="add_customer">
    <div class="row">
      <div class="field" style="flex:2; min-width:220px;">
        <div class="label">Name</div>
        <input name="name" placeholder="Juan Dela Cruz" required>
      </div>
      <div class="field" style="align-self:end;">
        <button class="btn" type="submit">Add</button>
      </div>
    </div>
  </form>
</div>

<div class="card" style="margin-top:14px;">
  <div class="row">
    <div>
      <div class="card__title">Customer List</div>
      <div style="margin-top:6px;color:var(--muted);">Search by name.</div>
    </div>
    <div class="spacer"></div>
    <form method="get" class="row" style="gap:10px;">
      <input name="q" placeholder="Search..." value="<?php echo h($q); ?>" style="width:240px;">
      <button class="btn btn--ghost" type="submit">Search</button>
      <a class="btn btn--ghost" href="customers.php">Clear</a>
    </form>
  </div>

  <div style="overflow:auto; margin-top:12px;">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th style="width:280px;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($customers as $c): ?>
        <tr>
          <td><?php echo (int)$c['id']; ?></td>
          <td><strong><?php echo h($c['name']); ?></strong></td>
          <td>
            <details>
              <summary class="btn btn--ghost">Edit</summary>
              <div class="card" style="margin-top:10px; min-width:300px;">
                <form method="post" class="form">
                  <input type="hidden" name="action" value="edit_customer">
                  <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                  <div class="field">
                    <div class="label">Name</div>
                    <input name="name" value="<?php echo h($c['name']); ?>" required>
                  </div>
                  <div class="row">
                    <button class="btn" type="submit">Save</button>
                  </div>
                </form>
                <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
                  <button type="button" class="btn btn--danger btn--block" style="margin-top:10px;" onclick="openDeleteModal(<?php echo (int)$c['id']; ?>, '<?php echo h($c['name']); ?>')">Delete Customer</button>
                <?php endif; ?>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>

<!-- Delete Customer Modal -->
<div id="deleteModal" style="display:none; position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,0.35); align-items:center; justify-content:center;" onclick="if(event.target.id==='deleteModal')closeDeleteModal()">
  <div style="background:#fff; border:1px solid var(--border); border-radius:14px; width:95%; max-width:400px; box-shadow:0 8px 32px rgba(0,0,0,.1); animation:modalIn 0.2s ease-out;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:18px 20px; border-bottom:1px solid var(--border);">
      <h3 style="margin:0; font-size:16px; color:var(--text);">🗑️ Delete Customer</h3>
      <span style="color:var(--muted); font-size:24px; cursor:pointer; line-height:1;" onclick="closeDeleteModal()">&times;</span>
    </div>
    <div style="text-align:center; padding:28px 24px;">
      <div style="width:56px; height:56px; margin:0 auto 16px; border-radius:50%; background:rgba(239,68,68,0.12); display:flex; align-items:center; justify-content:center; font-size:28px;">🗑️</div>
      <p style="color:var(--text); font-size:15px; margin:0 0 6px;">Are you sure you want to delete</p>
      <p style="color:#ef4444; font-size:18px; font-weight:700; margin:0 0 8px;">"<span id="delCustName"></span>"</p>
      <p style="color:var(--muted); font-size:13px; margin:0;">This action cannot be undone. All associated records will be removed.</p>
      <div style="display:flex; gap:10px; justify-content:center; margin-top:22px;">
        <button type="button" class="btn btn--ghost" onclick="closeDeleteModal()">Cancel</button>
        <button type="button" class="btn btn--danger" onclick="confirmDelete()">Delete</button>
      </div>
    </div>
  </div>
</div>

<form id="deleteForm" method="post" style="display:none;">
  <input type="hidden" name="action" value="delete_customer">
  <input type="hidden" name="id" id="delCustId">
</form>

<style>
@keyframes modalIn {
  from { opacity: 0; transform: scale(0.92) translateY(10px); }
  to   { opacity: 1; transform: scale(1) translateY(0); }
}
</style>

<script>
let delId = 0;
function openDeleteModal(id, name) {
  delId = id;
  document.getElementById('delCustName').textContent = name;
  document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
function confirmDelete() {
  document.getElementById('delCustId').value = delId;
  document.getElementById('deleteForm').submit();
}
</script>

