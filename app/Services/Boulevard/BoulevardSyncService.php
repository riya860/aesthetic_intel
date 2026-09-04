<?php

require_once __DIR__ . '/BoulevardService.php';

final class BoulevardSyncService
{
    public function __construct(
        private BoulevardService $boulevard,
        private BoulevardRepository $repository
    ) {
    }

    public function syncReferenceData(
        int $businessId
    ): array {
        $stats = [
            'locations' => 0,
            'staff' => 0,
            'services' => 0,
        ];

        foreach (
            $this->boulevard->getLocations()
            as $location
        ) {
            $this->repository->saveLocation(
                $businessId,
                $location
            );

            $stats['locations']++;
        }

        foreach (
            $this->boulevard->getStaff()
            as $staff
        ) {
            $this->repository->saveStaff(
                $businessId,
                $staff
            );

            $stats['staff']++;
        }

        /*
         * Add repository saveService()
         * using the same upsert pattern.
         */
        foreach (
            $this->boulevard->getServices()
            as $service
        ) {
            // $this->repository
            //     ->saveService(...);

            $stats['services']++;
        }

        return $stats;
    }
}