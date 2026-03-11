<?php

function svh_calendar_blocking_booking_statuses(): array
{
    return ['waiting', 'confirmed'];
}

function svh_calendar_blocking_event_types(): array
{
    return ['manual_block', 'maintenance'];
}

function svh_normalize_room_code(string $value): ?string
{
    $raw = mb_strtolower(trim($value));
    if (preg_match('/^room-(?:[1-9]|1[0-9]|20)$/', $raw) === 1) {
        return $raw;
    }

    if (preg_match('/^(?:[1-9]|1[0-9]|20)$/', $raw) === 1) {
        return 'room-' . $raw;
    }

    return null;
}

function svh_room_code_sort_value(string $roomCode): int
{
    if (preg_match('/^room-(\d{1,2})$/', trim($roomCode), $match) !== 1) {
        return 999;
    }

    return (int) ($match[1] ?? 999);
}

function svh_is_iso_date(string $value): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function svh_date_add_days(string $date, int $days): string
{
    $base = new DateTimeImmutable($date);
    if ($days === 0) {
        return $base->format('Y-m-d');
    }

    $modifier = ($days > 0 ? '+' : '') . $days . ' day';
    return $base->modify($modifier)->format('Y-m-d');
}

function svh_date_range_days(string $fromDate, int $days): array
{
    $days = max(1, min(62, $days));
    $result = [];
    for ($offset = 0; $offset < $days; $offset++) {
        $result[] = svh_date_add_days($fromDate, $offset);
    }

    return $result;
}

function svh_room_catalog(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $rooms = [];
    for ($roomId = 1; $roomId <= 20; $roomId++) {
        $room = svh_read_room_json($roomId);
        $roomCode = 'room-' . $roomId;
        $rooms[$roomCode] = [
            'id' => $roomId,
            'room_code' => $roomCode,
            'title' => trim((string) ($room['title'] ?? '')) !== '' ? trim((string) $room['title']) : 'Номер ' . $roomId,
            'capacity' => max(1, (int) ($room['capacity'] ?? $room['guests'] ?? 2)),
            'type' => trim((string) ($room['type'] ?? 'standard')) ?: 'standard',
        ];
    }

    uasort($rooms, static function (array $left, array $right): int {
        return ($left['id'] ?? 999) <=> ($right['id'] ?? 999);
    });

    $cache = $rooms;
    return $cache;
}

function svh_calendar_status_priority(string $status): int
{
    static $map = [
        'free' => 0,
        'waiting' => 1,
        'confirmed' => 2,
        'blocked' => 3,
    ];

    return $map[$status] ?? 0;
}

function svh_calendar_status_tone(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === 'blocked') {
        return 'danger';
    }
    if ($normalized === 'confirmed') {
        return 'success';
    }
    if ($normalized === 'waiting') {
        return 'warning';
    }

    return 'default';
}

function svh_active_booking_where_sql(): string
{
    return "status IN ('waiting','confirmed')";
}

function svh_active_calendar_event_where_sql(): string
{
    return "status = 'active' AND event_type IN ('manual_block','maintenance')";
}

function svh_sql_room_code_filter(string $column, array $roomCodes, array &$params, string $prefix = 'room'): string
{
    $normalized = [];
    foreach ($roomCodes as $roomCode) {
        $value = svh_normalize_room_code((string) $roomCode);
        if ($value !== null) {
            $normalized[] = $value;
        }
    }
    $normalized = array_values(array_unique($normalized));
    if (empty($normalized)) {
        return '';
    }

    $placeholders = [];
    foreach ($normalized as $index => $roomCode) {
        $name = ':' . $prefix . '_' . $index;
        $placeholders[] = $name;
        $params[$name] = $roomCode;
    }

    return " AND {$column} IN (" . implode(', ', $placeholders) . ')';
}

