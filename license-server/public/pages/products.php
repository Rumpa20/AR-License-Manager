<?php
/**
 * Products: create/edit/delete (safe), activate/deactivate, search, reports.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

use ARLM\Auth;
use ARLM\Csrf;
use ARLM\Logger;
use ARLM\Settings;
use ARLM\View;

$t = static fn (string $n): string => \ARLM\Database::table($n);

$licenseTypes = ['single_domain', 'multi_domain', 'unlimited_domain', 'monthly', 'annual', 'lifetime', 'trial', 'custom'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::validate($_POST['csrf_token'] ?? null);
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim(View::post('name'));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim(View::post('slug', trim(View::post('name')))))) ?? '';
        $uid = strtoupper(trim(View::post('product_uid')));
        if ($uid === '') {
            $uid = 'PRODUCT-' . strtoupper(bin2hex(random_bytes(3)));
        }
        $values = [
            'product_uid' => substr($uid, 0, 32),
            'name' => substr($name, 0, 191),
            'slug' => substr($slug, 0, 191),
            'description' => mb_substr(View::post('description'), 0, 2000),
            'version' => substr(trim(View::post('version', '1.0')), 0, 32) ?: '1.0',
            'category' => substr(trim(View::post('category', 'general')), 0, 64) ?: 'general',
            'default_license_type' => in_array(View::post('license_type'), $licenseTypes, true) ? View::post('license_type') : 'annual',
            'default_activation_limit' => max(1, (int) View::post('activation_limit', '1')),
            'default_duration_days' => View::post('duration_days') === '' ? null : max(1, (int) View::post('duration_days')),
        ];
        if ($name === '') {
            View::setFlash('error', 'Product name is required.');
        } else {
            try {
                if ($id > 0) {
                    \ARLM\Database::run(
                        'UPDATE ' . $t('products') . ' SET product_uid=?, name=?, slug=?, description=?, version=?, category=?, default_license_type=?, default_activation_limit=?, default_duration_days=? WHERE id=?',
                        array_merge(array_values($values), [$id])
                    );
                    Logger::audit('admin', Auth::adminId(), 'product_updated', 'product', $id, $name);
                    View::setFlash('ok', 'Product updated.');
                } else {
                    \ARLM\Database::run(
                        'INSERT INTO ' . $t('products') . ' (product_uid, name, slug, description, version, category, default_license_type, default_activation_limit, default_duration_days)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        array_values($values)
                    );
                    Logger::audit('admin', Auth::adminId(), 'product_created', 'product', (int) \ARLM\Database::pdo()->lastInsertId(), $name);
                    View::setFlash('ok', 'Product created.');
                }
            } catch (\PDOException $e) {
                View::setFlash('error', str_contains((string) $e->getMessage(), 'Duplicate') ? 'Product ID or slug already exists.' : 'Database error while saving product.');
            }
        }
    } elseif ($do === 'delete') {
        $id = (int) View::post('id');
        try {
            \ARLM\Database::run('DELETE FROM ' . $t('products') . ' WHERE id = ?', [$id]);
            Logger::audit('admin', Auth::adminId(), 'product_deleted', 'product', $id);
            View::setFlash('ok', 'Product deleted.');
        } catch (\PDOException) {
            View::setFlash('error', 'This product has licenses or orders and cannot be deleted. Deactivate it instead.');
        }
        View::redirect('index.php?page=products');
    } elseif ($do === 'toggle') {
        $id = (int) View::post('id');
        \ARLM\Database::run("UPDATE " . $t('products') . " SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?", [$id]);
        $status = (string) \ARLM\Database::value('SELECT status FROM ' . $t('products') . ' WHERE id = ?', [$id]);
        Logger::audit('admin', Auth::adminId(), 'product_' . ($status === 'active' ? 'activated' : 'deactivated'), 'product', $id);
        View::setFlash('ok', 'Product status changed.');
        View::redirect('index.php?page=products');
    }
    View::redirect('index.php?page=products');
}

if (View::query('export') === 'csv') {
    $rows = \ARLM\Database::all('SELECT product_uid, name, version, category, default_license_type, default_activation_limit, status, created_at FROM ' . $t('products') . ' ORDER BY name');
    View::csv(['product_uid','name','version','category','license_type','activation_limit','status','created_at'], $rows, 'arlm-products.csv');
}

$edit = null;
$editId = (int) View::query('edit');
if ($editId > 0) {
    $edit = \ARLM\Database::one('SELECT * FROM ' . $t('products') . ' WHERE id = ?', [$editId]);
}

[$sortCol, $sortDir] = View::sort(['name', 'created_at', 'status'], 'created_at');
$where = "WHERE (name LIKE ? OR product_uid LIKE ? OR slug LIKE ?)";
$args = array_fill(0, 3, '%' . View::query('q') . '%');
if (View::query('q') !== '') {
    $args = array_fill(0, 3, '%' . View::query('q') . '%');
} else {
    $where = '';
    $args = [];
}
$total = (int) \ARLM\Database::value("SELECT COUNT(*) FROM " . $t('products') . " {$where}", $args);
$pagen = View::page();
$rows = \ARLM\Database::all(
    "SELECT * FROM " . $t('products') . " {$where} ORDER BY {$sortCol} {$sortDir} LIMIT " . View::offset($pagen, 20) . ", 20",
    $args
);
?>
<h1><?= View::h(View::t('products')) ?></h1>

<form class="filters" method="get">
  <input type="hidden" name="page" value="products">
  <div><label><?= View::h(View::t('search')) ?></label><input type="text" name="q" value="<?= View::h(View::query('q')) ?>"></div>
  <button type="submit"><?= View::h(View::t('search')) ?></button>
  <a class="btn btn-sub" href="index.php?page=products&export=csv"><?= View::h(View::t('export_csv')) ?></a>
  <a class="btn btn-sub" href="index.php?page=products"><?= View::h(View::t('create')) ?> / <?= View::h(View::t('edit')) ?></a>
</form>

<form class="card" method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="do" value="save">
  <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <h2><?= View::h($edit ? View::t('edit') : View::t('create')) ?></h2>
  <label><?= View::h(View::t('name')) ?> *</label>
  <input type="text" name="name" value="<?= View::h($edit['name'] ?? '') ?>" required>
  <label><?= View::h(View::t('slug')) ?> (URL)</label>
  <input type="text" name="slug" value="<?= View::h($edit['slug'] ?? '') ?>" placeholder="my-product">
  <label><?= View::h(View::t('product_id')) ?> <?= View::h('(auto if empty)') ?></label>
  <input type="text" name="product_uid" value="<?= View::h($edit['product_uid'] ?? '') ?>">
  <label><?= View::h(View::t('description')) ?></label>
  <textarea name="description"><?= View::h($edit['description'] ?? '') ?></textarea>
  <label><?= View::h(View::t('version')) ?></label>
  <input type="text" name="version" value="<?= View::h($edit['version'] ?? '1.0') ?>">
  <label><?= View::h(View::t('category')) ?></label>
  <input type="text" name="category" value="<?= View::h($edit['category'] ?? 'general') ?>">
  <label><?= View::h(View::t('license_type')) ?></label>
  <select name="license_type">
<?php foreach ($licenseTypes as $lt): ?>
    <option value="<?= View::h($lt) ?>" <?= ($edit['default_license_type'] ?? '') === $lt ? 'selected' : '' ?>><?= View::h(str_replace('_', ' ', $lt)) ?></option>
<?php endforeach; ?>
  </select>
  <label><?= View::h(View::t('activation_limit')) ?></label>
  <input type="number" name="activation_limit" min="1" value="<?= (int) ($edit['default_activation_limit'] ?? 1) ?>">
  <label><?= View::h(View::t('duration_days')) ?> (empty = lifetime)</label>
  <input type="number" name="duration_days" min="1" value="<?= View::h((string) ($edit['default_duration_days'] ?? '')) ?>">
  <button type="submit"><?= View::h(View::t('save')) ?></button>
</form>

<div class="card">
  <table>
    <tr>
      <th><a href="index.php?page=products&sort=name&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>"><?= View::h(View::t('name')) ?></a></th>
      <th><?= View::h(View::t('product_id')) ?></th><th><?= View::h(View::t('version')) ?></th>
      <th><?= View::h(View::t('activation_limit')) ?></th><th><?= View::h(View::t('duration_days')) ?></th>
      <th><a href="index.php?page=products&sort=status&dir=ASC"><?= View::h(View::t('status')) ?></a></th>
      <th><a href="index.php?page=products&sort=created_at&dir=<?= View::h($sortDir === 'ASC' ? 'DESC' : 'ASC') ?>"><?= View::h(View::t('created')) ?></a></th>
      <th><?= View::h(View::t('actions')) ?></th>
    </tr>
<?php foreach ($rows as $row): ?>
    <tr>
      <td><strong><?= View::h($row['name']) ?></strong><br><span class="muted mono"><?= View::h($row['slug']) ?></span></td>
      <td class="mono"><?= View::h($row['product_uid']) ?></td>
      <td><?= View::h($row['version']) ?></td>
      <td><?= (int) $row['default_activation_limit'] ?></td>
      <td><?= $row['default_duration_days'] === null ? '?' : (int) $row['default_duration_days'] ?></td>
      <td><?= View::statusBadge((string) $row['status']) ?></td>
      <td><?= View::h($row['created_at']) ?></td>
      <td class="row-actions">
        <a class="btn btn-sm btn-sub" href="index.php?page=products&edit=<?= (int) $row['id'] ?>"><?= View::h(View::t('edit')) ?></a>
        <form class="inline-form" method="post" onsubmit="return confirm('<?= View::h(View::t('confirm_delete'), ENT_QUOTES) ?>');">
          <?= Csrf::field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <button class="btn-sm btn-danger" type="submit"><?= View::h(View::t('delete')) ?></button>
        </form>
        <form class="inline-form" method="post">
          <?= Csrf::field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <button class="btn-sm btn-sub" type="submit"><?= View::h($row['status'] === 'active' ? View::h(View::t('inactive')) : View::h(View::t('active'))) ?></button>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
  </table>
  <?= View::paginate($total, $pagen, 20, 'index.php?page=products&q=' . urlencode(View::query('q'))) ?>
</div>
