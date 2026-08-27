<?php
// wallet.php

require_once __DIR__ . '/common/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   LOGIN CHECK
========================= */

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$message = "";
$message_type = "success";


/* =========================
   GET USER
========================= */

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$user_id]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found.");
}


/* =========================
   SETTINGS
========================= */

$settings = [];

try {

    $stmt = $pdo->query("SELECT * FROM settings");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

} catch (PDOException $e) {

    $settings = [];
}


/* =========================
   DEPOSIT REQUEST
========================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['deposit_request'])
) {

    $amount = (float)($_POST['amount'] ?? 0);
    $transaction_id = trim($_POST['transaction_id'] ?? '');

    if ($amount <= 0) {

        $message = "Please enter a valid amount.";
        $message_type = "danger";

    } elseif ($transaction_id === '') {

        $message = "Please enter UPI transaction ID.";
        $message_type = "danger";

    } else {

        try {

            $stmt = $pdo->prepare("
                INSERT INTO deposits
                (user_id, amount, transaction_id, status)
                VALUES (?, ?, ?, 'Pending')
            ");

            $stmt->execute([
                $user_id,
                $amount,
                $transaction_id
            ]);

            $message = "Deposit request submitted successfully.";
            $message_type = "success";

        } catch (PDOException $e) {

            $message = "Deposit request failed.";
            $message_type = "danger";
        }
    }
}


/* =========================
   WITHDRAW REQUEST
========================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['withdraw_request'])
) {

    $amount = (float)($_POST['withdraw_amount'] ?? 0);

    $current_balance = (float)$user['wallet_balance'];

    if ($amount <= 0) {

        $message = "Please enter a valid withdrawal amount.";
        $message_type = "danger";

    } elseif ($amount > $current_balance) {

        $message = "Insufficient wallet balance.";
        $message_type = "danger";

    } elseif (empty($user['upi_id'])) {

        $message = "Please update your UPI ID in your profile.";
        $message_type = "danger";

    } else {

        try {

            $pdo->beginTransaction();

            /* Deduct balance */

            $stmt = $pdo->prepare("
                UPDATE users
                SET wallet_balance = wallet_balance - ?
                WHERE id = ?
                AND wallet_balance >= ?
            ");

            $stmt->execute([
                $amount,
                $user_id,
                $amount
            ]);

            if ($stmt->rowCount() !== 1) {

                $pdo->rollBack();

                $message = "Insufficient wallet balance.";
                $message_type = "danger";

            } else {

                /* Create withdrawal request */

                $stmt = $pdo->prepare("
                    INSERT INTO withdrawals
                    (user_id, amount, status)
                    VALUES (?, ?, 'Pending')
                ");

                $stmt->execute([
                    $user_id,
                    $amount
                ]);

                $pdo->commit();

                $message = "Withdrawal request submitted.";
                $message_type = "success";

                /* Refresh user */

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->execute([$user_id]);

                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $message = "Withdrawal failed. Please try again.";
            $message_type = "danger";
        }
    }
}


/* =========================
   CURRENT BALANCE
========================= */

$balance = (float)$user['wallet_balance'];


/* =========================
   DEPOSIT HISTORY
========================= */

$deposit_history = [];

