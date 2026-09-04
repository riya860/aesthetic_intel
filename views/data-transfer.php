<?php $params=[]; ?>
<div class="page-head">
  <div>
    <span class="eyebrow"><?=e($business['name'])?></span>
    <h1>Business Data Transfer</h1>
    <p>Export all saved reporting numbers, then import them into another Aesthetic Intel business.</p>
  </div>
  <a class="btn btn-primary" href="<?=url('business-data-export',$params)?>">Export Business Data</a>
</div>

<div class="settings-grid">
  <section class="panel">
    <h2>Export current data</h2>
    <p>Downloads one portable CSV containing all saved reporting periods for every supported tool.</p>
    <dl class="detail-list">
      <div><dt>Boulevard reports</dt><dd><?=numfmt($stats['boulevard'])?></dd></div>
      <div><dt>GBP entries</dt><dd><?=numfmt($stats['gbp'])?></dd></div>
      <div><dt>Podium entries</dt><dd><?=numfmt($stats['podium'])?></dd></div>
      <div><dt>Growth99+ entries</dt><dd><?=numfmt($stats['growth99'])?></dd></div>
      <div><dt>GA4 entries</dt><dd><?=numfmt($stats['ga4'])?></dd></div>
    </dl>
    <a class="btn btn-primary" href="<?=url('business-data-export',$params)?>">Download Portable CSV</a>
    <p class="muted">The package includes calculated metrics, charts, provider detail, insights, reporting dates, frequency, timezone, and source availability. It excludes users, passwords, API keys, raw uploads, and the business logo.</p>
  </section>

  <section class="panel">
    <h2>Import business data</h2>
    <p>Use an unedited CSV exported by Aesthetic Intel. The target business name, users, login credentials, API settings, and logo are never overwritten.</p>
    <form method="post" enctype="multipart/form-data" class="stack-form">
      <?=csrf_field()?>
      <label>Portable business-data CSV
        <span>Maximum 25 MB. Do not open and resave the CSV before importing.</span>
        <input type="file" name="business_data_csv" accept=".csv,text/csv" required>
      </label>
      <label>When the same reporting period already exists
        <select name="import_mode" required>
          <option value="replace_matching">Replace the matching period with imported data</option>
          <option value="skip_existing">Keep existing data and skip matching periods</option>
        </select>
      </label>
      <label class="check-row"><input type="checkbox" name="apply_business_settings" value="1" checked> Apply the source business timezone and report colors</label>
      <div class="alert alert-warning">For a newly created empty business, importing this file recreates the complete report history. Every import runs inside one database transaction; if any record fails validation, nothing is imported.</div>
      <button class="btn btn-primary" type="submit">Validate & Import Data</button>
    </form>
  </section>
</div>

<section class="panel">
  <h2>Data integrity protections</h2>
  <div class="data-integrity-grid">
    <article><strong>Integrity checksum</strong><p>The complete CSV is checked before any database change begins.</p></article>
    <article><strong>Exact period mapping</strong><p>Dates, frequency, source, provider data, comparisons, and calculations are preserved.</p></article>
    <article><strong>Atomic import</strong><p>A failed record rolls back the entire import, preventing partial or mixed data.</p></article>
    <article><strong>Business isolation</strong><p>Every imported record is assigned only to the currently selected business.</p></article>
  </div>
</section>
