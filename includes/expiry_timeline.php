<?php

require_once __DIR__ . '/currency_rates.php';

const EXPIRY_TIMELINE_DAYS = 30;

/**
 * Project every renewal of the user's active subscriptions over the next days
 * and group them by date, for the dashboard timeline.
 *
 * Recurring subscriptions are expanded to each occurrence inside the window, so
 * a weekly plan shows up four or five times. Occurrences are counted from the
 * stored next payment date (n × cycle, never chained, so a renewal on the 31st
 * does not drift), skip anything before today and stop at the cancellation date.
 * One-time purchases have nothing to renew and are left out, like the upcoming
 * payments list. Manual renewals already past due are shown by the overdue
 * section instead of being projected forward.
 *
 * @param SQLite3 $db
 * @param int     $userId
 * @param int     $days
 * @return array{days: array<string, array{date: string, offset: int, total: float, items: array}>, total: float, count: int}
 */
function get_expiry_timeline($db, $userId, $days = EXPIRY_TIMELINE_DAYS)
{
    $today = new DateTimeImmutable('today');
    $end = $today->modify('+' . (int) $days . ' days');

    $stmt = $db->prepare("SELECT id, logo, logo_text_color, logo_variant, name, price, currency_id,
            next_payment, cycle, frequency, auto_renew, cancellation_date
        FROM subscriptions
        WHERE user_id = :userId
          AND inactive = 0
          AND cycle != 5
          AND next_payment IS NOT NULL
          AND next_payment != ''
          AND next_payment <= :end
        ORDER BY next_payment ASC, name COLLATE NOCASE ASC");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':end', $end->format('Y-m-d'), SQLITE3_TEXT);
    $result = $stmt->execute();

    $units = [1 => 'days', 2 => 'weeks', 3 => 'months', 4 => 'years'];
    $timeline = [];
    $total = 0.0;
    $count = 0;

    while ($result && ($subscription = $result->fetchArray(SQLITE3_ASSOC))) {
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', substr($subscription['next_payment'], 0, 10));
        if ($base === false) {
            continue;
        }
        if ($base < $today && !$subscription['auto_renew']) {
            continue;
        }

        $unit = $units[(int) $subscription['cycle']] ?? 'months';
        $frequency = max(1, (int) $subscription['frequency']);
        $cancellation = !empty($subscription['cancellation_date'])
            ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($subscription['cancellation_date'], 0, 10))
            : false;
        $convertedPrice = wallos_convert_price($subscription['price'], $subscription['currency_id'], $db, $userId);

        for ($n = 0; ; $n++) {
            $date = $n === 0 ? $base : $base->modify('+' . ($n * $frequency) . ' ' . $unit);
            if ($date > $end || ($cancellation && $date > $cancellation)) {
                break;
            }
            if ($date < $today) {
                continue;
            }

            $key = $date->format('Y-m-d');
            if (!isset($timeline[$key])) {
                $timeline[$key] = [
                    'date' => $key,
                    'offset' => (int) $today->diff($date)->days,
                    'total' => 0.0,
                    'items' => [],
                ];
            }
            $timeline[$key]['items'][] = $subscription;
            $timeline[$key]['total'] += $convertedPrice;
            $total += $convertedPrice;
            $count++;
        }
    }

    ksort($timeline);

    return ['days' => $timeline, 'total' => $total, 'count' => $count];
}
