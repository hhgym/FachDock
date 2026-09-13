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
        ?string $areaCode,
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
        $floorIdsByLocation = [];
        $floorById = [];
        foreach ($floors as $floor) {
            $id = (int) $floor['id'];
            $floorById[$id] = $floor;
            $floorIdsByLocation[$this->floorLocationKey(
                (string) $floor['building_code'],
                (string) $floor['code'],
            )] = $id;
        }

        $recommendedFloorIds = $this->recommendedFloorIds($selection['available'], $floorIdsByLocation);
        $floors = array_map(
            static fn (array $floor): array => $floor + [
                'recommended' => in_array((int) $floor['id'], $recommendedFloorIds, true),
            ],
            $floors,
        );

        $base = [
            'floors' => $floors,
            'floor_plans' => [],
            'areas' => [],
            'recommended_floor_ids' => $recommendedFloorIds,
            'recommended_area_codes' => [],
            'selected_floor_id' => null,
            'selected_area_code' => null,
            'selected_plan_id' => null,
            'floor_auto_selected' => false,
            'area_auto_selected' => false,
            'plan' => null,
        ];
        if ($floors === []) {
            return $selection + $base;
        }

        if ($floorId !== null && !isset($floorById[$floorId])) {
            throw new DomainException('Die ausgewählte Etage ist für die Schließfachbuchung nicht verfügbar.');
        }

        $plan = null;
        $selectedFloorId = $floorId;
        if ($planId !== null) {
            $candidate = $this->floorPlans->plan($planId, $schoolYearId);
            $candidateFloorId = (int) $candidate['floor_id'];
            if (!isset($floorById[$candidateFloorId])) {
                throw new DomainException('Der ausgewählte Lageplan gehört zu keiner aktiven Etage mit Buchungszugang.');
            }
            if ($selectedFloorId === null) {
                $selectedFloorId = $candidateFloorId;
                $plan = $candidate;
            } elseif ($selectedFloorId === $candidateFloorId) {
                $plan = $candidate;
            }
        }

        $floorAutoSelected = false;
        $hasActiveReservation = is_array($selection['active_reservation'] ?? null);
        if ($selectedFloorId === null && !$hasActiveReservation && count($recommendedFloorIds) === 1) {
            $selectedFloorId = $recommendedFloorIds[0];
            $floorAutoSelected = true;
        }
        if ($selectedFloorId === null) {
            return $selection + array_replace($base, [
                'floor_auto_selected' => $floorAutoSelected,
            ]);
        }

        $selectedFloor = $floorById[$selectedFloorId];
        $areas = $this->areasForFloor($selection['available'], $selectedFloor);
        $recommendedAreaCodes = array_values(array_map(
            static fn (array $area): string => (string) $area['code'],
            array_filter($areas, static fn (array $area): bool => (bool) $area['recommended']),
        ));
        $areasByCode = [];
        foreach ($areas as $area) {
            $areasByCode[(string) $area['code']] = $area;
        }

        $areaCode = $areaCode !== null ? trim($areaCode) : null;
        if ($areaCode === '') {
            $areaCode = null;
        }
        if ($areaCode !== null && !isset($areasByCode[$areaCode])) {
            throw new DomainException('Der ausgewählte Bereich ist für diese Buchung nicht verfügbar.');
        }

        $areaAutoSelected = false;
        if ($areaCode === null && !$hasActiveReservation && count($recommendedAreaCodes) === 1) {
            $areaCode = $recommendedAreaCodes[0];
            $areaAutoSelected = true;
        }

        $plans = $this->floorPlans->plansForFloor($selectedFloorId);
        if ($plans === []) {
            return $selection + array_replace($base, [
                'floor_plans' => [],
                'areas' => $areas,
                'recommended_area_codes' => $recommendedAreaCodes,
                'selected_floor_id' => $selectedFloorId,
                'selected_area_code' => $areaCode,
                'floor_auto_selected' => $floorAutoSelected,
                'area_auto_selected' => $areaAutoSelected,
            ]);
        }

        if ($plan === null) {
            $selectedPlanId = (int) $plans[0]['id'];
            $plan = $this->floorPlans->plan($selectedPlanId, $schoolYearId);
        } else {
            $selectedPlanId = (int) $plan['id'];
        }

        return $selection + array_replace($base, [
            'floor_plans' => $plans,
            'areas' => $areas,
            'recommended_area_codes' => $recommendedAreaCodes,
            'selected_floor_id' => $selectedFloorId,
            'selected_area_code' => $areaCode,
            'selected_plan_id' => $selectedPlanId,
            'floor_auto_selected' => $floorAutoSelected,
            'area_auto_selected' => $areaAutoSelected,
            'plan' => $this->decoratePlan($plan, $selection, $selectedFloor, $areaCode),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $available
     * @param array<string, int> $floorIdsByLocation
     * @return list<int>
     */
    private function recommendedFloorIds(array $available, array $floorIdsByLocation): array
    {
        $bestScore = null;
        $ids = [];
        foreach ($available as $locker) {
            $key = $this->floorLocationKey(
                (string) ($locker['building_code'] ?? ''),
                (string) ($locker['floor_code'] ?? ''),
            );
            $floorId = $floorIdsByLocation[$key] ?? null;
            if ($floorId === null) {
                continue;
            }
            $score = (int) ($locker['score'] ?? 0);
            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $ids = [$floorId => true];
            } elseif ($score === $bestScore) {
                $ids[$floorId] = true;
            }
        }

        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $available
     * @param array<string, mixed> $floor
     * @return list<array{code:string,name:string,available_count:int,best_score:int,recommended:bool}>
     */
    private function areasForFloor(array $available, array $floor): array
    {
        $areas = [];
        $bestScore = null;
        foreach ($available as $locker) {
            if (!$this->lockerMatchesFloor($locker, $floor)) {
                continue;
            }
            $code = (string) ($locker['area_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $score = (int) ($locker['score'] ?? 0);
            if (!isset($areas[$code])) {
                $areas[$code] = [
                    'code' => $code,
                    'name' => (string) ($locker['area_name'] ?? $code),
                    'available_count' => 0,
                    'best_score' => $score,
                ];
            }
            ++$areas[$code]['available_count'];
            $areas[$code]['best_score'] = max((int) $areas[$code]['best_score'], $score);
            $bestScore = $bestScore === null ? $score : max($bestScore, $score);
        }

        ksort($areas, SORT_NATURAL | SORT_FLAG_CASE);
        $result = [];
        foreach ($areas as $area) {
            $result[] = $area + [
                'recommended' => $bestScore !== null && (int) $area['best_score'] === $bestScore,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $selectedFloor
     * @return array<string, mixed>
     */
    private function decoratePlan(
        array $plan,
        array $selection,
        array $selectedFloor,
        ?string $selectedAreaCode,
    ): array {
        $available = [];
        $selectedAreaLockerIds = [];
        $scopeCandidates = [];
        foreach ($selection['available'] as $locker) {
            if (!is_array($locker)) {
                continue;
            }
            $lockerId = (int) $locker['locker_id'];
            $available[$lockerId] = $locker;
            if (!$this->lockerMatchesFloor($locker, $selectedFloor)) {
                continue;
            }
            if ($selectedAreaCode !== null && (string) ($locker['area_code'] ?? '') !== $selectedAreaCode) {
                continue;
            }
            $scopeCandidates[$lockerId] = $locker;
            if ($selectedAreaCode !== null) {
                $selectedAreaLockerIds[$lockerId] = true;
            }
        }

        $recommendedLockerIds = [];
        $bestLockerScore = null;
        foreach ($scopeCandidates as $lockerId => $locker) {
            $score = (int) ($locker['score'] ?? 0);
            if ($bestLockerScore === null || $score > $bestLockerScore) {
                $bestLockerScore = $score;
                $recommendedLockerIds = [$lockerId => true];
            } elseif ($score === $bestLockerScore) {
                $recommendedLockerIds[$lockerId] = true;
            }
        }
        if ($bestLockerScore === null || $bestLockerScore <= 0) {
            $recommendedLockerIds = [];
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
            $recommendedCount = 0;
            $areaVisible = $selectedAreaCode === null;

            foreach ($group['lockers'] as $locker) {
                if (!is_array($locker)) {
                    continue;
                }
                $lockerId = (int) $locker['id'];
                $authoritative = $available[$lockerId] ?? null;
                $rawAvailability = (string) ($locker['availability'] ?? 'unavailable');
                $recommended = isset($recommendedLockerIds[$lockerId]);
                if (isset($selectedAreaLockerIds[$lockerId])) {
                    $areaVisible = true;
                }
                if ($recommended) {
                    ++$recommendedCount;
                }

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
                    'recommended' => $recommended,
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
                'recommended_count' => $recommendedCount,
                'area_visible' => $areaVisible,
            ]);
        }

        $plan['groups'] = $groups;

        return $plan;
    }

    /**
     * @param array<string, mixed> $locker
     * @param array<string, mixed> $floor
     */
    private function lockerMatchesFloor(array $locker, array $floor): bool
    {
        return (string) ($locker['building_code'] ?? '') === (string) ($floor['building_code'] ?? '')
            && (string) ($locker['floor_code'] ?? '') === (string) ($floor['code'] ?? '');
    }

    private function floorLocationKey(string $buildingCode, string $floorCode): string
    {
        return $buildingCode . "\0" . $floorCode;
    }
}
