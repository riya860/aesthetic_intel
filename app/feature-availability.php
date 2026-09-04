<?php

declare(strict_types=1);


/**
 * ============================================================
 * AESTHETIC INTEL
 * Feature Availability / Maintenance / Coming Soon
 * ============================================================
 *
 * Existing Feature Controls answer:
 *     "Does this business have this feature?"
 *
 * This module answers:
 *     "Is this feature currently available?"
 *
 * Super Admin always bypasses maintenance restrictions so
 * development/fixes can continue while business users see the
 * maintenance/coming-soon experience.
 */


/**
 * Built-in feature/route registry.
 *
 * route prefixes mean:
 *
 * business-ga4
 *
 * matches:
 *
 * business-ga4-integration
 * business-ga4-connect
 * business-ga4-test
 * ...
 */
function feature_availability_registry(): array
{
    return [

        'business_workspace' => [
            'name' => 'Business Workspace',

            /*
             * Useful if you ever need broad maintenance
             * across the business-facing application.
             */
            'routes' => [
                'business-',
            ],
        ],


        'ga4' => [
            'name' => 'Google Analytics 4',

            'routes' => [
                'business-ga4',
                'ga4-test-console',
            ],
        ],


        'boulevard_api' => [
            'name' => 'Boulevard API Integration',

            'routes' => [
                'business-boulevard',
            ],
        ],


        'gbp' => [
            'name' => 'Google Business Profile',

            'routes' => [
                'business-gbp',
            ],
        ],


        'provider_kpi' => [
            'name' => 'Provider KPI',

            'routes' => [
                'business-provider-kpi',
            ],
        ],


        'reports' => [
            'name' => 'Reports & Downloads',

            'routes' => [
                'business-reports',
                'business-report',
                'business-unified',
                'business-ai-report',
            ],
        ],


        'smart_search' => [
            'name' => 'Smart Search',

            'routes' => [
                'smart-search',
            ],
        ],


        'backup_restore' => [
            'name' => 'Backup & Restore',

            /*
             * Business users normally do not access these,
             * but this remains useful for future permissions.
             */
            'routes' => [
                'admin-backup',
                'admin-automatic-backup',
            ],
        ],
    ];
}


/**
 * Split and clean route-prefix input.
 */
function feature_availability_normalize_routes(
    string|array $routes
): array {

    if (is_string($routes)) {

        $routes = preg_split(
            '/[\r\n,]+/',
            $routes
        ) ?: [];
    }


    $clean = [];


    foreach ($routes as $route) {

        $route =
            strtolower(
                trim(
                    (string)$route
                )
            );


        if ($route === '') {
            continue;
        }


        if (
            !preg_match(
                '/^[a-z0-9_-]+$/',
                $route
            )
        ) {
            continue;
        }


        /*
         * Protect critical application routes from
         * accidentally being placed behind maintenance.
         */
        $forbidden = [
            'login',
            'logout',
            'change-password',
            'feature-status',
            'admin-feature-availability',
        ];


        if (
            in_array(
                $route,
                $forbidden,
                true
            )
        ) {
            continue;
        }


        $clean[] = $route;
    }


    return array_values(
        array_unique(
            $clean
        )
    );
}


/**
 * Convert DB route string into array.
 */
function feature_availability_rule_routes(
    array $rule
): array {

    return feature_availability_normalize_routes(
        (string)(
            $rule['route_prefixes']
            ?? ''
        )
    );
}


/**
 * Fetch every configured rule.
 */
function feature_availability_rules(): array
{
    return db()->query(
        "SELECT
            fa.*,
            CASE
                WHEN fa.business_id = 0
                    THEN 'All businesses'
                ELSE b.name
            END AS scope_name

         FROM feature_availability fa

         LEFT JOIN businesses b
             ON b.id = fa.business_id

         ORDER BY
             fa.business_id ASC,
             fa.feature_name ASC"
    )->fetchAll();
}


/**
 * Get one rule by ID.
 */
