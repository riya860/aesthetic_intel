<?php
$providerNavCurrent=(string)($_GET['page']??'business-provider-kpi');
$providerNavMonth=isset($month)?substr((string)$month,0,7):substr(provider_kpi_default_month((int)business_context_id()),0,7);
$providerNavRole=provider_kpi_user_role();
$providerNavEnabled=provider_kpi_enabled((int)business_context_id());
$providerNavCanManage=provider_kpi_can_manage((int)business_context_id());
$providerNavCanImport=provider_kpi_can_import((int)business_context_id());
?>
<nav class="provider-workspace-nav no-print" aria-label="Provider KPI workspace">
 <a class="<?=$providerNavCurrent==='business-provider-kpi'?'active':''?>" href="<?=url('business-provider-kpi',['month'=>$providerNavMonth])?>">Clinic Overview</a>
 <?php if($providerNavEnabled&&$providerNavRole!=='provider'):?><a class="<?=$providerNavCurrent==='business-provider-kpi-rankings'?'active':''?>" href="<?=url('business-provider-kpi-rankings',['month'=>$providerNavMonth])?>">Rankings</a><?php endif;?>
 <?php if($providerNavEnabled&&$providerNavCanManage):?><a class="<?=in_array($providerNavCurrent,['business-provider-kpi-providers','business-provider-kpi-provider-form'],true)?'active':''?>" href="<?=url('business-provider-kpi-providers')?>">Providers</a><a class="<?=$providerNavCurrent==='business-provider-kpi-goals'?'active':''?>" href="<?=url('business-provider-kpi-goals',['month'=>$providerNavMonth])?>">Goals</a><?php endif;?>
 <?php if($providerNavEnabled&&$providerNavCanImport):?><a class="<?=in_array($providerNavCurrent,['business-provider-kpi-import','business-provider-kpi-import-preview'],true)?'active':''?>" href="<?=url('business-provider-kpi-import',['month'=>$providerNavMonth])?>">Data Import</a><?php endif;?>
 <?php if($providerNavEnabled&&$providerNavCanManage):?><a class="<?=$providerNavCurrent==='business-provider-kpi-activity'?'active':''?>" href="<?=url('business-provider-kpi-activity')?>">Activity</a><?php endif;?>
</nav>
