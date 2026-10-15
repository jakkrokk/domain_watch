<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/new_ui_competitor.php';
function check_competitor(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$run = ['target_sub_texts'=>'["競合B","競合A"]','hit_sub'=>'["競合A"]','hit_sub1'=>0,'hit_sub2'=>1];
check_competitor(nu_competitor_hit($run,'競合A',0) === true, 'Named hit follows its historical target');
check_competitor(nu_competitor_hit($run,'競合B',1) === false, 'Recorded absence is kept');
check_competitor(nu_competitor_hit($run,'競合C',0) === null, 'Unmeasured replacement does not inherit an old slot');
$run['hit_sub'] = null;
check_competitor(nu_competitor_hit($run,'競合A',0) === true && nu_competitor_hit($run,'競合B',1) === false, 'Legacy flags use the historical slot after reordering');
$legacy = ['target_sub_texts'=>null,'target_sub1_text'=>null,'target_sub2_text'=>'競合A','target_sub3_text'=>'競合B','hit_sub'=>null,'hit_sub1'=>0,'hit_sub2'=>1,'hit_sub3'=>0];
check_competitor(nu_competitor_hit($legacy,'競合A',0) === true && nu_competitor_hit($legacy,'競合B',1) === false, 'Legacy target columns preserve gaps in their original slots');
check_competitor(nu_competitor_hit(['hit_sub1'=>1],'競合A',0) === true, 'Pre-snapshot records retain the existing slot fallback');
check_competitor(nu_competitor_hit(['hit_sub1'=>1],'競合A') === null, 'Missing target and slot remain unknown');
check_competitor(nu_competitor_hit($run,'') === null, 'Empty target is never measured');
check_competitor(nu_competitor_hit($run,'<https://example.com|競合A>',0) === true, 'Existing target normalization is retained');
echo "Competitor historical targets, reordered slots, legacy flags, missing data and normalization passed\n";