function feature_availability_rule(
    int $id
): ?array {

    if ($id <= 0) {
        return null;
    }


    $stmt = db()->prepare(
        "SELECT *
         FROM feature_availability
         WHERE id = ?
         LIMIT 1"
    );


    $stmt->execute([
        $id
    ]);


    $row = $stmt->fetch();


    return $row ?: null;
}


/**
 * Determine whether a scheduled rule is currently effective.
 */
function feature_availability_rule_is_current(
    array $rule
): bool {

    $now = new DateTimeImmutable(
        'now'
    );


    if (
        !empty(
            $rule['starts_at']
        )
    ) {

        try {

            $start =
                new DateTimeImmutable(
                    (string)$rule['starts_at']
                );


            if ($now < $start) {
                return false;
            }

        } catch (Throwable) {
            return false;
        }
    }


    if (
        !empty(
            $rule['ends_at']
        )
    ) {

        try {

            $end =
                new DateTimeImmutable(
                    (string)$rule['ends_at']
                );


            if ($now > $end) {
                return false;
            }

        } catch (Throwable) {
            return false;
        }
    }


    return true;
}


/**
 * Resolve all applicable rules.
 *
 * A business-specific rule overrides the global rule
 * for the same feature.
 */
function feature_availability_effective_rules(
    int $businessId = 0
): array {

    if ($businessId > 0) {

        $stmt = db()->prepare(
            "SELECT *
             FROM feature_availability

             WHERE business_id IN (0, ?)

             ORDER BY
                feature_key ASC,
                business_id DESC"
        );


        $stmt->execute([
            $businessId
        ]);


        $rows =
            $stmt->fetchAll();

    } else {

        $stmt = db()->query(
            "SELECT *
             FROM feature_availability

             WHERE business_id = 0

             ORDER BY
                feature_key ASC"
        );


        $rows =
            $stmt->fetchAll();
    }


    /*
     * First row for a feature wins.
     *
     * Because business-specific rows are sorted before
     * business_id=0, business override wins.
     */
    $resolved = [];


    foreach ($rows as $row) {

        $key =
            (string)$row[
                'feature_key'
            ];


        if (
            isset(
                $resolved[$key]
            )
        ) {
            continue;
        }


        $resolved[$key] =
            $row;
    }


    return $resolved;
}


/**
 * Resolve one feature's effective rule.
 */
function feature_availability_for_feature(
    string $featureKey,
    int $businessId = 0
): ?array {

    $rules =
        feature_availability_effective_rules(
            $businessId
        );


    return
        $rules[$featureKey]
        ?? null;
}


/**
 * Determine which maintenance/coming-soon rule
 * applies to the requested route.
 */
function feature_availability_for_route(
    string $page,
    int $businessId = 0
): ?array {

    $page =
        strtolower(
            trim($page)
        );


    if ($page === '') {
        return null;
    }


    $rules =
        feature_availability_effective_rules(
            $businessId
        );


    $matches = [];


    foreach ($rules as $rule) {

        $status =
            (string)(
                $rule['status']
                ?? 'active'
            );


        if (
            !in_array(
                $status,
                [
                    'maintenance',
                    'coming_soon',
                ],
                true
            )
        ) {
            continue;
        }


        if (
            !feature_availability_rule_is_current(
                $rule
            )
        ) {
            continue;
        }


        foreach (
            feature_availability_rule_routes(
                $rule
            )
            as $prefix
        ) {

            if (
                str_starts_with(
                    $page,
                    $prefix
                )
            ) {

                $matches[] = [
                    'rule' =>
                        $rule,

                    'prefix_length' =>
                        strlen(
                            $prefix
                        ),
                ];
            }
        }
    }


    if (!$matches) {
        return null;
    }


    /*
     * Most-specific route prefix wins.
     */
    usort(
        $matches,
        static fn(
            array $a,
            array $b
        ): int =>
            $b['prefix_length']
            <=>
            $a['prefix_length']
    );


    return
        $matches[0]['rule']
        ?? null;
}


