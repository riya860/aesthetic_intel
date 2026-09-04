<?php

declare(strict_types=1);

/**
 * Aesthetic Intel in-app documentation registry.
 *
 * Keep this file updated whenever a user-facing feature, route, permission,
 * upload requirement, or workflow changes. Change #5 Smart Search can reuse
 * the same registry so documentation and navigation guidance stay aligned.
 */

function documentation_role_label(): string {
    if (auth_is_admin()) {
        return admin_business_view_active() ? 'Super Admin · Business View' : 'Super Admin';
    }

    return match (provider_kpi_user_role()) {
        'leadership' => 'Business User · Leadership',
        'data_uploader' => 'Business User · Data Uploader',
        'provider' => 'Business User · Provider',
        default => 'Business User',
    };
}

function documentation_business_context(): ?array {
    $businessId = (int)(business_context_id() ?? 0);
    if ($businessId <= 0) return null;

    try {
        $stmt = db()->prepare('SELECT id,name,status,timezone FROM businesses WHERE id=? LIMIT 1');
        $stmt->execute([$businessId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

function documentation_topic(
    string $id,
    string $section,
    string $title,
    string $summary,
    array $steps,
    ?string $route = null,
    string $action = 'Open',
    array $audiences = ['all'],
    array $keywords = [],
    ?string $note = null,
    string $status = 'active'
): array {
    return compact('id','section','title','summary','steps','route','action','audiences','keywords','note','status');
}

function documentation_user_audiences(): array {
    $audiences = ['all'];
    if (auth_is_admin()) {
        $audiences[] = 'admin';
        if (admin_business_view_active()) $audiences[] = 'business';
        $audiences[] = 'leadership';
        $audiences[] = 'data_uploader';
        return array_values(array_unique($audiences));
    }

    $audiences[] = 'business';
    $role = provider_kpi_user_role();
    if (in_array($role, ['leadership','data_uploader','provider'], true)) $audiences[] = $role;
    else $audiences[] = 'viewer';
    return array_values(array_unique($audiences));
}

function documentation_registry(): array {
    $business = documentation_business_context();
    $businessId = (int)($business['id'] ?? 0);
    $providerEnabled = $businessId > 0 && provider_kpi_enabled($businessId);
    $boulevardAuto = $businessId > 0 ? boulevard_business_user_access($businessId) : ['enabled'=>false];
    $boulevardAutoEnabled = $businessId > 0 && business_feature_enabled($businessId,'boulevard_api') && !empty($boulevardAuto['enabled']);

    $topics = [];

    // START HERE
    $topics[] = documentation_topic(
        'start-dashboard','Start Here','Dashboard','Your starting point for the current workspace.',
        auth_is_admin() && !admin_business_view_active()
            ? ['Review platform activity.','Open Businesses to manage or enter a business.','Use Documentation whenever you need a quick workflow.']
            : ['Choose the tool or report you need.','Add the newest reporting data.','Open Reports & Downloads to review saved results.'],
        auth_is_admin() && !admin_business_view_active() ? url('admin-dashboard') : url('business-dashboard'),
        'Open Dashboard',['all'],['home','dashboard','start']
    );

    $topics[] = documentation_topic(
        'smart-search','Start Here','Smart Search','Find the correct Aesthetic Intel feature with natural-language task search.',
        ['Open Smart Search.','Describe what you are trying to do in plain language.','Open the best matching feature.','If the local match is uncertain, AI may choose only from features already allowed for your account.'],
        url('smart-search'),'Open Smart Search',['all'],['smart search','feature finder','find feature','where is','how do i','navigation','search'],
        'Smart Search is navigation only. It never executes destructive actions or bypasses the normal confirmation on the destination page.'
    );

    if (auth_is_admin()) {
        $topics[] = documentation_topic(
            'admin-business-view','Start Here','Open a Business as Super Admin','Work inside any business without using its login.',
            ['Open Businesses.','Choose Open / View for the business.','Use Return to Super Admin when finished.'],
            url('admin-businesses'),'Open Businesses',['admin'],['business view','switch business','open business']
        );
    }

    // SUPER ADMIN
    $topics[] = documentation_topic(
        'admin-businesses','Super Admin','Businesses','Create, edit, open, configure features, or securely delete a business.',
        ['Open Businesses.','Add or edit the business details.','Use Feature Controls in Edit Business to enable only the tools that business needs.','Disabling a feature preserves its saved data.','Deleting a business requires your current Super Admin password.'],
        url('admin-businesses'),'Manage Businesses',['admin'],['business','add business','edit business','delete business','provider kpi enable']
    );

        $topics[] = documentation_topic(
        'admin-ai-weekly-reports',
        'Super Admin',
        'AI Weekly Reports',
        'Create a weekly business-performance dashboard from a written report using AI, review it privately, and publish the approved result to the selected business.',
        [
            'Open AI Weekly Reports.',
            'Choose the business that the report belongs to.',
            'Select the reporting-period start and end dates.',
            'Paste the sanitized weekly report text.',
            'Confirm that the pasted content does not contain patient-identifying information.',
            'Click Generate Dashboard.',
            'AI analyzes the report and creates a structured private dashboard preview.',
            'Compare the generated metrics, wins, risks, opportunities, and recommendations with the original pasted report.',
            'Use Regenerate if the dashboard needs another AI generation.',
            'Click Display on Business Dashboard only after the generated report has been reviewed and approved.',
            'Published reports become visible only to the selected business.',
            'Use Archive when a published report should no longer appear as the current business report.'
        ],
        url('admin-ai-weekly-reports'),
        'Manage AI Weekly Reports',
        ['admin'],
     [
    'ai weekly report',
    'weekly report',
    'openai',
    'weekly dashboard',
    'generate dashboard',
    'publish weekly report',
    'business dashboard',
    'weekly intelligence',
    'ai report',
    'weekly performance report'
],
        'AI generates structured report content only. The original pasted report remains stored separately, and nothing is published automatically.'
    );
    $topics[] = documentation_topic(
        'admin-feature-controls','Super Admin','Business Feature Controls','Enable only the optional dashboard modules each business actually uses without deleting historical data.',
        ['Open Businesses.','Choose Edit / Features for the business.','Scroll to Feature Controls.','Turn reporting sources, Provider KPI, report intelligence tools, or workspace tools on/off.','Save Business & Feature Controls.','Disabled modules disappear from business navigation and direct access is blocked until re-enabled.'],
        url('admin-businesses'),'Manage Feature Controls',['admin'],['feature controls','enable feature','disable feature','hide module','business modules','dashboard tools','turn off feature'],
        'Feature switches preserve existing reports, uploads, KPI history, reviews, and configuration.'
    );

    $topics[] = documentation_topic(
        'admin-users','Super Admin','Users & Access','Create accounts, assign businesses, reset passwords, and set Provider KPI access.',
        ['Open Users.','Add or edit the user.','Choose Business User or Super Admin.','For Provider KPI, choose Viewer, Leadership, Data Uploader, or Provider access.'],
        url('admin-users'),'Manage Users',['admin'],['users','access','roles','password','provider role']
    );
    $topics[] = documentation_topic(
        'admin-upload-monitoring','Super Admin','Upload Monitoring','Check Boulevard upload status across businesses.',
        ['Open Upload Monitoring.','Review completed or failed uploads.','Open the related business/report when follow-up is needed.'],
        url('admin-uploads'),'Open Monitoring',['admin'],['monitor','uploads','failed upload']
    );
    $topics[] = documentation_topic(
        'admin-ai','Super Admin','AI Integration','Manage the OpenAI key used by AI-assisted tools.',
        ['Open AI Integration.','Save or update the API key.','Use the test/fetch controls shown on that page before relying on AI extraction.'],
        url('admin-ai-settings'),'Open AI Integration',['admin'],['ai','openai','api key']
    );
    $topics[] = documentation_topic(
        'admin-backup','Super Admin','Backup & Restore','Create manual backups, schedule encrypted daily full-system backups, and restore verified snapshots.',
        ['Open Backup & Restore.','For daily protection, set time, timezone, retention, and an encryption password.','Add the displayed Hostinger cron command once, then run Test Backup Now.','If today reaches the automatic retry limit after a server/cron fix, use Reset Today’s Automatic Backup Retry once so the next cron check can try again.','Use Backup History to Download, Validate, Restore, or Delete retained backups.','Restore only a Backup Verified snapshot when recovery is required.'],
        url('admin-backup'),'Open Backup & Restore',['admin'],['backup','restore','recovery','automatic backup','daily backup','cron','retention','backup history']
    );
    $topics[] = documentation_topic(
        'admin-boulevard-types','Super Admin','Boulevard Report Types','Maintain Boulevard report definitions used by the platform.',
        ['Open Boulevard Report Types.','Review the configured report types.','Change mappings only when the required Boulevard report format has been verified.'],
        url('admin-boulevard-report-types'),'Open Report Types',['admin'],['boulevard report types','mapping','csv']
    );

    // BUSINESS WORKSPACE
    if ($businessId > 0 || !auth_is_admin()) {
        $topics[] = documentation_topic(
            'business-gbp','Reporting Tools','Google Business Profile','Save the current cumulative GBP totals; Aesthetic Intel calculates new activity from the prior entry.',
            ['Open Tools → Google Business Profile.','Choose the reporting period.','Enter the current cumulative values shown in GBP.','Save & Compare.'],
            url('business-gbp'),'Add GBP Data',['business'],['google business profile','gbp','calls','directions','reviews','website clicks'],
            'Use the same rolling GBP window each time for cumulative metrics.'
        );

        if (auth_is_admin()) {
            $topics[] = documentation_topic(
                'business-boulevard-admin','Reporting Tools','Boulevard Uploads & API Beta','Upload verified Boulevard CSVs manually. Super Admin can also access the API Beta area.',
                ['Use Boulevard Uploads for the approved CSV workflow.','Choose the correct reporting period.','Review validation before saving.','Treat API Integration as Beta until live exports are fully verified.'],
                url('business-upload'),'Open Boulevard',['admin'],['boulevard','csv','api','beta','upload']
            );
        } elseif ($boulevardAutoEnabled) {
            $topics[] = documentation_topic(
                'business-boulevard-run','Reporting Tools','Run Boulevard Report','Run the Super Admin-approved Boulevard weekly report.',
                ['Open Tools → Run Boulevard Report.','Confirm the weekly period.','Start the report.','Return later if processing continues in the background.'],
                url('business-boulevard-run'),'Run Boulevard Report',['business'],['boulevard','weekly report','run report']
            );
        } else {
            $topics[] = documentation_topic(
                'business-boulevard-upload','Reporting Tools','Boulevard','Upload the required Boulevard CSV exports for the selected reporting period.',
                ['Open Tools → Boulevard.','Choose the reporting period.','Upload the requested CSV files.','Review validation, then save the report.'],
                url('business-upload'),'Open Boulevard',['business'],['boulevard','csv','upload report']
            );
        }

        $topics[] = documentation_topic(
            'business-podium','Reporting Tools','Podium','Upload Podium Inbox or Calls reports for AI extraction.',
            ['Open Tools → Podium.','Set the correct reporting period.','Upload one Inbox or Calls PDF/image at a time.','Wait for extraction to save before uploading the next report.'],
            url('business-ai-extraction',['source'=>'podium']),'Open Podium',['business'],['podium','inbox','calls','upload']
        );
        $topics[] = documentation_topic(
            'business-growth99','Reporting Tools','Growth99+','Upload available Growth99+ reports for AI extraction.',
            ['Open Tools → Growth99+.','Set the correct reporting period.','Upload each available report individually.','Review the saved metrics after extraction.'],
            url('business-ai-extraction',['source'=>'growth99']),'Open Growth99+',['business'],['growth99','leads','cliffhanger','callrail']
        );
        $topics[] = documentation_topic(
            'business-ga4','Reporting Tools','Google Analytics 4','Upload the GA4 Reports snapshot PDF or screenshot.',
            ['Open Tools → Google Analytics 4.','Set the correct reporting period.','Upload the Reports snapshot.','Review the extracted metrics after it saves.'],
            url('business-ai-extraction',['source'=>'ga4']),'Open GA4',['business'],['ga4','google analytics','reports snapshot']
        );
        $topics[] = documentation_topic(
            'business-reports','Reports','Reports & Downloads','Open original reports and separately saved AI Reviewed Reports from one place.',
            ['Open Reports & Downloads.','Use Original to open the source report.','When Review with AI is available to your role, use it for deeper business analysis.','Open Reports & Downloads by AI later without spending another AI request.'],
            url('business-history'),'Open Reports',['business'],['reports','downloads','history','unified report','ai reviewed report']
        );

                if (
            $businessId > 0
            &&
            business_feature_enabled(
                $businessId,
                'ai_weekly_report'
            )
        ) {

            $topics[] = documentation_topic(
                'business-ai-weekly-reports',
                'Reports',
                'AI Weekly Reports',
                'View the weekly performance dashboards that have been reviewed and published for your business.',
                [
                    'Open AI Weekly Reports.',
                    'Review the latest published weekly performance report.',
                    'Use the executive summary for the overall weekly picture.',
                    'Review the KPI cards for the most important source-supported metrics.',
                    'Review Wins to understand areas of strong performance.',
                    'Review Risks for areas that may require attention.',
                    'Review Opportunities for potential areas of improvement.',
                    'Review Recommended Actions for suggested next steps.',
                    'Open Report History to view earlier published weekly reports.',
                    'Use Print / Save PDF when you need a shareable copy.'
                ],
                url('business-ai-weekly-reports'),
                'Open AI Weekly Reports',
                ['business'],
                [
                    'ai weekly report',
                    'weekly report',
                    'weekly dashboard',
                    'weekly performance',
                    'weekly intelligence',
                    'weekly summary',
                    'wins',
                    'risks',
                    'opportunities',
                    'recommendations',
                    'report history',
                    'gemini report'
                ],
                'Business users can view only published reports belonging to their own business. Drafts, Gemini generation controls, API settings, token usage, and Super Admin review controls are not available here.'
            );
        }
        $topics[] = documentation_topic(
            'business-ai-reviewed-report','Reports','Review with AI','Create a separate AI-reviewed version of an existing report without changing its original numbers.',
            ['Open Reports & Downloads or an original report.','Click Review with AI.','Aesthetic Intel reviews every available section using a compact normalized report.','Open the saved AI Reviewed Report for executive summary, wins, risks, anomalies, KPI observations, opportunities, and actions.','Use Save as PDF when needed.'],
            url('business-history'),'Find AI Reviews',['admin','viewer','leadership','data_uploader'],['review with ai','ai report','reports by ai','ai reviewed report','business insights']
        );
        $topics[] = documentation_topic(
            'business-report-intelligence','Reports','Report Intelligence','Automatic safety gate that checks reporting periods and unusual changes before data is allowed into comparisons.',
            ['Upload or enter the report normally.','Aesthetic Intel checks the reporting period, structure, historical pattern, and AI validation.','Comparisons use only a previous report with the same frequency; custom periods must also have the same length.','Validated reports compare automatically; warnings stay visible.','Review Required reports are held until corrected or Super Admin-approved.','This safety gate is separate from the optional Review with AI business analysis.'],
            url('business-history'),'Review Validation',['business'],['report intelligence','validation','wrong period','anomaly','review required','ai validation']
        );
        $topics[] = documentation_topic(
            'business-unified','Reports','Unified Report','Combine validated source data for one reporting period into a single performance view.',
            ['Open Reports & Downloads.','Choose a reporting period with comparison-ready source data.','Open Unified Report.','A source marked Review Required is held out automatically so unlike periods are not compared.'],
            url('business-history'),'Find Unified Reports',['business'],['unified report','combined report','performance comparison','all sources']
        );
        $topics[] = documentation_topic(
            'business-transfer','Data & Settings','Data Transfer','Export or import portable business reporting data.',
            ['Open Data Transfer.','Export a portable CSV when moving or safeguarding business data.','For import, choose the CSV and review the replace/skip option.','Validate & Import.'],
            url('business-data-transfer'),'Open Data Transfer',['business'],['data transfer','export','import','portable csv']
        );
        $topics[] = documentation_topic(
            'business-settings','Data & Settings','Business Settings','Manage the reporting timezone and business logo.',
            ['Open Settings.','Update the reporting timezone or upload the business logo.','Save the change.'],
            url('business-settings'),'Open Settings',['business'],['settings','timezone','logo']
        );
    }

    // PROVIDER KPI
    if (($providerEnabled && $businessId > 0) || (auth_is_admin() && $businessId > 0)) {
        $topics[] = documentation_topic(
            'provider-overview','Provider KPI','Clinic Overview','See provider performance, goals, opportunities, coaching status, and clinic totals.',
            ['Open Provider KPI.','Choose the reporting month.','Review clinic KPIs and provider rows.','Open a provider for the full scorecard.'],
            url('business-provider-kpi'),'Open Provider KPI',['business'],['provider kpi','clinic overview','scorecard','performance']
        );

        $topics[] = documentation_topic(
            'provider-scorecard','Provider KPI','Provider Scorecard','Review current month, previous month, MoM change, YTD, goals, and goal attainment.',
            ['Open Provider KPI.','Choose a provider.','Review the KPI groups.','Use Opportunity, Coaching, or drill-down links for more detail.'],
            url('business-provider-kpi'),'Open Scorecards',['business'],['provider scorecard','mom','ytd','goal attainment']
        );

        $topics[] = documentation_topic(
            'provider-opportunities','Provider KPI','Opportunity Dashboard','Translate KPI gaps into revenue, capacity, patient, and productivity actions.',
            ['Open a provider scorecard.','Choose Opportunities.','Review revenue needed, open-hour potential, patient needs, and required rates.'],
            url('business-provider-kpi'),'Open Provider KPI',['business'],['opportunity','revenue gap','open hours','patients needed']
        );

        if (auth_is_admin() || provider_kpi_user_role() !== 'provider') {
            $topics[] = documentation_topic(
                'provider-rankings','Provider KPI','Rankings & Trends','Compare providers by production and other available KPIs.',
                ['Open Provider KPI → Rankings.','Choose the month and KPI.','Open a provider scorecard when a result needs deeper review.'],
                url('business-provider-kpi-rankings'),'Open Rankings',['business'],['rankings','trends','compare providers']
            );
        }

        $topics[] = documentation_topic(
            'provider-goals','Provider KPI','Provider Goals','Set monthly KPI goals and compare actual performance against them.',
            ['Open Provider KPI → Goals.','Choose provider and month.','Enter only the goals you want to track.','Save goals or copy the previous month when appropriate.'],
            url('business-provider-kpi-goals'),'Open Goals',['leadership'],['provider goals','targets','goal attainment']
        );
        $topics[] = documentation_topic(
            'provider-import','Provider KPI','Monthly KPI Data Import','Upload monthly provider KPI data through validation before anything is saved.',
            ['Open Provider KPI → Data Import.','Download/use the KPI CSV template.','Choose the month and upload the CSV.','Review validation, then confirm the import.'],
            url('business-provider-kpi-import'),'Open Data Import',['leadership','data_uploader'],['provider import','kpi csv','monthly data','upload']
        );
        $topics[] = documentation_topic(
            'provider-management','Provider KPI','Providers','Add providers, departments, status, and linked provider logins.',
            ['Open Provider KPI → Providers.','Add or edit the provider.','Link a business user only when that user should have Provider access.','Save.'],
            url('business-provider-kpi-providers'),'Manage Providers',['leadership'],['providers','add provider','link provider','inactive']
        );
        $topics[] = documentation_topic(
            'provider-coaching','Provider KPI','Coaching Reviews','Record monthly reviews, wins, risks, opportunities, and action items.',
            ['Open a provider.','Choose Coaching.','Save the review summary and next review date.','Add action items with owner, priority, due date, and status.'],
            url('business-provider-kpi'),'Open Provider KPI',['leadership','provider'],['coaching','review','action item','wins','risks']
        );
        $topics[] = documentation_topic(
            'provider-activity','Provider KPI','Activity & Rollback','Review Provider KPI changes and safely roll back an eligible latest import.',
            ['Open Provider KPI → Activity.','Review the audit history.','Use rollback only when the latest unchanged import must be reversed.'],
            url('business-provider-kpi-activity'),'Open Activity',['leadership'],['activity','rollback','audit','import history']
        );
    }

    return $topics;
}

function documentation_visible_topics(): array {
    $allowed = documentation_user_audiences();
    $topics = [];
    $businessId=(int)(business_context_id()??0);
    foreach (documentation_registry() as $topic) {
        $audiences = (array)($topic['audiences'] ?? ['all']);
        if (!array_intersect($allowed, $audiences)) continue;
        $featureCode=business_feature_documentation_code((string)($topic['id']??''));
        if($businessId>0&&$featureCode!==null&&!business_feature_enabled($businessId,$featureCode))continue;
        $topics[] = $topic;
    }
    return $topics;
}

function documentation_sections(array $topics): array {
    $sections = [];
    foreach ($topics as $topic) $sections[(string)$topic['section']][] = $topic;
    return $sections;
}
