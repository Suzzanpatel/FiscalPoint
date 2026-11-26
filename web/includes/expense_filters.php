<?php
/**
 * Shared filter handling for the "All Expenses" report and its Excel export,
 * so the download always contains exactly the rows shown on screen.
 */

/** Normalise raw request input into a safe filter array. */
function expense_filters(array $input): array
{
    $month = (string) ($input['month'] ?? '');
    $order = static fn($v) => in_array($v, ['ASC', 'DESC'], true) ? $v : '';
    $payment = (string) ($input['payment_type'] ?? '');
    $allowedPayments = array_merge(PAYMENT_METHODS, ['Other', '']);

    return [
        'month'        => valid_month($month) ? $month : date('Y-m'),
        'date_order'   => $order($input['date_order'] ?? ''),
        'amount_order' => $order($input['amount_order'] ?? ''),
        'payment_type' => in_array($payment, $allowedPayments, true) ? $payment : '',
    ];
}

/** Build the parameterised SELECT for the given filters. Returns [sql, types, params]. */
function expense_filter_query(int $uid, array $filters): array
{
    $sql = "SELECT Eid, category, description AS Item, amount AS Cost, date AS Date, Payment_Method
            FROM Expense WHERE Uid = ? AND DATE_FORMAT(date, '%Y-%m') = ?";
    $types = 'is';
    $params = [$uid, $filters['month']];

    if ($filters['payment_type'] === 'Other') {
        // "Other" = any custom payment method typed by the user.
        $placeholders = implode(', ', array_fill(0, count(PAYMENT_METHODS), '?'));
        $sql .= " AND Payment_Method NOT IN ($placeholders)";
        $types .= str_repeat('s', count(PAYMENT_METHODS));
        array_push($params, ...PAYMENT_METHODS);
    } elseif ($filters['payment_type'] !== '') {
        $sql .= ' AND Payment_Method = ?';
        $types .= 's';
        $params[] = $filters['payment_type'];
    }

    $order = [];
    if ($filters['date_order'] !== '') {
        $order[] = 'date ' . $filters['date_order'];
    }
    if ($filters['amount_order'] !== '') {
        $order[] = 'amount ' . $filters['amount_order'];
    }
    $sql .= ' ORDER BY ' . ($order ? implode(', ', $order) : 'date ASC');

    return [$sql, $types, $params];
}