/**
 * Server-side maintenance enforcement.
 *
 * IMPORTANT:
 * Hiding a sidebar button alone is not security.
 * Direct route access must also be blocked.
 */
function feature_availability_enforce_request(
    string $page
): void {

    if (!auth_check()) {
        return;
    }


    /*
     * Super Admin keeps access so work can continue.
     */
    if (auth_is_admin()) {
        return;
    }


    /*
     * Routes that must never be intercepted.
     */
    $safePages = [
        'home',
        'login',
        'logout',
        'change-password',
        'feature-status',
    ];


    if (
        in_array(
            $page,
            $safePages,
            true
        )
    ) {
        return;
    }


    /*
     * Never interfere with Super Admin routes.
     */
    if (
        str_starts_with(
            $page,
            'admin-'
        )
    ) {
        return;
    }


    $businessId =
        (int)business_context_id();


    $rule =
        feature_availability_for_route(
            $page,
            $businessId
        );


    if (!$rule) {
        return;
    }


    $_SESSION[
        '_feature_availability_return'
    ] = $page;


    redirect(
        url(
            'feature-status',
            [
                'feature' =>
                    (string)$rule[
                        'feature_key'
                    ],
            ]
        )
    );
}


/**
 * Active announcement list for the current user/business.
 */
function feature_availability_announcements(): array
{
    if (!auth_check()) {
        return [];
    }


    $businessId =
        (int)business_context_id();


    $rules =
        feature_availability_effective_rules(
            $businessId
        );


    $result = [];


    foreach ($rules as $rule) {

        if (
            empty(
                $rule[
                    'show_announcement'
                ]
            )
        ) {
            continue;
        }


        if (
            !in_array(
                (string)$rule['status'],
                [
                    'maintenance',
                    'coming_soon',
                ],
                true
            )
        ) {
            continue;
        }


        if (
            !feature_availability_rule_is_current(
                $rule
            )
        ) {
            continue;
        }


        $result[] = $rule;
    }


    return array_slice(
        $result,
        0,
        3
    );
}


/**
 * HTML datetime-local -> MySQL datetime.
 */
function feature_availability_parse_datetime(
    ?string $value
): ?string {

    $value =
        trim(
            (string)$value
        );


    if ($value === '') {
        return null;
    }


    try {

        $date =
            new DateTimeImmutable(
                $value
            );


        return
            $date->format(
                'Y-m-d H:i:s'
            );

    } catch (Throwable) {

        throw new RuntimeException(
            'Invalid availability date/time.'
        );
    }
}


/**
 * Save/create a rule from the admin form.
 */
