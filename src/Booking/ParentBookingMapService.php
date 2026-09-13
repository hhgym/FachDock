<?php

declare(strict_types=1);

namespace FachDock\Booking;

use DomainException;
use FachDock\FloorPlan\FloorPlanService;
use FachDock\Parent\AuthenticatedParent;

final class ParentBookingMapService
{
    public function __construct(
        private readonly ParentBookingService $bookings,
        private readonly FloorPlanService $floorPlans,
    ) {
    }

    /** @return array<string, mixed> */
    public function selection(
        AuthenticatedParent $parent,
        int $studentId,
        int $schoolYearId,
        ?int $floorId,
        ?int $planId,
        int $recommendationCount,
    ): array {
        $selection = $this->bookings->selection(
            $parent,
            $studentId,
            $schoolYearId,
            $recommendationCount,
        );

        $floors = array_values(array_filter(
            $this->floorPlans->floors(),
            static fn (array $floor): bool => (int) $floor['plan_count'] > 0,
        ));
        if ($floors === []) {
            return $selection + [
                'floors' => [],
                'floor_plans' => [],
                'selected_floor_id' => null,
                'selected_plan_id' => null,
                'plan' => null,
            ];
        }

        $floorIds = [];
        foreach ($floors as $floor) {
            $floorIds[(int) $floor['id']] = true;
        }

        if ($floorId !== null && !isset($floorIds[$floorId])) {
            throw new DomainException('Die ausgewählte Etage ist für die Schließfachbuchung nicht verfügbar.');
        }

        $plan = null;
        $selectedFloorId = $floorId;
        if ($planId !== null) {
            $candidate = $this->floorPlans->plan($planId, $schoolYearId);
            $candidateFloorId = (int) $candidate['floor_id'];
            if (!isset($floorIds[$candidateFloorId])) {
                throw new DomainException('Der ausgewählte Lageplan gehört zu keiner aktiven Etage mit Buchungszugang.');
            }
            if ($selectedFloorId !== null && $selectedFloorId !== $candidateFloorId) {
                throw new DomainException('Der ausgewählte Lageplan gehört nicht zur ausgewählten Etage.');
            }
            $selectedFloorId = $candidateFloorId;
            $plan = $candidate;
        }

        if ($selectedFloorId === null) {
            return $selection + [
                'floors' => $floors,
                'floor_plans' => [],
                'selected_floor_id' => null,
                'selected_plan_id' => null,
                'plan' => null,
            ];
        }

        $plans = $this->floorPlans->plansForFloor($selectedFloorId);
        if ($plans === []) {
            return $selection + [
                'floors' => $floors,
                'floor_plans' => [],
                'selected_floor_id' => $selectedFloorId,
                'selected_plan_id' => null,
                'plan' => null,
            ];
        }

        if ($plan === null) {
            $selectedPlanId = (int) $plans[0]['id'];
            $plan = $this->floorPlans->plan($selectedPlanId, $schoolYearId);
        } else {
            $selectedPlanId = (int) $plan['id'];
        }

        return $selection + [
            'floors' => $floors,
            'floor_plans' => $plans,
            'selected_floor_id' => $selectedFloorId,
            'selected_plan_id' => $selectedPlanId,
            'plan' => $this->decoratePlan($plan, $selection),
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function decoratePlan(array $plan, array $selection): array
    {
        $available = [];
        foreach ($selection['available'] as $locker) {
            if (!is_array($locker)) {
                continue;
            }
            $available[(int) $locker['locker_id']] = $locker;
        }

        $recommended = [];
        foreach ($selection['recommended'] as $locker) {
            if (is_array($locker)) {
                $recommended[(int) $locker['locker_id']] = true;
            }
        }

        $activeReservation = is_array($selection['active_reservation'] ?? null)
            ? $selection['active_reservation']
            : null;
        $selectedLockerId = $activeReservation !== null
            ? (int) ($activeReservation['locker_id'] ?? 0)
            : 0;
        $paymentRunning = $activeReservation !== null
            && (string) ($activeReservation['status'] ?? '') === ReservationStatus::PaymentRunning->value;

        $groups = [];
        foreach ($plan['groups'] as $group) {
            if (!is_array($group)) {
                continue;
            }
            $lockers = [];
            $selectableCount = 0;
            $restrictedCount = 0;
            $selectedCount = 0;

            foreach ($group['lockers'] as $locker) {
                if (!is_array($locker)) {
                    continue;
                }
                $lockerId = (int) $locker['id'];
                $authoritative = $available[$lockerId] ?? null;
                $rawAvailability = (string) ($locker['availability'] ?? 'unavailable');

                if ($lockerId === $selectedLockerId) {
                    $bookingStatus = 'selected';
                    ++$selectedCount;
                } elseif (is_array($authoritative)) {
                    $bookingStatus = 'selectable';
                    ++$selectableCount;
                } elseif ($rawAvailability === 'occupied') {
                    $bookingStatus = 'occupied';
                } elseif ($rawAvailability === 'unavailable') {
                    $bookingStatus = 'unavailable';
                } else {
                    $bookingStatus = 'restricted';
                    ++$restrictedCount;
                }

                $lockers[] = array_replace($locker, [
                    'booking_status' => $bookingStatus,
                    'recommended' => isset($recommended[$lockerId]),
                    'score' => is_array($authoritative) ? (int) $authoritative['score'] : null,
                    'barrier_friendly' => is_array($authoritative)
                        ? (bool) $authoritative['barrier_friendly']
                        : false,
                    'long_name' => is_array($authoritative)
                        ? (string) $authoritative['long_name']
                        : (string) ($locker['short_name'] ?? ''),
                    'can_select' => $bookingStatus === 'selectable' && !$paymentRunning,
                ]);
            }

            $markerStatus = $selectedCount > 0
                ? 'selected'
                : ($selectableCount > 0 ? 'free' : ($restrictedCount > 0 ? 'warning' : 'full'));
            $groups[] = array_replace($group, [
                'lockers' => $lockers,
                'booking_marker_status' => $markerStatus,
                'selectable_count' => $selectableCount,
                'restricted_count' => $restrictedCount,
                'selected_count' => $selectedCount,
            ]);
        }

        $plan['groups'] = $groups;

        return $plan;
    }
}