try {

    $stmt = $pdo->prepare("
        SELECT amount, transaction_id, status, created_at
        FROM deposits
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 30
    ");

    $stmt->execute([$user_id]);

    $deposit_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $deposit_history = [];
}


/* =========================
   WITHDRAW HISTORY
========================= */

$withdraw_history = [];

try {

    $stmt = $pdo->prepare("
        SELECT amount, status, created_at
        FROM withdrawals
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 30
    ");

    $stmt->execute([$user_id]);

    $withdraw_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $withdraw_history = [];
}


/* =========================
   COMBINE HISTORY
========================= */

$history = [];


/* Deposits */

foreach ($deposit_history as $row) {

    $history[] = [
        'type' => 'CREDIT',
        'title' => 'Added By UPI',
        'amount' => (float)$row['amount'],
        'status' => $row['status'],
        'date' => $row['created_at'] ?? ''
    ];
}


/* Withdrawals */

foreach ($withdraw_history as $row) {

    $history[] = [
        'type' => 'DEBIT',
        'title' => 'Withdrawal',
        'amount' => (float)$row['amount'],
        'status' => $row['status'],
        'date' => $row['created_at'] ?? ''
    ];
}


/* =========================
   SORT HISTORY
========================= */

usort($history, function ($a, $b) {

    return strtotime($b['date']) <=> strtotime($a['date']);

});


/* =========================
   TOTALS
========================= */

$total_deposits = 0;
$total_withdrawals = 0;


/* Deposit totals */

foreach ($deposit_history as $row) {

    $status = strtolower($row['status'] ?? '');

    if (
        $status === 'approved'
        || $status === 'success'
        || $status === 'completed'
    ) {

        $total_deposits += (float)$row['amount'];
    }
}


/* Withdrawal totals */

foreach ($withdraw_history as $row) {

    $status = strtolower($row['status'] ?? '');

    if (
        $status === 'approved'
        || $status === 'success'
        || $status === 'completed'
    ) {

        $total_withdrawals += (float)$row['amount'];
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>My Wallet</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>

/* =========================
   RESET
========================= */

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    background: #090014;
    color: #ffffff;
    font-family: Arial, sans-serif;
}


/* =========================
   PAGE
========================= */

.wallet-page {
    min-height: 100vh;
    padding: 20px 15px 50px;
}


/* =========================
   HEADER
========================= */

.wallet-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 35px;
}

.back-btn {
    color: #ffffff;
    text-decoration: none;
    font-size: 42px;
    line-height: 1;
}

.wallet-header h1 {
    margin: 0;
    font-size: 34px;
    font-weight: 700;
}


/* =========================
   ALERT
========================= */

.wallet-page .alert {
    border-radius: 10px;
}


/* =========================
   BALANCE TITLE
========================= */

.balance-title {
    text-align: center;
    font-size: 30px;
    font-weight: 700;
    margin-bottom: 18px;
}


/* =========================
   BALANCE CARD
========================= */

.balance-card {
    background: linear-gradient(
        135deg,
        #210044,
        #31005e
    );

    border-radius: 12px;

    padding: 25px;

    margin-bottom: 25px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 25px;

    box-shadow:
        0 0 20px rgba(128,0,255,0.15);
}

.balance-left {
    flex: 1;
}


/* =========================
   TOTAL AMOUNT
========================= */

.total-amount {
    font-size: 38px;
    font-weight: 700;
    margin-bottom: 15px;
}


/* =========================
   COIN
========================= */

.coin {
    color: #ffd21c;
}


/* =========================
   MONEY ROW
========================= */

.money-row {
    font-size: 22px;
    margin: 8px 0;
}


/* =========================
   ACTION BUTTONS
========================= */

.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 15px;
    width: 230px;
}

.action-btn {
    border: 0;

    border-radius: 12px;

    padding: 15px;

    font-size: 25px;

    font-weight: 700;

    color: #ffffff;

    background: linear-gradient(
        135deg,
        #5b00ff,
        #8000ff
    );

    cursor: pointer;

    transition: 0.2s;
}

.action-btn:hover {
    opacity: 0.9;
}

.action-btn:active {
    transform: scale(0.97);
}


/* =========================
   STATS
========================= */

.stats {
    display: grid;

    grid-template-columns: 1fr 1fr;

    gap: 15px;

    margin-bottom: 25px;
}

.stat-title {
    text-align: center;

    font-size: 27px;

    font-weight: 700;

    margin-bottom: 10px;
}

.stat-card {
    background: #210044;

    padding: 18px;

    border-radius: 8px;

    text-align: center;

    font-size: 25px;
}


/* =========================
   HISTORY TITLE
========================= */

.history-title {
    text-align: center;

    font-size: 30px;

    font-weight: 700;

    margin: 20px 0;
}


/* =========================
   HISTORY CARD
========================= */

.history-card {
    background: #210044;

    border-radius: 12px;

    padding: 18px;

    margin-bottom: 15px;

    display: flex;

    align-items: center;

    gap: 15px;
}

.history-type {
    width: 95px;

    flex-shrink: 0;

    font-size: 19px;

    font-weight: 700;
}


/* =========================
   CREDIT / DEBIT
========================= */

.credit {
    color: #25e86d;
}

.debit {
    color: #ff4141;
}


/* =========================
   HISTORY DETAILS
========================= */

.history-details {
    flex: 1;

    min-width: 0;
}

.history-name {
    color: #ff9638;

    font-size: 20px;

    font-weight: 600;
}

.history-date {
    font-size: 16px;

    margin-top: 8px;

    color: #eeeeee;
}


/* =========================
   HISTORY AMOUNT
========================= */

.history-amount {
    flex-shrink: 0;

    font-size: 22px;

    font-weight: 700;

    text-align: right;
}


/* =========================
   STATUS
========================= */

.pending {
    color: #ffd21c;

    font-size: 14px;
}

.success {
    color: #25e86d;

    font-size: 14px;
}


/* =========================
   NO HISTORY
========================= */

.no-history {
    background: #210044;

    padding: 25px;

    text-align: center;

    border-radius: 12px;

    color: #aaaaaa;
}


/* =========================
   MODAL
========================= */

.modal-content {
    background: #050505 !important;

    color: #ffffff !important;

    border: 1px solid rgba(255,255,255,0.7);

    border-radius: 14px;
}

.modal-header,
.modal-footer {
    border-color: rgba(255,255,255,0.2);
}

.modal-title,
.modal-body,
.form-label {
    color: #ffffff !important;
}

.form-control {
    background: #000000 !important;

    color: #ffffff !important;

    border: 1px solid rgba(255,255,255,0.55) !important;

    border-radius: 9px !important;
}

.form-control::placeholder {
    color: #777777 !important;
}

.form-control:focus {
    background: #000000 !important;

    color: #ffffff !important;

    border-color: #ffffff !important;

    box-shadow:
        0 0 5px rgba(255,255,255,0.7) !important;
}

.btn-close {
    filter: invert(1);
}

.btn-primary {
    background: #050505 !important;

    border: 1px solid #ffffff !important;

    color: #ffffff !important;
}

.btn-danger {
    background: #050505 !important;

    border: 1px solid #ff4141 !important;

    color: #ffffff !important;
}

.alert-info,
.alert-success,
.alert-warning {
    background: #111111 !important;

    color: #ffffff !important;

    border: 1px solid #777777 !important;
}


/* =========================
   MOBILE
========================= */

@media (max-width: 600px) {

    .wallet-page {
        padding: 18px 12px 45px;
    }


    .wallet-header {
        gap: 12px;

        margin-bottom: 35px;
    }


    .back-btn {
        font-size: 40px;
    }


    .wallet-header h1 {
        font-size: 28px;
    }


    .balance-title {
        font-size: 25px;
    }


    .balance-card {
        padding: 18px;

        flex-direction: column;

        align-items: stretch;

        gap: 20px;
    }


    .total-amount {
        font-size: 34px;
    }


    .money-row {
        font-size: 19px;
    }


    .action-buttons {
        width: 100%;

        flex-direction: row;

        gap: 10px;
    }


    .action-btn {
        flex: 1;

        font-size: 20px;

        padding: 13px 8px;
    }


    .stats {
        gap: 10px;
    }


    .stat-title {
        font-size: 21px;
    }


    .stat-card {
        font-size: 20px;

        padding: 15px 5px;
    }


    .history-title {
        font-size: 27px;
    }


    .history-card {
        padding: 14px 10px;

        gap: 8px;
    }


    .history-type {
        width: 60px;

        font-size: 13px;
    }


    .history-name {
        font-size: 15px;
    }


    .history-date {
        font-size: 11px;
    }


    .history-amount {
        font-size: 15px;
    }


    .pending,
    .success {
        font-size: 11px;
    }

}


/* =========================
   VERY SMALL MOBILE
========================= */

@media (max-width: 380px) {

    .wallet-page {
        padding-left: 9px;
        padding-right: 9px;
    }


    .total-amount {
        font-size: 30px;
    }


    .money-row {
        font-size: 17px;
    }


    .action-btn {
        font-size: 18px;
    }


    .history-type {
        width: 55px;

        font-size: 12px;
    }


    .history-name {
        font-size: 14px;
    }


    .history-amount {
        font-size: 14px;
    }

}

</style>

</head>


<body>

<div class="wallet-page">


<!-- =========================
     HEADER
========================= -->

<div class="wallet-header">

    <a
        href="index.php"
        class="back-btn"
    >
        ‹
    </a>

    <h1>
        My Wallet
    </h1>

</div>


<!-- =========================
     MESSAGE
========================= -->

<?php if (!empty($message)): ?>

<div class="alert alert-<?= htmlspecialchars($message_type) ?>">

    <?= htmlspecialchars($message) ?>

</div>

<?php endif; ?>


<!-- =========================
     TOTAL BALANCE
========================= -->

<div class="balance-title">
    TOTAL BALANCE
</div>


<!-- =========================
     BALANCE CARD
========================= -->

<div class="balance-card">

    <div class="balance-left">

        <div class="total-amount">

            🪙 ₹<?= number_format($balance, 2) ?>

        </div>


        <div class="money-row">

            Win money :

            <span class="coin">
                🪙
            </span>

            ₹0.00

        </div>


        <div class="money-row">

            Join money :

            <span class="coin">
                🪙
            </span>

            ₹<?= number_format($balance, 2) ?>

        </div>

    </div>


    <div class="action-buttons">

        <button
            type="button"
            class="action-btn"
            data-bs-toggle="modal"
            data-bs-target="#depositModal"
        >
            ADD
        </button>


        <button
            type="button"
            class="action-btn"
            data-bs-toggle="modal"
            data-bs-target="#withdrawModal"
        >
            WITHDRAW
        </button>

    </div>

</div>


<!-- =========================
     STATS
========================= -->

<div class="stats">

    <div>

        <div class="stat-title">
            Earnings
        </div>

        <div class="stat-card">

            🪙 ₹<?= number_format(
                $total_deposits,
                2
            ) ?>

        </div>

    </div>


    <div>

        <div class="stat-title">
            PAYOUTS
        </div>

        <div class="stat-card">

            🪙 ₹<?= number_format(
                $total_withdrawals,
                2
            ) ?>

        </div>

    </div>

</div>


<!-- =========================
     WALLET HISTORY
========================= -->

<div class="history-title">
    WALLET HISTORY
</div>


<?php if (!empty($history)): ?>


    <?php foreach ($history as $item): ?>


        <div class="history-card">


            <!-- TYPE -->

            <div
                class="history-type <?= $item['type'] === 'CREDIT'
                    ? 'credit'
                    : 'debit' ?>"
            >

                <?= htmlspecialchars(
                    $item['type']
                ) ?>

            </div>


            <!-- DETAILS -->

            <div class="history-details">


                <div class="history-name">

                    <?= htmlspecialchars(
                        $item['title']
                    ) ?>

                </div>


                <div class="history-date">

                    <?php if (!empty($item['date'])): ?>

                        <?= htmlspecialchars(
                            date(
                                'Y-m-d H:i:s',
                                strtotime($item['date'])
                            )
                        ) ?>

                    <?php endif; ?>

                </div>


                <div>

                    <?php

                    $status = strtolower(
                        $item['status'] ?? ''
                    );

                    if (
                        $status === 'approved'
                        || $status === 'success'
                        || $status === 'completed'
                    ):

                    ?>

                        <span class="success">

                            <?= htmlspecialchars(
                                $item['status']
                            ) ?>

                        </span>

                    <?php else: ?>

                        <span class="pending">

                            <?= htmlspecialchars(
                                $item['status']
                            ) ?>

                        </span>

                    <?php endif; ?>

                </div>


            </div>


            <!-- AMOUNT -->

            <div class="history-amount">


                <?php if ($item['type'] === 'CREDIT'): ?>


                    <span class="credit">

                        +
                        🪙
                        ₹<?= number_format(
                            $item['amount'],
                            2
                        ) ?>

                    </span>


                <?php else: ?>


                    <span class="debit">

                        -
                        🪙
                        ₹<?= number_format(
                            $item['amount'],
                            2
                        ) ?>

                    </span>


                <?php endif; ?>


            </div>


        </div>


    <?php endforeach; ?>


<?php else: ?>


    <div class="no-history">

        No wallet transactions yet.

    </div>


<?php endif; ?>


</div>


<!-- =========================
     DEPOSIT MODAL
========================= -->

<div
    class="modal fade"
    id="depositModal"
    tabindex="-1"
    aria-hidden="true"
>


    <div class="modal-dialog">


        <div class="modal-content">


            <!-- HEADER -->

            <div class="modal-header">


                <h5 class="modal-title">

                    Add Money

                </h5>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>


            </div>


            <!-- FORM -->

            <form method="POST">


                <div class="modal-body">


                    <!-- ADMIN UPI -->

                    <?php if (
                        !empty(
                            $settings['admin_upi_id']
                        )
                    ): ?>


                        <div class="alert alert-info">


                            UPI ID:

                            <strong>

                                <?= htmlspecialchars(
                                    $settings['admin_upi_id']
                                ) ?>

                            </strong>


                        </div>


                    <?php endif; ?>


                    <!-- QR CODE -->

                    <?php if (
                        !empty(
                            $settings['admin_qr_code']
                        )
                    ): ?>


                        <div class="text-center mb-3">


                            <img
                                src="<?= htmlspecialchars(
                                    $settings['admin_qr_code']
                                ) ?>"
                                class="img-fluid"
                                style="max-width:220px;"
                                alt="UPI QR Code"
                            >


                        </div>


                    <?php endif; ?>


                    <!-- AMOUNT -->

                    <label class="form-label">

                        Amount

                    </label>


                    <input
                        type="number"
                        name="amount"
                        step="0.01"
                        min="1"
                        class="form-control mb-3"
                        placeholder="Enter amount"
                        required
                    >


                    <!-- TRANSACTION ID -->

                    <label class="form-label">

                        UPI Transaction ID

                    </label>


                    <input
                        type="text"
                        name="transaction_id"
                        class="form-control"
                        placeholder="Enter transaction ID"
                        required
                    >


                </div>


                <!-- FOOTER -->

                <div class="modal-footer">


                    <button
                        type="submit"
                        name="deposit_request"
                        class="btn btn-primary w-100"
                    >

                        Submit Deposit Request

                    </button>


                </div>


            </form>


        </div>

    </div>