function feature_availability_save(
    array $input,
    ?int $actorId = null
): int {

    $registry =
        feature_availability_registry();


    $preset =
        trim(
            (string)(
                $input['preset_key']
                ?? ''
            )
        );


    /*
     * Built-in feature.
     */
    if (
        $preset !== ''
        &&
        $preset !== '__custom__'
        &&
        isset(
            $registry[$preset]
        )
    ) {

        $featureKey =
            $preset;


        $featureName =
            (string)$registry[
                $preset
            ]['name'];


        $routes =
            $registry[
                $preset
            ]['routes'];


    } else {

        /*
         * Custom/upcoming feature.
         */
        $featureKey =
            strtolower(
                trim(
                    (string)(
                        $input[
                            'custom_feature_key'
                        ]
                        ?? ''
                    )
                )
            );


        $featureKey =
            preg_replace(
                '/[^a-z0-9_-]+/',
                '-',
                $featureKey
            );


        $featureKey =
            trim(
                (string)$featureKey,
                '-'
            );


        $featureName =
            trim(
                (string)(
                    $input[
                        'feature_name'
                    ]
                    ?? ''
                )
            );


        $routes =
            feature_availability_normalize_routes(
                (string)(
                    $input[
                        'route_prefixes'
                    ]
                    ?? ''
                )
            );
    }


    if (
        $featureKey === ''
        ||
        $featureName === ''
    ) {

        throw new RuntimeException(
            'Feature key and feature name are required.'
        );
    }


    $status =
        (string)(
            $input['status']
            ?? 'active'
        );


    if (
        !in_array(
            $status,
            [
                'active',
                'maintenance',
                'coming_soon',
            ],
            true
        )
    ) {

        $status = 'active';
    }


    $businessId =
        max(
            0,
            (int)(
                $input[
                    'business_id'
                ]
                ?? 0
            )
        );


    /*
     * Make sure a scoped business exists.
     */
    if ($businessId > 0) {

        $stmt =
            db()->prepare(
                "SELECT COUNT(*)
                 FROM businesses
                 WHERE id = ?"
            );


        $stmt->execute([
            $businessId
        ]);


        if (
            !(int)$stmt->fetchColumn()
        ) {

            throw new RuntimeException(
                'Selected business does not exist.'
            );
        }
    }


    $message =
        trim(
            (string)(
                $input['message']
                ?? ''
            )
        );


    $eta =
        trim(
            (string)(
                $input['eta_text']
                ?? ''
            )
        );


    if (
        mb_strlen(
            $message
        ) > 700
    ) {

        throw new RuntimeException(
            'Announcement message is too long.'
        );
    }


    $startsAt =
        feature_availability_parse_datetime(
            $input['starts_at']
            ?? null
        );


    $endsAt =
        feature_availability_parse_datetime(
            $input['ends_at']
            ?? null
        );


    if (
        $startsAt
        &&
        $endsAt
        &&
        $startsAt > $endsAt
    ) {

        throw new RuntimeException(
            'End time cannot be before start time.'
        );
    }


    $showAnnouncement =
        !empty(
            $input[
                'show_announcement'
            ]
        )
        ? 1
        : 0;


    $routeString =
        implode(
            "\n",
            $routes
        );


    /*
     * Unique key:
     *
     * business_id + feature_key
     *
     * means Save updates the same scope instead of creating
     * duplicate availability rules.
     */
    $stmt = db()->prepare(
        "INSERT INTO feature_availability (
            business_id,
            feature_key,
            feature_name,
            route_prefixes,
            status,
            message,
            eta_text,
            show_announcement,
            starts_at,
            ends_at,
            created_by,
            updated_by
        )

        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )

        ON DUPLICATE KEY UPDATE

            feature_name =
                VALUES(feature_name),

            route_prefixes =
                VALUES(route_prefixes),

            status =
                VALUES(status),

            message =
                VALUES(message),

            eta_text =
                VALUES(eta_text),

            show_announcement =
                VALUES(show_announcement),

            starts_at =
                VALUES(starts_at),

            ends_at =
                VALUES(ends_at),

            updated_by =
                VALUES(updated_by),

            updated_at =
                CURRENT_TIMESTAMP"
    );


    $stmt->execute([
        $businessId,
        $featureKey,
        $featureName,
        $routeString,
        $status,
        $message !== ''
            ? $message
            : null,
        $eta !== ''
            ? $eta
            : null,
        $showAnnouncement,
        $startsAt,
        $endsAt,
        $actorId,
        $actorId,
    ]);


    /*
     * Find actual record ID after INSERT/UPDATE.
     */
    $stmt = db()->prepare(
        "SELECT id
         FROM feature_availability
         WHERE business_id = ?
           AND feature_key = ?
         LIMIT 1"
    );


    $stmt->execute([
        $businessId,
        $featureKey
    ]);


    return
        (int)$stmt->fetchColumn();
}


/**
 * Delete an availability rule.
 *
 * This does NOT delete or disable the actual feature.
 */
function feature_availability_delete(
    int $id
): ?array {

    $rule =
        feature_availability_rule(
            $id
        );


    if (!$rule) {
        return null;
    }


    $stmt = db()->prepare(
        "DELETE FROM feature_availability
         WHERE id = ?"
    );


    $stmt->execute([
        $id
    ]);


    return $rule;
}