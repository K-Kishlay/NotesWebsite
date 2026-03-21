<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$userId = (int) current_user()['id'];
$stmt = db()->prepare('SELECT n.id, n.title, n.subject, n.price, n.description FROM cart c JOIN notes n ON n.id = c.note_id WHERE c.user_id = ? ORDER BY c.added_at DESC');
$stmt->execute([$userId]);
$items = $stmt->fetchAll();
$total = 0.0;
foreach ($items as $item) {
    $total += (float) $item['price'];
}

$pageTitle = 'NotesPro | Cart';
$activePage = 'cart';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h3 fw-bold mb-1">My Cart</h1>
  <p class="text-secondary mb-0">Review your selected notes before checkout.</p>
</header>

<section class="card border-0 shadow-sm mb-3">
  <div class="card-body p-4">
    <?php if (!$items): ?>
      <p class="mb-0 text-secondary">Your cart is empty. <a href="notes.php">Browse notes</a>.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr><th>Title</th><th>Subject</th><th>Price</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?= e($item['title']) ?></td>
                <td><?= e($item['subject'] ?: 'General') ?></td>
                <td><?= ((float)$item['price'] > 0) ? '$' . number_format((float)$item['price'], 2) : 'Free' ?></td>
                <td>
                  <form method="post" action="cart_action.php" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="note_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="action" value="remove">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Remove</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3">
        <strong>Total: $<?= number_format($total, 2) ?></strong>
        <a class="btn btn-brand" href="payment.php?scope=cart">Proceed to Payment</a>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
