<?php
declare(strict_types=1);
/**
 * Fleet Management Dashboard — consolidated KPIs across assets, maintenance,
 * fuel, incidents and instructors, with lifecycle/replacement and renewal flags.
 * Read-only analytics over existing transport data.
 */
require_once __DIR__ . '/includes/transport.php';
require_once __DIR__ . '/includes/teveta_helpers.php';

$page_title = 'Fleet Management Dashboard';

function fd_scalar(mysqli $db, string $sql): float
{
    $r = @$db->query($sql);
    if (!$r) { return 0.0; }
    $row = $r->fetch_row();
    $r->free();
    return isset($row[0]) ? (float)$row[0] : 0.0;
}
function fd_rows(mysqli $db, string $sql): array
{
    $out = []; $r = @$db->query($sql);
    if ($r) { while ($x = $r->fetch_assoc()) { $out[] = $x; } $r->free(); }
    return $out;
}

// ---- Headline KPIs ----
$totalVehicles   = (int)fd_scalar($db, "SELECT COUNT(*) FROM transport_vehicles");
$available       = (int)fd_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE status='available'");
$inMaintenance   = (int)fd_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE status IN ('maintenance','unavailable')");
$availabilityPct = $totalVehicles > 0 ? round($available / $totalVehicles * 100) : 0;