function svh_fetch_blocking_bookings_for_range(PDO $pdo, string $fromDate, string $toDate, array $roomCodes = []): array
{
    if (!svh_is_iso_date($fromDate) || !svh_is_iso_date($toDate) || $toDate <= $fromDate) {
        return [];
    }

    $params = [
        ':from_date' => $fromDate,
        ':to_date' => $toDate,
    ];
    $roomSql = svh_sql_room_code_filter('room_code', $roomCodes, $params, 'booking_room');

    $sql = "
        SELECT booking_id, name, guests, room_code, checkin_date, checkout_date, status
        FROM bookings
        WHERE honeypot_triggered = 0
          AND " . svh_active_booking_where_sql() . "
          AND checkin_date < :to_date
          AND checkout_date > :from_date
          {$roomSql}
        ORDER BY room_code ASC, checkin_date ASC, created_at ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function svh_fetch_calendar_events_for_range(PDO $pdo, string $fromDate, string $toDate, array $roomCodes = []): array
{
    if (!svh_is_iso_date($fromDate) || !svh_is_iso_date($toDate) || $toDate <= $fromDate) {
        return [];
    }

    $params = [
        ':from_date' => $fromDate,
        ':to_date' => $toDate,
    ];
    $roomSql = svh_sql_room_code_filter('room_code', $roomCodes, $params, 'event_room');

    $sql = "
        SELECT id, room_code, start_date, end_date, event_type, title, note, status
        FROM calendar_events
        WHERE " . svh_active_calendar_event_where_sql() . "
          AND start_date < :to_date
          AND end_date > :from_date
          {$roomSql}
        ORDER BY room_code ASC, start_date ASC, created_at ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function svh_find_room_conflicts(PDO $pdo, string $roomCode, string $checkinDate, string $checkoutDate, ?string $excludeBookingId = null): array
{
    $normalizedRoom = svh_normalize_room_code($roomCode);
    if ($normalizedRoom === null || !svh_is_iso_date($checkinDate) || !svh_is_iso_date($checkoutDate) || $checkoutDate <= $checkinDate) {
        return [
            'has_conflict' => false,
            'bookings' => [],
            'events' => [],
        ];
    }

    $params = [
        ':room_code' => $normalizedRoom,
        ':checkin_date' => $checkinDate,
        ':checkout_date' => $checkoutDate,
    ];
    $bookingExclusionSql = '';
    $bookingId = trim((string) ($excludeBookingId ?? ''));
    if ($bookingId !== '') {
        $bookingExclusionSql = ' AND booking_id <> :exclude_booking_id';
        $params[':exclude_booking_id'] = strtoupper($bookingId);
    }

    $bookingStmt = $pdo->prepare("
        SELECT booking_id, name, guests, room_code, checkin_date, checkout_date, status
        FROM bookings
        WHERE honeypot_triggered = 0
          AND room_code = :room_code
          AND " . svh_active_booking_where_sql() . "
          AND checkin_date < :checkout_date
          AND checkout_date > :checkin_date
          {$bookingExclusionSql}
        ORDER BY checkin_date ASC, created_at ASC
    ");
    $bookingStmt->execute($params);
    $bookings = $bookingStmt->fetchAll() ?: [];

    $eventStmt = $pdo->prepare("
        SELECT id, room_code, start_date, end_date, event_type, title, note, status
        FROM calendar_events
        WHERE room_code = :room_code
          AND " . svh_active_calendar_event_where_sql() . "
          AND start_date < :checkout_date
          AND end_date > :checkin_date
        ORDER BY start_date ASC, created_at ASC
    ");
    $eventStmt->execute([
        ':room_code' => $normalizedRoom,
        ':checkin_date' => $checkinDate,
        ':checkout_date' => $checkoutDate,
    ]);
    $events = $eventStmt->fetchAll() ?: [];

    return [
        'has_conflict' => !empty($bookings) || !empty($events),
        'bookings' => $bookings,
        'events' => $events,
    ];
}

function svh_room_range_availability(PDO $pdo, string $checkinDate, string $checkoutDate, ?string $roomFilter = null): array
{
    if (!svh_is_iso_date($checkinDate) || !svh_is_iso_date($checkoutDate) || $checkoutDate <= $checkinDate) {
        return [];
    }

    $rooms = svh_room_catalog();
    if ($roomFilter !== null && $roomFilter !== '') {
        $normalizedFilter = svh_normalize_room_code($roomFilter);
        if ($normalizedFilter === null || !isset($rooms[$normalizedFilter])) {
            return [];
        }
        $rooms = [$normalizedFilter => $rooms[$normalizedFilter]];
    }

    $roomCodes = array_keys($rooms);
    $bookings = svh_fetch_blocking_bookings_for_range($pdo, $checkinDate, $checkoutDate, $roomCodes);
    $events = svh_fetch_calendar_events_for_range($pdo, $checkinDate, $checkoutDate, $roomCodes);

    $result = [];
    foreach ($rooms as $roomCode => $room) {
        $result[$roomCode] = [
            'room' => $room,
            'status' => 'free',
            'bookings' => [],
            'events' => [],
        ];
    }

    foreach ($bookings as $booking) {
        $roomCode = (string) ($booking['room_code'] ?? '');
        if (!isset($result[$roomCode])) {
            continue;
        }

        $result[$roomCode]['bookings'][] = $booking;
        $bookingStatus = strtolower(trim((string) ($booking['status'] ?? '')));
        if (svh_calendar_status_priority($bookingStatus) > svh_calendar_status_priority((string) $result[$roomCode]['status'])) {
            $result[$roomCode]['status'] = $bookingStatus;
        }
    }

    foreach ($events as $event) {
        $roomCode = (string) ($event['room_code'] ?? '');
        if (!isset($result[$roomCode])) {
            continue;
        }

        $result[$roomCode]['events'][] = $event;
        $result[$roomCode]['status'] = 'blocked';
    }

    return $result;
}

function svh_calendar_build_matrix(PDO $pdo, string $fromDate, int $days = 7, ?string $roomFilter = null): array
{
    $days = max(1, min(31, $days));
    if (!svh_is_iso_date($fromDate)) {
        $fromDate = date('Y-m-d');
    }

    $dates = svh_date_range_days($fromDate, $days);
    $toDate = svh_date_add_days($fromDate, $days);
    $rooms = svh_room_catalog();
    if ($roomFilter !== null && $roomFilter !== '') {
        $normalizedFilter = svh_normalize_room_code($roomFilter);
        if ($normalizedFilter !== null && isset($rooms[$normalizedFilter])) {
            $rooms = [$normalizedFilter => $rooms[$normalizedFilter]];
        } else {
            $rooms = [];
        }
    }

    $roomCodes = array_keys($rooms);
    $bookings = svh_fetch_blocking_bookings_for_range($pdo, $fromDate, $toDate, $roomCodes);
    $events = svh_fetch_calendar_events_for_range($pdo, $fromDate, $toDate, $roomCodes);

    $matrix = [];
    foreach ($rooms as $roomCode => $room) {
        $matrix[$roomCode] = [
            'room' => $room,
            'days' => [],
        ];
        foreach ($dates as $date) {
            $matrix[$roomCode]['days'][$date] = [
                'status' => 'free',
                'items' => [],
            ];
        }
    }

    foreach ($bookings as $booking) {
        $roomCode = (string) ($booking['room_code'] ?? '');
        if (!isset($matrix[$roomCode])) {
            continue;
        }
        $bookingStatus = strtolower(trim((string) ($booking['status'] ?? '')));
        $start = max($fromDate, (string) ($booking['checkin_date'] ?? ''));
        $end = min($toDate, (string) ($booking['checkout_date'] ?? ''));
        if (!svh_is_iso_date($start) || !svh_is_iso_date($end) || $end <= $start) {
            continue;
        }

        $cursor = $start;
        while ($cursor < $end) {
            $cell = &$matrix[$roomCode]['days'][$cursor];
            $cell['items'][] = [
                'type' => 'booking',
                'status' => $bookingStatus,
                'booking_id' => (string) ($booking['booking_id'] ?? ''),
                'title' => trim((string) ($booking['name'] ?? '')) ?: 'Гість',
                'guests' => (int) ($booking['guests'] ?? 0),
                'checkin_date' => (string) ($booking['checkin_date'] ?? ''),
                'checkout_date' => (string) ($booking['checkout_date'] ?? ''),
            ];
            if (svh_calendar_status_priority($bookingStatus) > svh_calendar_status_priority((string) $cell['status'])) {
                $cell['status'] = $bookingStatus;
            }
            unset($cell);
            $cursor = svh_date_add_days($cursor, 1);
        }
    }

    foreach ($events as $event) {
        $roomCode = (string) ($event['room_code'] ?? '');
        if (!isset($matrix[$roomCode])) {
            continue;
        }

        $start = max($fromDate, (string) ($event['start_date'] ?? ''));
        $end = min($toDate, (string) ($event['end_date'] ?? ''));
        if (!svh_is_iso_date($start) || !svh_is_iso_date($end) || $end <= $start) {
            continue;
        }

        $cursor = $start;
        while ($cursor < $end) {
            $cell = &$matrix[$roomCode]['days'][$cursor];
            $cell['items'][] = [
                'type' => 'event',
                'status' => 'blocked',
                'event_id' => (int) ($event['id'] ?? 0),
                'event_type' => (string) ($event['event_type'] ?? 'manual_block'),
                'title' => trim((string) ($event['title'] ?? '')) ?: 'Блок',
                'note' => trim((string) ($event['note'] ?? '')),
                'start_date' => (string) ($event['start_date'] ?? ''),
                'end_date' => (string) ($event['end_date'] ?? ''),
            ];
            $cell['status'] = 'blocked';
            unset($cell);
            $cursor = svh_date_add_days($cursor, 1);
        }
    }

    return [
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'dates' => $dates,
        'rooms' => $rooms,
        'matrix' => $matrix,
    ];
}

function svh_day_booking_list(PDO $pdo, string $dateColumn, string $targetDate): array
{
    if (!in_array($dateColumn, ['checkin_date', 'checkout_date'], true) || !svh_is_iso_date($targetDate)) {
        return [];
    }

    $sql = "
        SELECT booking_id, name, phone, email, checkin_date, checkout_date, guests, room_code, status, created_at
        FROM bookings
        WHERE honeypot_triggered = 0
          AND " . svh_active_booking_where_sql() . "
          AND {$dateColumn} = :target_date
        ORDER BY room_code ASC, created_at ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':target_date' => $targetDate]);
    return $stmt->fetchAll() ?: [];
}

function svh_day_occupancy_summary(PDO $pdo, string $targetDate): array
{
    if (!svh_is_iso_date($targetDate)) {
        return [
            'date' => date('Y-m-d'),
            'rooms' => [],
            'arrivals' => [],
            'departures' => [],
        ];
    }

    $nextDate = svh_date_add_days($targetDate, 1);
    $availability = svh_room_range_availability($pdo, $targetDate, $nextDate);

    return [
        'date' => $targetDate,
        'rooms' => $availability,
        'arrivals' => svh_day_booking_list($pdo, 'checkin_date', $targetDate),
        'departures' => svh_day_booking_list($pdo, 'checkout_date', $targetDate),
    ];
}
