<?php

final class BoulevardRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function saveLocation(
        int $businessId,
        array $location
    ): void {
        $sql = "
            INSERT INTO boulevard_locations (
                business_id,
                boulevard_id,
                name,
                timezone,
                is_remote,
                synced_at
            )
            VALUES (
                :business_id,
                :boulevard_id,
                :name,
                :timezone,
                :is_remote,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                timezone = VALUES(timezone),
                is_remote = VALUES(is_remote),
                synced_at = NOW()
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':business_id' =>
                $businessId,

            ':boulevard_id' =>
                $location['id'],

            ':name' =>
                $location['name'],

            ':timezone' =>
                $location['tz'] ?? null,

            ':is_remote' =>
                !empty($location['isRemote'])
                    ? 1
                    : 0,
        ]);
    }

    public function saveStaff(
        int $businessId,
        array $staff
    ): void {
        $sql = "
            INSERT INTO boulevard_staff (
                business_id,
                boulevard_id,
                name,
                display_name,
                role_name,
                active,
                externally_bookable,
                synced_at
            )
            VALUES (
                :business_id,
                :boulevard_id,
                :name,
                :display_name,
                :role_name,
                :active,
                :externally_bookable,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                display_name = VALUES(display_name),
                role_name = VALUES(role_name),
                active = VALUES(active),
                externally_bookable =
                    VALUES(externally_bookable),
                synced_at = NOW()
        ";

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':business_id' =>
                $businessId,

            ':boulevard_id' =>
                $staff['id'],

            ':name' =>
                $staff['name'],

            ':display_name' =>
                $staff['displayName'] ?? null,

            ':role_name' =>
                $staff['role']['name'] ?? null,

            ':active' =>
                !empty($staff['active'])
                    ? 1
                    : 0,

            ':externally_bookable' =>
                isset(
                    $staff['externallyBookable']
                )
                    ? (
                        $staff['externallyBookable']
                            ? 1
                            : 0
                    )
                    : null,
        ]);
    }
}