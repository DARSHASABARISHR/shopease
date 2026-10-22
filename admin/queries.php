<?php
declare(strict_types=1);
$adminPage = 'queries'; $pageTitle = 'Customer Queries';
require_once __DIR__ . '/../includes/functions.php';
$admin = require_admin();
$db = Database::getConnection();

/* AJAX: toggle Pending <-> Resolved */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require(true);
    $id = (int)($_POST['id'] ?? 0);
    $to = ($_POST['status'] ?? '') === 'Resolved' ? 'Resolved' : 'Pending';
    $st = $to === 'Resolved'
        ? $db->prepare("UPDATE support_queries SET status = 'Resolved', resolved_at = NOW(), resolved_by = ? WHERE query_id = ?")
        : $db->prepare("UPDATE support_queries SET status = 'Pending', resolved_at = NULL, resolved_by = ? WHERE query_id = ?");
    $st->execute([$admin['user_id'], $id]);
    json_response(['ok' => true, 'status' => $to]);
}

$f = (string)($_GET['status'] ?? 'Pending');
$f = in_array($f, ['Pending', 'Resolved', 'All'], true) ? $f : 'Pending';
$counts = ['Pending' => 0, 'Resolved' => 0];
foreach ($db->query('SELECT status, COUNT(*) c FROM support_queries GROUP BY status')->fetchAll() as $c) $counts[$c['status']] = (int)$c['c'];
$sql = 'SELECT * FROM support_queries' . ($f === 'All' ? '' : ' WHERE status = ?') . ' ORDER BY query_id DESC LIMIT 300';
$st = $db->prepare($sql); $st->execute($f === 'All' ? [] : [$f]);
$rows = $st->fetchAll();
require __DIR__ . '/_header.php';
?>
<h1>Customer queries</h1>
<p>
  <?php foreach (['Pending' => 'Pending (' . $counts['Pending'] . ')', 'Resolved' => 'Resolved (' . $counts['Resolved'] . ')', 'All' => 'All'] as $k => $l): ?>
    <a class="btn btn-sm <?= $f === $k ? 'btn-orange' : 'btn-light' ?>" href="<?= url('admin/queries.php?status=' . $k) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</p>
<div id="qAlert" class="alert" hidden></div>
<div class="panel" style="overflow-x:auto"><table class="tbl">
  <thead><tr><th>ID</th><th>Date</th><th>Customer</th><th>Email</th><th>Order</th><th style="min-width:280px">Message</th><th>Status</th><th>Action</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $q): $done = $q['status'] === 'Resolved'; ?>
    <tr data-id="<?= (int)$q['query_id'] ?>">
      <td>#<?= (int)$q['query_id'] ?></td>
      <td><small><?= e(date('d M Y, h:i A', strtotime((string)$q['created_at']))) ?></small></td>
      <td><?= e($q['name']) ?></td>
      <td><a href="mailto:<?= e($q['email']) ?>?subject=<?= rawurlencode('Re: Your ShopEase query #' . $q['query_id']) ?>"><?= e($q['email']) ?></a></td>
      <td><?= $q['order_id'] ? '<a href="' . e(url('admin/orders.php#o' . (int)$q['order_id'])) . '">#' . (int)$q['order_id'] . '</a>' : '-' ?></td>
      <td><?= nl2br(e($q['message'])) ?></td>
      <td><span class="pill <?= $done ? 'Paid' : 'Pending' ?>" data-badge><?= e($q['status']) ?></span><?php if ($done && $q['resolved_at']): ?><br><small><?= e(date('d M, h:i A', strtotime((string)$q['resolved_at']))) ?></small><?php endif; ?></td>
      <td><button class="btn btn-sm <?= $done ? 'btn-light' : 'btn-orange' ?> js-toggle" data-to="<?= $done ? 'Pending' : 'Resolved' ?>"><?= $done ? 'Reopen' : 'Mark resolved' ?></button></td>
    </tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="8">No queries here.</td></tr>'; ?>
  </tbody></table></div>
<script>
document.querySelectorAll('.js-toggle').forEach((b) => b.addEventListener('click', async () => {
  const tr = b.closest('tr'); b.disabled = true;
  const j = await postJSON(location.pathname, { id: tr.dataset.id, status: b.dataset.to });
  const a = document.getElementById('qAlert'); a.hidden = false;
  if (!j.ok) { a.className = 'alert alert-error'; a.textContent = j.message || 'Could not update.'; b.disabled = false; return; }
  a.className = 'alert alert-success'; a.textContent = 'Query #' + tr.dataset.id + ' marked ' + j.status + '.';
  setTimeout(() => location.reload(), 700);          // reload so filters, counts and the sidebar badge stay correct
}));
</script>
<?php require __DIR__ . '/_footer.php'; ?>