$serviceDue   = (int)fd_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE next_service_due IS NOT NULL AND next_service_due <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$serviceOver  = (int)fd_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE next_service_due IS NOT NULL AND next_service_due < CURDATE()");
$docExpiring  = (int)fd_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE (fitness_expiry IS NOT NULL AND fitness_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) OR (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
$replaceFlag  = (int)fd_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE replacement_mileage IS NOT NULL AND current_mileage IS NOT NULL AND current_mileage >= replacement_mileage");

$sessionsMonth = (int)fd_scalar($db, "SELECT COUNT(*) FROM transport_sessions WHERE session_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') AND status<>'cancelled'");
$openIncidents = (int)fd_scalar($db, "SELECT COUNT(*) FROM transport_incident_reports WHERE COALESCE(LOWER(status),'open') NOT IN ('closed','resolved')");

$fuelSpend90  = fd_scalar($db, "SELECT COALESCE(SUM(total_cost),0) FROM transport_fuel_logs WHERE fuel_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
$maintSpend90 = fd_scalar($db, "SELECT COALESCE(SUM(cost),0) FROM transport_maintenance_logs WHERE service_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)");

// ---- Vehicles needing attention ----
$attention = fd_rows($db, "
    SELECT v.registration_no, v.vehicle_type, v.status, v.current_mileage, v.replacement_mileage,
           v.next_service_due, v.fitness_expiry, v.insurance_expiry, ca.campus_name
    FROM transport_vehicles v
    LEFT JOIN transport_campuses ca ON ca.id = v.campus_id
    WHERE (v.next_service_due IS NOT NULL AND v.next_service_due <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
       OR (v.fitness_expiry IS NOT NULL AND v.fitness_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
       OR (v.insurance_expiry IS NOT NULL AND v.insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
       OR (v.replacement_mileage IS NOT NULL AND v.current_mileage >= v.replacement_mileage)
    ORDER BY v.next_service_due IS NULL, v.next_service_due
    LIMIT 25
");

// ---- Fuel efficiency per vehicle (last 90 days) ----
$fuelRaw = fd_rows($db, "
    SELECT v.registration_no,
           COUNT(f.id) AS fills,
           MAX(f.odometer) - MIN(f.odometer) AS km_span,
           SUM(f.amount_added) AS litres,
           SUM(f.total_cost) AS cost
    FROM transport_vehicles v
    INNER JOIN transport_fuel_logs f ON f.vehicle_id = v.id
    WHERE f.fuel_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    GROUP BY v.id, v.registration_no
    HAVING fills >= 2 AND litres > 0
    ORDER BY v.registration_no
    LIMIT 20
");
$fuelEff = [];
foreach ($fuelRaw as $f) {
    $km = (float)$f['km_span']; $l = (float)$f['litres'];
    $f['kmpl'] = ($km > 0 && $l > 0) ? round($km / $l, 1) : null;
    $f['cost_per_km'] = ($km > 0 && (float)$f['cost'] > 0) ? round((float)$f['cost'] / $km, 2) : null;
    $fuelEff[] = $f;
}

// ---- Instructor / driver scorecards ----
$scorecards = fd_rows($db, "
    SELECT i.full_name, i.status,
           (SELECT COUNT(*) FROM transport_sessions s WHERE s.instructor_id=i.id AND s.status<>'cancelled') AS sessions,
           (SELECT COALESCE(SUM(s.contact_hours),0) FROM transport_sessions s WHERE s.instructor_id=i.id AND s.status='completed') AS hours,
           (SELECT COUNT(*) FROM transport_incident_reports ir WHERE ir.instructor_id=i.id) AS incidents
    FROM transport_instructors i
    ORDER BY incidents DESC, sessions DESC
    LIMIT 15
");

// ---- Incident trend (6 months) ----
$incTrend = fd_rows($db, "
    SELECT DATE_FORMAT(incident_time,'%Y-%m') AS ym, COUNT(*) AS n
    FROM transport_incident_reports
    WHERE incident_time >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym
");
$maxInc = 0; foreach ($incTrend as $t) { $maxInc = max($maxInc, (int)$t['n']); }

function fd_daybadge(?string $d): string
{
    if (!$d) { return '<span class="text-muted">—</span>'; }
    $days = (int)((strtotime($d) - strtotime('today')) / 86400);
    if ($days < 0) { return '<span class="badge bg-danger">Overdue ' . tev_h($d) . '</span>'; }
    if ($days <= 30) { return '<span class="badge bg-warning text-dark">' . tev_h($d) . '</span>'; }
    return '<span class="badge bg-success">' . tev_h($d) . '</span>';
}

require_once __DIR__ . '/includes/nav.php';
?>
<div class="container-fluid py-3">
    <h3 class="mb-1"><i class="fas fa-gauge-high me-2"></i>Fleet Management Dashboard</h3>
    <p class="text-muted">Assets, maintenance, fuel, incidents and instructor performance at a glance.</p>

    <div class="row g-3 mb-2">
        <?php
        $kpis = [
            ['Fleet size', $totalVehicles, 'fa-truck', 'text-primary'],
            ['Available', $available . ' (' . $availabilityPct . '%)', 'fa-circle-check', $availabilityPct>=70?'text-success':'text-warning'],
            ['In maintenance', $inMaintenance, 'fa-screwdriver-wrench', $inMaintenance>0?'text-warning':'text-muted'],
            ['Service due (30d)', $serviceDue . ($serviceOver>0?' · '.$serviceOver.' overdue':''), 'fa-oil-can', $serviceOver>0?'text-danger':($serviceDue>0?'text-warning':'text-success')],
            ['Docs expiring', $docExpiring, 'fa-file-shield', $docExpiring>0?'text-warning':'text-success'],
            ['Replace flagged', $replaceFlag, 'fa-recycle', $replaceFlag>0?'text-danger':'text-muted'],
            ['Sessions (month)', $sessionsMonth, 'fa-calendar-check', 'text-primary'],
            ['Open incidents', $openIncidents, 'fa-triangle-exclamation', $openIncidents>0?'text-danger':'text-success'],
        ];
        foreach ($kpis as $k): ?>
            <div class="col-6 col-md-3">
                <div class="card h-100"><div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div><div class="text-muted small"><?php echo tev_h($k[0]); ?></div><div class="fs-5 fw-bold <?php echo $k[3]; ?>"><?php echo tev_h($k[1]); ?></div></div>
                        <i class="fas <?php echo $k[2]; ?> fa-lg <?php echo $k[3]; ?> opacity-75"></i>
                    </div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="card"><div class="card-body d-flex justify-content-between align-items-center">
            <div><div class="text-muted small">Fuel spend (90 days)</div><div class="fs-4 fw-bold"><?php echo 'ZMW ' . number_format($fuelSpend90, 2); ?></div></div>
            <i class="fas fa-gas-pump fa-2x text-muted opacity-50"></i>
        </div></div></div>
        <div class="col-md-6"><div class="card"><div class="card-body d-flex justify-content-between align-items-center">
            <div><div class="text-muted small">Maintenance spend (90 days)</div><div class="fs-4 fw-bold"><?php echo 'ZMW ' . number_format($maintSpend90, 2); ?></div></div>
            <i class="fas fa-screwdriver-wrench fa-2x text-muted opacity-50"></i>
        </div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header"><strong><i class="fas fa-triangle-exclamation me-2"></i>Vehicles needing attention</strong></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Vehicle</th><th>Status</th><th>Service due</th><th>Fitness</th><th>Insurance</th><th>Mileage</th></tr></thead><tbody>
                        <?php if (!$attention): ?><tr><td colspan="6" class="text-muted text-center py-3">No vehicles need attention. 🎉</td></tr>
                        <?php else: foreach ($attention as $v):
                            $replace = $v['replacement_mileage'] && (int)$v['current_mileage'] >= (int)$v['replacement_mileage']; ?>
                            <tr>
                                <td><?php echo tev_h($v['registration_no']); ?><div class="text-muted small"><?php echo tev_h($v['campus_name']); ?></div></td>
                                <td class="text-capitalize"><?php echo tev_h($v['status']); ?></td>
                                <td><?php echo fd_daybadge($v['next_service_due']); ?></td>
                                <td><?php echo fd_daybadge($v['fitness_expiry']); ?></td>
                                <td><?php echo fd_daybadge($v['insurance_expiry']); ?></td>
                                <td><?php echo number_format((float)$v['current_mileage']); ?><?php echo $replace?' <span class="badge bg-danger">Replace</span>':''; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody></table></div></div>
            </div>

            <div class="card">
                <div class="card-header"><strong><i class="fas fa-gas-pump me-2"></i>Fuel efficiency (90 days)</strong></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Vehicle</th><th>Fills</th><th>Distance</th><th>Litres</th><th>km/L</th><th>Cost/km</th></tr></thead><tbody>
                        <?php if (!$fuelEff): ?><tr><td colspan="6" class="text-muted text-center py-3">Not enough fuel logs yet (need ≥2 fills with odometer per vehicle).</td></tr>
                        <?php else: foreach ($fuelEff as $f): ?>
                            <tr>
                                <td><?php echo tev_h($f['registration_no']); ?></td>
                                <td><?php echo (int)$f['fills']; ?></td>
                                <td><?php echo number_format((float)$f['km_span']); ?> km</td>
                                <td><?php echo number_format((float)$f['litres'],1); ?></td>
                                <td><?php echo $f['kmpl']!==null?'<strong>'.tev_h($f['kmpl']).'</strong>':'—'; ?></td>
                                <td><?php echo $f['cost_per_km']!==null?'ZMW '.tev_h($f['cost_per_km']):'—'; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody></table></div></div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header"><strong><i class="fas fa-chart-column me-2"></i>Incident trend (6 months)</strong></div>
                <div class="card-body">
                    <?php if (!$incTrend): ?><p class="text-muted text-center py-3 mb-0">No incidents recorded. 🎉</p>
                    <?php else: foreach ($incTrend as $t): $pct = $maxInc>0?round((int)$t['n']/$maxInc*100):0; ?>
                        <div class="d-flex align-items-center mb-2">
                            <div class="small text-muted" style="width:64px;"><?php echo tev_h($t['ym']); ?></div>
                            <div class="flex-grow-1 bg-light rounded" style="height:18px;"><div style="height:18px;width:<?php echo $pct; ?>%;background:#1B2A4A;border-radius:4px;"></div></div>
                            <div class="small fw-bold ms-2" style="width:28px;"><?php echo (int)$t['n']; ?></div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong><i class="fas fa-user-shield me-2"></i>Instructor / driver scorecards</strong></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Instructor</th><th>Sessions</th><th>Hours</th><th>Incidents</th></tr></thead><tbody>
                        <?php if (!$scorecards): ?><tr><td colspan="4" class="text-muted text-center py-3">No instructors yet.</td></tr>
                        <?php else: foreach ($scorecards as $s): ?>
                            <tr>
                                <td><?php echo tev_h($s['full_name']); ?></td>
                                <td><?php echo (int)$s['sessions']; ?></td>
                                <td><?php echo number_format((float)$s['hours'],1); ?></td>
                                <td><?php echo (int)$s['incidents']>0?'<span class="badge bg-danger">'.(int)$s['incidents'].'</span>':'<span class="badge bg-success">0</span>'; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody></table></div></div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
