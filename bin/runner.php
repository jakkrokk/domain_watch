<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (php_sapi_name() !== 'cli') { exit("cli only\n"); }

$tz = new DateTimeZone('Asia/Tokyo');
$now = new DateTime('now', $tz);
$runAt = $now->format('Y-m-d H:00:00');
$dayKey = $now->format('Y-m-d');
$hour = (int)$now->format('G');
$scheduleHours = runner_schedule_hours();
if (!in_array($hour, $scheduleHours, true) && env_get('RUNNER_IGNORE_SCHEDULE', '') !== '1') {
    echo 'skip hour=' . $hour . ' schedule=' . runner_schedule_label() . "\n";
    exit;
}

$model = env_get('OPENAI_MODEL', 'gpt-4o-mini');

$projects = db_all('SELECT id,name FROM projects WHERE is_active=1 ORDER BY id');

$startAll = microtime(true);
$ran = 0;
$skipped = 0;
$errors = 0;

foreach ($projects as $p) {
    $projectId = (int)$p['id'];

    $domains = db_all('SELECT type,domain FROM project_domains WHERE project_id=?', [$projectId]);
    $main = null;
    $subs = [];
    foreach ($domains as $d) {
        if ($d['type'] === 'main') $main = $d['domain'];
        if ($d['type'] === 'sub') $subs[] = $d['domain'];
    }

    $prompts = db_all('SELECT id,name,content FROM project_prompts WHERE project_id=? AND is_active=1 ORDER BY id', [$projectId]);
    if (!$prompts) {
        $skipped++;
        continue;
    }

    foreach ($prompts as $pr) {
        $promptId = (int)$pr['id'];

        // idempotency: if already ran at this scheduled slot for this prompt, skip
        $exists = db_one('SELECT id FROM runs WHERE project_id=? AND prompt_id=? AND run_at=?', [$projectId, $promptId, $runAt]);
        if ($exists) { $skipped++; continue; }

        $runId = $projectId . '-' . $promptId . '-' . $now->format('YmdH') . '-' . bin2hex(random_bytes(4));

        $t0 = microtime(true);
        try {
            $resp = openai_chat($pr['content'], $runId, $model);
            $text = (string)$resp['text'];

            // Snapshot targets (stable metrics even if user edits settings later)
            $snapMain = $main ? normalize_target_text((string)$main) : null;
            $snapSub1 = isset($subs[0]) ? normalize_target_text((string)$subs[0]) : null;
            $snapSub2 = isset($subs[1]) ? normalize_target_text((string)$subs[1]) : null;
            $snapSub3 = isset($subs[2]) ? normalize_target_text((string)$subs[2]) : null;

            // legacy/compat list
            $snapSubs = [];
            foreach ([$snapSub1, $snapSub2, $snapSub3] as $ns) {
                if ($ns) $snapSubs[] = $ns;
            }

            $hitMain = ($snapMain && text_contains_target($text, $snapMain)) ? 1 : 0;
            $hitSub1 = ($snapSub1 && text_contains_target($text, $snapSub1)) ? 1 : 0;
            $hitSub2 = ($snapSub2 && text_contains_target($text, $snapSub2)) ? 1 : 0;
            $hitSub3 = ($snapSub3 && text_contains_target($text, $snapSub3)) ? 1 : 0;

            // legacy/compat: list of hit sub texts
            $hitSubs = [];
            if ($hitSub1 && $snapSub1) $hitSubs[] = $snapSub1;
            if ($hitSub2 && $snapSub2) $hitSubs[] = $snapSub2;
            if ($hitSub3 && $snapSub3) $hitSubs[] = $snapSub3;

            // daily_logs: overwrite the 1/day slot per prompt so "latest" is always available.
            db_exec(
                'INSERT INTO daily_logs(project_id,prompt_id,day_key,run_at,response_text) VALUES(?,?,?,?,?) '
                . 'ON DUPLICATE KEY UPDATE run_at=VALUES(run_at), response_text=VALUES(response_text)',
                [$projectId, $promptId, $dayKey, $runAt, $text]
            );

            // runs: keep hourly metrics; do NOT store full text here (avoid volume)
            $saveText = null;

            db_exec(
                'INSERT INTO runs(project_id,prompt_id,run_at,day_key,hour,target_main_text,target_sub1_text,target_sub2_text,target_sub3_text,target_sub_texts,hit_main,hit_sub1,hit_sub2,hit_sub3,hit_sub,response_text,model,tokens_in,tokens_out,latency_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $projectId,
                    $promptId,
                    $runAt,
                    $dayKey,
                    $hour,
                    $snapMain,
                    $snapSub1,
                    $snapSub2,
                    $snapSub3,
                    $snapSubs ? json_encode($snapSubs, JSON_UNESCAPED_UNICODE) : null,
                    $hitMain,
                    $hitSub1,
                    $hitSub2,
                    $hitSub3,
                    $hitSubs ? json_encode($hitSubs, JSON_UNESCAPED_UNICODE) : null,
                    $saveText,
                    $resp['model'],
                    $resp['tokens_in'],
                    $resp['tokens_out'],
                    $resp['latency_ms'],
                ]
            );

            $ran++;
            $ms = (int)round((microtime(true)-$t0)*1000);
            echo "[ok] project={$projectId} prompt={$promptId} hour={$hour} ms={$ms} main={$hitMain} subHits=" . count($hitSubs) . "\n";
        } catch (Throwable $e) {
            $errors++;
            $ms = (int)round((microtime(true)-$t0)*1000);
            // record a run row without response_text, but keep failure visible by not inserting (simple). 
            // For production, make runs.status and store error.
            fwrite(STDERR, "[err] project={$projectId} prompt={$promptId} hour={$hour} ms={$ms} msg=" . $e->getMessage() . "\n");
        }

        // polite spacing to avoid rate limits
        usleep(200000); // 200ms
    }
}

$msAll = (int)round((microtime(true)-$startAll)*1000);
echo "done ran={$ran} skipped={$skipped} errors={$errors} total_ms={$msAll}\n";
