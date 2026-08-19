<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/_layout.php';

$u = require_admin();

$period = moneybird_valid_period((string)($_GET['period'] ?? 'this_year'));
$refresh = ($_GET['refresh'] ?? '') === '1';

$configured = moneybird_configured();
$enabled = moneybird_enabled();
$result = ['ok' => false, 'invoices' => [], 'error' => '', 'stale' => false, 'truncated' => false];

if ($configured && $enabled) {
    $result = moneybird_sales_invoices($period, $refresh);
}

$invoices = $result['invoices'];
$summary = moneybird_revenue_summary($invoices);
$currency = $summary['currency'];

usort($invoices, static function (array $a, array $b): int {
    return strcmp((string)($b['invoice_date'] ?? ''), (string)($a['invoice_date'] ?? ''));
});

$maxMonth = 0.0;
foreach ($summary['months'] as $month) $maxMonth = max($maxMonth, (float)$month['excl']);

$periods = ['this_month', 'prev_month', 'this_quarter', 'prev_quarter', 'this_year', 'prev_year'];

admin_head($u, 'Revenue', 'revenue');
?>
<style>
.rev-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
.rev-periods{display:flex;flex-wrap:wrap;gap:6px}
.rev-periods a{padding:8px 12px;border:1px solid #2a3038;border-radius:9px;color:#919ba8;text-decoration:none;font-size:11px;white-space:nowrap}
.rev-periods a:hover{background:#252a31;color:#fff}
.rev-periods a.on{border-color:#ff7417;background:rgba(255,116,23,.12);color:#ffb37d}
.rev-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-bottom:16px}
.rev-kpi{padding:16px;border:1px solid #2a3038;border-radius:12px;background:#14181d}
.rev-kpi small{display:block;color:#788390;font-size:9px;text-transform:uppercase;letter-spacing:.09em}
.rev-kpi b{display:block;margin-top:8px;font-size:21px;letter-spacing:-.02em}
.rev-kpi span{display:block;margin-top:5px;color:#6f7a87;font-size:10px}
.rev-kpi.is-paid b{color:#43d990}
.rev-kpi.is-open b{color:#ffb96c}
.rev-kpi.is-late b{color:#ff7c86}
.rev-months{display:flex;align-items:flex-end;gap:7px;min-height:150px;padding:16px 14px;border:1px solid #2a3038;border-radius:12px;background:#14181d;overflow-x:auto}
.rev-month{display:flex;flex:1 0 42px;flex-direction:column;align-items:center;gap:7px}
.rev-month-bar{width:100%;min-height:3px;border-radius:5px 5px 0 0;background:linear-gradient(180deg,#ff9a4d,#ff7417)}
.rev-month small{color:#788390;font-size:9px;white-space:nowrap}
.rev-month b{color:#c8d1dc;font-size:9px;font-weight:600}
.rev-states{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.rev-state{padding:6px 10px;border:1px solid #2a3038;border-radius:999px;color:#9aa5b3;font-size:10px}
.rev-empty{padding:44px 20px;text-align:center;color:#818b98}
.rev-empty b{display:block;margin-bottom:7px;color:#dfe6ee;font-size:15px}
@media(max-width:1000px){.rev-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.rev-kpis{grid-template-columns:1fr}}
</style>

<?php if (!$configured): ?>
    <div class="error">
        Moneybird is not configured yet. Add an API token and administration ID in
        <a href="/admin/settings-connections.php">Settings → Connections</a>, then use “Save &amp; test Moneybird”.
    </div>
<?php elseif (!$enabled): ?>
    <div class="notice">
        Moneybird is configured but disabled. Enable it in <a href="/admin/settings-connections.php">Settings → Connections</a> to load revenue.
    </div>
<?php elseif (!$result['ok']): ?>
    <div class="error">Moneybird could not be reached: <?=e($result['error'])?></div>
<?php endif ?>

<?php if ($result['stale']): ?>
    <div class="notice">Showing the last successfully cached figures because Moneybird did not respond just now.</div>
<?php endif ?>
<?php if ($result['truncated']): ?>
    <div class="notice">Only the most recent <?=e((string)(MONEYBIRD_MAX_PAGES * MONEYBIRD_PER_PAGE))?> invoices were loaded for this period.</div>
<?php endif ?>

<div class="rev-toolbar">
    <div class="rev-periods">
        <?php foreach ($periods as $option): ?>
            <a class="<?=$option === $period ? 'on' : ''?>" href="/admin/revenue.php?period=<?=e($option)?>"><?=e(moneybird_period_label($option))?></a>
        <?php endforeach ?>
    </div>
    <div class="buttons">
        <a class="btn" href="/admin/revenue.php?period=<?=e($period)?>&amp;refresh=1">Refresh from Moneybird</a>
        <a class="btn" href="/admin/billing.php">Portal invoices</a>
    </div>
</div>

<section class="rev-kpis" aria-label="Revenue summary">
    <article class="rev-kpi is-paid">
        <small>Paid revenue</small>
        <b><?=e(moneybird_money((float)$summary['paid'], $currency))?></b>
        <span><?=e(moneybird_period_label($period))?> · incl. VAT received</span>
    </article>
    <article class="rev-kpi">
        <small>Invoiced excl. VAT</small>
        <b><?=e(moneybird_money((float)$summary['invoiced_excl'], $currency))?></b>
        <span><?=e((string)$summary['count'])?> issued invoice<?=$summary['count'] === 1 ? '' : 's'?></span>
    </article>
    <article class="rev-kpi is-open">
        <small>Outstanding</small>
        <b><?=e(moneybird_money((float)$summary['outstanding'], $currency))?></b>
        <span>Awaiting payment</span>
    </article>
    <article class="rev-kpi is-late">
        <small>Overdue</small>
        <b><?=e(moneybird_money((float)$summary['overdue'], $currency))?></b>
        <span><?=e((string)($summary['states']['late'] ?? 0))?> late invoice<?=(int)($summary['states']['late'] ?? 0) === 1 ? '' : 's'?></span>
    </article>
</section>

<?php if ($summary['months']): ?>
<section class="card" style="margin-bottom:16px">
    <div class="cardhead"><b>REVENUE BY MONTH</b><span class="muted">Excl. VAT · <?=e($currency)?></span></div>
    <div style="padding:14px">
        <div class="rev-months">
            <?php foreach ($summary['months'] as $monthKey => $month): ?>
                <?php $height = $maxMonth > 0 ? max(3, (int)round(((float)$month['excl'] / $maxMonth) * 105)) : 3; ?>
                <div class="rev-month" title="<?=e($monthKey.': '.moneybird_money((float)$month['excl'], $currency))?>">
                    <b><?=e(number_format((float)$month['excl'], 0, ',', '.'))?></b>
                    <div class="rev-month-bar" style="height:<?=e((string)$height)?>px"></div>
                    <small><?=e(date('M y', strtotime($monthKey.'-01')))?></small>
                </div>
            <?php endforeach ?>
        </div>
        <div class="rev-states">
            <?php foreach ($summary['states'] as $state => $count): ?>
                <span class="rev-state"><?=e(moneybird_state_label((string)$state))?>: <?=e((string)$count)?></span>
            <?php endforeach ?>
            <?php if ($summary['draft_count'] > 0): ?>
                <span class="rev-state">Draft value (excluded): <?=e(moneybird_money((float)$summary['draft_incl'], $currency))?></span>
            <?php endif ?>
        </div>
    </div>
</section>
<?php endif ?>

<section class="card">
    <div class="cardhead">
        <b>MONEYBIRD SALES INVOICES</b>
        <span class="muted"><?=e((string)count($invoices))?> in <?=e(strtolower(moneybird_period_label($period)))?></span>
    </div>
    <?php if (!$invoices): ?>
        <div class="rev-empty">
            <b>No sales invoices for this period</b>
            <?php if ($configured && $enabled && $result['ok']): ?>
                Pick another period, or create invoices in Moneybird.
            <?php else: ?>
                Connect Moneybird to load revenue figures.
            <?php endif ?>
        </div>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Contact</th>
                        <th>Date</th>
                        <th>Due</th>
                        <th>Excl. VAT</th>
                        <th>Incl. VAT</th>
                        <th>Paid</th>
                        <th>State</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                        $state = strtolower(trim((string)($invoice['state'] ?? '')));
                        $invoiceCurrency = strtoupper(trim((string)($invoice['currency'] ?? $currency))) ?: $currency;
                        $number = trim((string)($invoice['invoice_id'] ?? '')) ?: ('Draft #'.(string)($invoice['id'] ?? ''));
                        $link = trim((string)($invoice['invoice_url'] ?? ''));
                        ?>
                        <tr>
                            <td>
                                <?php if ($link !== '' && preg_match('~^https://~i', $link)): ?>
                                    <a href="<?=e($link)?>" target="_blank" rel="noopener noreferrer"><?=e($number)?></a>
                                <?php else: ?>
                                    <?=e($number)?>
                                <?php endif ?>
                            </td>
                            <td><?=e(moneybird_invoice_contact_name($invoice))?></td>
                            <td><?=e((string)($invoice['invoice_date'] ?? '—'))?></td>
                            <td><?=e((string)($invoice['due_date'] ?? '—'))?></td>
                            <td><?=e(moneybird_money((float)($invoice['total_price_excl_tax'] ?? 0), $invoiceCurrency))?></td>
                            <td><?=e(moneybird_money((float)($invoice['total_price_incl_tax'] ?? 0), $invoiceCurrency))?></td>
                            <td><?=e(moneybird_money((float)($invoice['total_paid'] ?? 0), $invoiceCurrency))?></td>
                            <td><span class="admin-badge status-<?=e($state)?>"><?=e(strtoupper(moneybird_state_label($state)))?></span></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
<?php admin_foot(); ?>