</div>


<!-- =========================
     WITHDRAW MODAL
========================= -->

<div
    class="modal fade"
    id="withdrawModal"
    tabindex="-1"
    aria-hidden="true"
>


    <div class="modal-dialog">


        <div class="modal-content">


            <!-- HEADER -->

            <div class="modal-header">


                <h5 class="modal-title">

                    Withdraw Money

                </h5>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>


            </div>


            <!-- FORM -->

            <form method="POST">


                <div class="modal-body">


                    <!-- UPI -->

                    <?php if (
                        !empty(
                            $user['upi_id']
                        )
                    ): ?>


                        <div class="alert alert-success">


                            Your UPI ID:

                            <strong>

                                <?= htmlspecialchars(
                                    $user['upi_id']
                                ) ?>

                            </strong>


                        </div>


                    <?php else: ?>


                        <div class="alert alert-warning">

                            Please update your UPI ID in Profile.

                        </div>


                    <?php endif; ?>


                    <!-- AMOUNT -->

                    <label class="form-label">

                        Amount to Withdraw

                    </label>


                    <input
                        type="number"
                        name="withdraw_amount"
                        step="0.01"
                        min="1"
                        max="<?= htmlspecialchars(
                            $balance
                        ) ?>"
                        class="form-control"
                        placeholder="Enter amount"
                        required
                    >


                </div>


                <!-- FOOTER -->

                <div class="modal-footer">


                    <button
                        type="submit"
                        name="withdraw_request"
                        class="btn btn-danger w-100"
                        <?= empty($user['upi_id'])
                            ? 'disabled'
                            : '' ?>
                    >

                        Request Withdrawal

                    </button>


                </div>


            </form>


        </div>

    </div>

</div>


<!-- =========================
     BOOTSTRAP JS
========================= -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>


</body>

</html>
