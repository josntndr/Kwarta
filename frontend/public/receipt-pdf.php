<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

$userId = current_user_id();
$selectedMonth = $_GET['month'] ?? current_month();

if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = current_month();
}

$stmt = $pdo->prepare('
    INSERT INTO monthly_receipt_logs (user_id, selected_month)
    VALUES (:user_id, :selected_month)
');
$stmt->execute(['user_id' => $userId, 'selected_month' => $selectedMonth]);

$monthLabel = month_label($selectedMonth);
$generatedAt = date('F j, Y - g:i A');
$userName = current_user_name();

$stmt = $pdo->prepare('
    SELECT t.*, c.name AS category_name
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
      AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month
      AND t.type = "expense"
    ORDER BY t.transaction_date ASC, t.id ASC
');
$stmt->execute(['user_id' => $userId, 'month' => $selectedMonth]);
$expenseRows = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT
        COALESCE(SUM(CASE WHEN t.type = "income" THEN t.amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN t.type = "expense" THEN t.amount ELSE 0 END), 0) AS expenses,
        COUNT(CASE WHEN t.type = "expense" THEN 1 END) AS expense_count
    FROM transactions t
    WHERE t.user_id = :user_id
      AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month
');
$stmt->execute(['user_id' => $userId, 'month' => $selectedMonth]);
$summary = $stmt->fetch();

$totalIncome = (float) $summary['income'];
$totalExpenses = (float) $summary['expenses'];
$remainingBalance = $totalIncome - $totalExpenses;
$expenseCount = (int) $summary['expense_count'];

$stmt = $pdo->prepare('
    SELECT c.name, COALESCE(SUM(t.amount), 0) AS total
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
      AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month
      AND t.type = "expense"
    GROUP BY c.id, c.name
    ORDER BY total DESC
    LIMIT 1
');
$stmt->execute(['user_id' => $userId, 'month' => $selectedMonth]);
$topCategory = $stmt->fetch();
$highestCategory = $topCategory ? $topCategory['name'] . ' (' . peso((float) $topCategory['total']) . ')' : 'No expenses yet';

$stmt = $pdo->prepare('
    SELECT COALESCE(SUM(t.amount), 0)
    FROM transactions t
    JOIN categories c ON c.id = t.category_id
    WHERE t.user_id = :user_id
      AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month
      AND t.type = "expense"
      AND LOWER(c.name) = "savings"
');
$stmt->execute(['user_id' => $userId, 'month' => $selectedMonth]);
$totalSavings = (float) $stmt->fetchColumn();

function receipt_pdf_escape(string $text): string
{
    $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean ?: '');
}

function receipt_pdf_wrap(string $text, int $length = 78): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') {
        return [''];
    }

    return explode("\n", wordwrap($text, $length, "\n", true));
}

function receipt_pdf_text(float $x, float $y, string $text, int $size = 10): string
{
    return "BT /F1 {$size} Tf {$x} {$y} Td (" . receipt_pdf_escape($text) . ") Tj ET\n";
}

function receipt_pdf_document(array $lines): string
{
    $pages = [];
    $current = [];
    $y = 760;

    foreach ($lines as $line) {
        $text = (string) ($line['text'] ?? '');
        $size = (int) ($line['size'] ?? 10);
        $x = (float) ($line['x'] ?? 48);
        $space = (int) ($line['space'] ?? 16);

        foreach (receipt_pdf_wrap($text, (int) ($line['wrap'] ?? 78)) as $wrapped) {
            if ($y < 52) {
                $pages[] = implode('', $current);
                $current = [];
                $y = 760;
            }

            $current[] = receipt_pdf_text($x, $y, $wrapped, $size);
            $y -= $space;
        }
    }

    $pages[] = implode('', $current);

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
    ];

    $kids = [];
    foreach ($pages as $index => $content) {
        $pageId = 4 + ($index * 2);
        $contentId = $pageId + 1;
        $kids[] = "{$pageId} 0 R";
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
        $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream";
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach (array_keys($objects) as $id) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    return $pdf;
}

$lines = [
    ['text' => 'KWARTA', 'size' => 20, 'space' => 24],
    ['text' => 'Monthly Expense Summary', 'size' => 14, 'space' => 22],
    ['text' => str_repeat('-', 72), 'space' => 14],
    ['text' => 'Month: ' . $monthLabel],
    ['text' => 'Generated: ' . $generatedAt],
    ['text' => 'User: ' . $userName],
    ['text' => 'Transactions: ' . $expenseCount],
    ['text' => str_repeat('-', 72), 'space' => 18],
    ['text' => 'EXPENSES', 'size' => 12, 'space' => 18],
];

if (!$expenseRows) {
    $lines[] = ['text' => 'No expenses recorded for ' . $monthLabel . '.'];
} else {
    foreach ($expenseRows as $index => $row) {
        $date = date('F j, Y', strtotime($row['transaction_date']));
        $notes = $row['notes'] ?: 'No notes';
        $lines[] = [
            'text' => ($index + 1) . '. ' . $row['category_name'] . ' - ' . peso((float) $row['amount']) . ' - ' . $notes . ' - ' . $date,
            'wrap' => 74,
        ];
    }
}

$lines = array_merge($lines, [
    ['text' => str_repeat('-', 72), 'space' => 18],
    ['text' => 'Total Expenses: ' . peso($totalExpenses), 'size' => 12],
    ['text' => 'Total Income: ' . peso($totalIncome), 'size' => 12],
    ['text' => 'Remaining Balance: ' . peso($remainingBalance), 'size' => 12],
    ['text' => 'Total Savings: ' . peso($totalSavings), 'size' => 12],
    ['text' => 'Highest Spending Category: ' . $highestCategory, 'size' => 12, 'wrap' => 70],
    ['text' => str_repeat('-', 72), 'space' => 18],
    ['text' => 'Keep tracking, keep saving, and keep leveling up your Kwarta habits.', 'wrap' => 70],
]);

$filename = 'kwarta-monthly-receipt-' . $selectedMonth . '.pdf';
$pdf = receipt_pdf_document($lines);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
