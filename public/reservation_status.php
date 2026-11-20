<?php
/** Wspólna zmiana statusu dla istniejących paneli. Autoryzacja i CSRF należą do panelu. */
function reservation_transitions(string $status): array
{
    return match ($status) {
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['cancelled', 'completed', 'no_show'],
        default => [],
    };
}

function change_reservation_status(PDO $pdo, int $reservationId, string $status, string $expectedStatus, ?int $ownerEmployeeId = null): void
{
    if ($reservationId < 1 || !in_array($status, ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'], true)) {
        throw new RuntimeException('Nieprawidłowa rezerwacja lub status.');
    }
    // Odczyt pomocniczy poza transakcją: służy wyłącznie wybraniu blokady.
    $stmt = $pdo->prepare('SELECT employee_id FROM reservations WHERE reservation_id = ?');
    $stmt->execute([$reservationId]);
    $employeeId = $stmt->fetchColumn();
    if (!$employeeId || ($ownerEmployeeId !== null && (int)$employeeId !== $ownerEmployeeId)) {
        throw new RuntimeException('Nie znaleziono rezerwacji lub nie masz do niej uprawnień.');
    }
    try {
        $pdo->beginTransaction();
        // Ta sama blokada, co przy INSERT klienta, także gdy termin nie ma żadnych rezerwacji.
        // Zawsze pracownik przed rezerwacją, aby nie odwracać kolejności blokad.
        $stmt = $pdo->prepare('SELECT employee_id FROM employees WHERE employee_id = ? FOR UPDATE');
        $stmt->execute([$employeeId]);
        if (!$stmt->fetch()) throw new RuntimeException('Nie znaleziono pracownika.');
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE reservation_id = ? FOR UPDATE');
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reservation || (int)$reservation['employee_id'] !== (int)$employeeId) {
            throw new RuntimeException('Rezerwacja została zmieniona. Odśwież stronę i spróbuj ponownie.');
        }
        if ($reservation['status'] !== $expectedStatus) {
            throw new RuntimeException('Status rezerwacji został zmieniony. Odśwież stronę i spróbuj ponownie.');
        }
        if (!in_array($status, reservation_transitions($reservation['status']), true)) {
            throw new RuntimeException('Niedozwolona zmiana statusu rezerwacji. Statusy końcowe nie mogą być przywracane.');
        }
        if (in_array($status, ['pending', 'confirmed'], true)) {
            if ($reservation['start_time'] >= $reservation['end_time']) {
                throw new RuntimeException('Rezerwacja ma nieprawidłowy przedział czasu.');
            }
            // Current read: po oczekiwaniu na pracownika widzi również najnowszy COMMIT.
            $stmt = $pdo->prepare("SELECT reservation_id FROM reservations
                WHERE employee_id = :employee AND reservation_date = :date
                  AND reservation_id <> :id AND status IN ('pending', 'confirmed')
                  AND start_time < :end AND end_time > :start
                LIMIT 1 FOR UPDATE");
            $stmt->execute(['employee'=>$employeeId, 'date'=>$reservation['reservation_date'],
                'id'=>$reservationId, 'end'=>$reservation['end_time'], 'start'=>$reservation['start_time']]);
            if ($stmt->fetch()) throw new RuntimeException('Ten termin jest już zajęty. Nie można aktywować rezerwacji.');
            if (new DateTimeImmutable($reservation['reservation_date'].' '.$reservation['start_time']) <= new DateTimeImmutable()) {
                throw new RuntimeException('Nie można aktywować rezerwacji z terminem w przeszłości.');
            }
        }
        $stmt = $pdo->prepare('UPDATE reservations SET status = ? WHERE reservation_id = ? AND employee_id = ? AND status = ?');
        $stmt->execute([$status, $reservationId, $employeeId, $expectedStatus]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Nie zmieniono statusu rezerwacji. Odśwież stronę.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
