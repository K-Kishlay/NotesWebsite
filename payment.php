<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$userId = (int) current_user()['id'];

function load_single_note(PDO $pdo, int $userId, int $noteId): ?array {
    $stmt = $pdo->prepare('SELECT n.id, n.title, n.subject, n.price, n.description,
                           EXISTS(SELECT 1 FROM purchases p WHERE p.user_id = ? AND p.note_id = n.id) AS owned
                           FROM notes n WHERE n.id = ? LIMIT 1');
    $stmt->execute([$userId, $noteId]);
    return $stmt->fetch() ?: null;
}

function load_cart_items(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT n.id, n.title, n.subject, n.price, n.description
                           FROM cart c
                           JOIN notes n ON n.id = c.note_id
                           WHERE c.user_id = ?
                           ORDER BY c.added_at DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

$scope = $_REQUEST['scope'] ?? '';
if ($scope !== 'cart' && $scope !== 'single') {
    $scope = isset($_REQUEST['note_id']) ? 'single' : 'cart';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    verify_csrf_or_abort();
    $paymentMethod = $_POST['payment_method'] ?? '';
    $allowedMethods = ['card', 'upi', 'netbanking', 'wallet'];

    $items = [];
    if ($scope === 'single') {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $note = load_single_note($pdo, $userId, $noteId);
        if (!$note) {
            set_flash('danger', 'Selected note not found.');
            redirect('notes.php');
        }
        if ((int) $note['owned'] === 1) {
            set_flash('info', 'You already own this note.');
            redirect('library.php');
        }
        $items[] = $note;
    } else {
        $items = load_cart_items($pdo, $userId);
        if (!$items) {
            set_flash('warning', 'Your cart is empty.');
            redirect('cart.php');
        }
    }

    $total = 0.0;
    foreach ($items as $item) {
        $total += (float) $item['price'];
    }

    if ($total > 0 && !in_array($paymentMethod, $allowedMethods, true)) {
        set_flash('danger', 'Please choose a payment method.');
        redirect('payment.php?scope=' . $scope . ($scope === 'single' ? '&note_id=' . (int) $items[0]['id'] : ''));
    }

    $pdo->beginTransaction();
    try {
        $insertPurchase = $pdo->prepare('INSERT INTO purchases (user_id, note_id, amount, payment_status) VALUES (?, ?, ?, ?)');
        $insertDownload = $pdo->prepare('INSERT INTO downloads (user_id, note_id) VALUES (?, ?)');
        $checkOwned = $pdo->prepare('SELECT 1 FROM purchases WHERE user_id = ? AND note_id = ? LIMIT 1');

        foreach ($items as $item) {
            $noteId = (int) $item['id'];
            $checkOwned->execute([$userId, $noteId]);
            if ($checkOwned->fetch()) {
                continue;
            }

            $insertPurchase->execute([$userId, $noteId, (float) $item['price'], 'paid']);
            $insertDownload->execute([$userId, $noteId]);
        }

        if ($scope === 'cart') {
            $clear = $pdo->prepare('DELETE FROM cart WHERE user_id = ?');
            $clear->execute([$userId]);
        } else {
            $clear = $pdo->prepare('DELETE FROM cart WHERE user_id = ? AND note_id = ?');
            $clear->execute([$userId, (int) $items[0]['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        set_flash('danger', 'Payment failed. Please try again.');
        redirect('payment.php?scope=' . $scope . ($scope === 'single' ? '&note_id=' . (int) $items[0]['id'] : ''));
    }

    if ($total > 0) {
        set_flash('success', 'Payment successful. Notes added to your library.');
    } else {
        set_flash('success', 'Free notes added to your library.');
    }
    redirect('library.php');
}

$items = [];
if ($scope === 'single') {
    $noteId = (int) ($_REQUEST['note_id'] ?? 0);
    $note = load_single_note($pdo, $userId, $noteId);
    if (!$note) {
        set_flash('danger', 'Selected note not found.');
        redirect('notes.php');
    }
    if ((int) $note['owned'] === 1) {
        set_flash('info', 'You already own this note.');
        redirect('library.php');
    }
    $items[] = $note;
} else {
    $items = load_cart_items($pdo, $userId);
    if (!$items) {
        set_flash('warning', 'Your cart is empty.');
        redirect('cart.php');
    }
}

$total = 0.0;
foreach ($items as $item) {
    $total += (float) $item['price'];
}

$pageTitle = 'NotesPro | Payment';
$activePage = 'cart';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar_start.php';
?>
<header class="topbar mb-4">
  <h1 class="h3 fw-bold mb-1">Payment</h1>
  <p class="text-secondary mb-0">Review order and complete payment securely.</p>
</header>

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <section class="card border-0 shadow-sm mb-3">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3">Order Summary</h2>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr><th>Title</th><th>Subject</th><th>Price</th></tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= e($item['title']) ?></td>
                  <td><?= e($item['subject'] ?: 'General') ?></td>
                  <td><?= ((float)$item['price'] > 0) ? '$' . number_format((float)$item['price'], 2) : 'Free' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>

  <div class="col-12 col-lg-4">
    <section class="card border-0 shadow-sm mb-3">
      <div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3">Pay Now</h2>
        <form method="post" action="payment.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="pay">
          <input type="hidden" name="scope" value="<?= e($scope) ?>">
          <?php if ($scope === 'single'): ?>
            <input type="hidden" name="note_id" value="<?= (int)$items[0]['id'] ?>">
          <?php endif; ?>

          <?php if ($total > 0): ?>
            <div class="mb-2 form-check">
              <input class="form-check-input" type="radio" name="payment_method" id="pmCard" value="card" checked>
              <label class="form-check-label" for="pmCard">Card</label>
            </div>
            <div class="mb-2 form-check">
              <input class="form-check-input" type="radio" name="payment_method" id="pmUpi" value="upi">
              <label class="form-check-label" for="pmUpi">UPI</label>
            </div>
            <div class="mb-2 form-check">
              <input class="form-check-input" type="radio" name="payment_method" id="pmNet" value="netbanking">
              <label class="form-check-label" for="pmNet">Net Banking</label>
            </div>
            <div class="mb-3 form-check">
              <input class="form-check-input" type="radio" name="payment_method" id="pmWallet" value="wallet">
              <label class="form-check-label" for="pmWallet">Wallet</label>
            </div>
          <?php else: ?>
            <p class="text-secondary">No payment required for free notes.</p>
          <?php endif; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
            <strong>Total</strong>
            <strong>$<?= number_format($total, 2) ?></strong>
          </div>
          <button class="btn btn-brand w-100" type="submit"><?= $total > 0 ? 'Complete Payment' : 'Confirm Access' ?></button>
        </form>
      </div>
    </section>
  </div>
</div>
<?php
require __DIR__ . '/includes/sidebar_end.php';
require __DIR__ . '/includes/footer.php';
