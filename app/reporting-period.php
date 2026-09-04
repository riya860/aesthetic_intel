<?php
declare(strict_types=1);

function reporting_frequencies(): array { return ['weekly','monthly','quarterly','yearly','custom']; }

function reporting_business_today(string $timezone): string {
    try { return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d'); }
    catch(Throwable) { return gmdate('Y-m-d'); }
}

function reporting_subtract_months(DateTimeImmutable $date,int $months): DateTimeImmutable {
    $targetMonth=(int)$date->format('n')-$months;
    $targetYear=(int)$date->format('Y');
    while($targetMonth<1){$targetMonth+=12;$targetYear--;}
    while($targetMonth>12){$targetMonth-=12;$targetYear++;}
    $day=min((int)$date->format('j'),cal_days_in_month(CAL_GREGORIAN,$targetMonth,$targetYear));
    return $date->setDate($targetYear,$targetMonth,$day);
}

function reporting_subtract_year(DateTimeImmutable $date): DateTimeImmutable {
    $year=(int)$date->format('Y')-1;
    $month=(int)$date->format('n');
    $day=min((int)$date->format('j'),cal_days_in_month(CAL_GREGORIAN,$month,$year));
    return $date->setDate($year,$month,$day);
}

function reporting_period_bounds(string $frequency,string $periodEnd,string $timezone='UTC'): array {
    if(!in_array($frequency,reporting_frequencies(),true)) throw new RuntimeException('Choose a valid reporting frequency.');
    try {$tz=new DateTimeZone($timezone);}catch(Throwable){$tz=new DateTimeZone('UTC');}
    $end=DateTimeImmutable::createFromFormat('!Y-m-d',$periodEnd,$tz);
    if(!$end||$end->format('Y-m-d')!==$periodEnd) throw new RuntimeException('Choose a valid period end date.');
    $start=match($frequency){
        'weekly'=>$end->modify('-7 days'),
        'monthly'=>$end->modify('first day of this month'),
        'quarterly'=>reporting_subtract_months($end,3),
        'yearly'=>reporting_subtract_year($end),
        default=>$end,
    };
    return [$start->format('Y-m-d'),$end->format('Y-m-d')];
}

function reporting_normalize_period(string $frequency,string $periodStart,string $periodEnd,string $timezone='UTC'): array {
    if(!in_array($frequency,reporting_frequencies(),true)) throw new RuntimeException('Choose a valid reporting frequency.');
    if($frequency!=='custom') return reporting_period_bounds($frequency,$periodEnd,$timezone);
    try {$tz=new DateTimeZone($timezone);}catch(Throwable){$tz=new DateTimeZone('UTC');}
    $start=DateTimeImmutable::createFromFormat('!Y-m-d',$periodStart,$tz);
    $end=DateTimeImmutable::createFromFormat('!Y-m-d',$periodEnd,$tz);
    if(!$start||!$end||$start->format('Y-m-d')!==$periodStart||$end->format('Y-m-d')!==$periodEnd||$start>$end) throw new RuntimeException('Choose a valid custom reporting period.');
    return [$periodStart,$periodEnd];
}

function reporting_us_date(?string $date,bool $withTime=false): string {
    if(!$date)return '';
    $ts=strtotime($date);
    if($ts===false)return (string)$date;
    return date($withTime?'m-d-Y g:i A':'m-d-Y',$ts);
}
?>