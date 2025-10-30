<?php
session_start();
/**
 * guardian_agent.php — Self-healing supervisor + chat console for language_api.php
 *
 * What this file does
 *  - Runs structural/accuracy tests against language_api.php
 *  - When tests fail OR when you explicitly ask, it asks Gemini 2.5 Pro to produce a minimal unified diff
 *  - Can apply the diff safely (backup → dry-run → smoke-test → commit or revert)
 *  - NEW: Chat endpoints (action=chat, chat_patch, apply_last) that keep context in the same PHP session
 *
 * Hostinger-friendly: no shell_exec/exec, cURL only.
 */

// --------------------- CONFIG ---------------------
const TARGET_FILE = __DIR__ . '/language_api.php';
const BACKUP_DIR  = __DIR__ . '/guardian_backups';
const PATCH_DIR   = __DIR__ . '/guardian_patches';
const LOG_FILE    = __DIR__ . '/guardian_debug.log';
// IMPORTANT: set this to your public URL of language_api.php
const BASE_URL    = 'https://YOUR_DOMAIN/PATH/TO/language_api.php';

// Only allow patching of these basenames (security)
const ALLOW_PATCH = [ 'language_api.php' ];

// Model names
const GEMINI_MODEL             = 'gemini-2.5-pro';
const GEMINI_MODEL_FALLBACK    = 'gemini-2.5-pro-preview-05-06';

// --------------------- BOOT -----------------------
@ini_set('display_errors','0');
@ini_set('log_errors','1');
@ini_set('error_log', LOG_FILE);
header('Content-Type: application/json; charset=UTF-8');

foreach ([BACKUP_DIR, PATCH_DIR] as $d){ if (!is_dir($d)) @mkdir($d, 0755, true); }

register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    guardian_log('FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
  }
});

function guardian_log($msg){
  if (!is_string($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
  file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

function read_api_key(){
  $gemini = '';
  ob_start();
  $ret = @include __DIR__ . '/api_key.php';
  ob_end_clean();
  if (!$gemini && is_array($ret)) { $gemini = $ret['gemini'] ?? $ret['geminiApiKey'] ?? ''; }
  if (!$gemini && getenv('GEMINI_API_KEY')) $gemini = getenv('GEMINI_API_KEY');
  if (!$gemini) guardian_log('Guardian: no Gemini API key found');
  return $gemini;
}

// ---------------- Gemini call ----------------
function gemini_call_25(array $messages, $max_tokens=1400, $temperature=0.1){
  $apiKey = read_api_key();
  if (!$apiKey) return ['status'=>'error','error'=>'no_api_key'];

  $contents = [];
  foreach ($messages as $m){
    $role  = ($m['role']==='assistant') ? 'model' : 'user';
    $parts = isset($m['parts']) ? $m['parts'] : [['text'=>$m['content']]];
    $contents[] = ['role'=>$role,'parts'=>$parts];
  }

  $model = GEMINI_MODEL;
  $valid = [GEMINI_MODEL, GEMINI_MODEL_FALLBACK, 'gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-pro-latest'];
  if (!in_array($model, $valid, true)) $model = GEMINI_MODEL_FALLBACK;

  $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($apiKey);
  $payload = [
    'contents' => $contents,
    'generationConfig' => [ 'temperature'=>$temperature, 'maxOutputTokens'=>$max_tokens, 'stopSequences'=>[] ]
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => 1,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload)
  ]);
  $response = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($err)  return ['status'=>'error','error'=>'curl','details'=>$err];
  if ($http !== 200) return ['status'=>'error','error'=>'http','code'=>$http,'details'=>$response];
  $j = json_decode($response, true);
  if (!$j) return ['status'=>'error','error'=>'json','details'=>$response];
  $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
  if (!$text) return ['status'=>'error','error'=>'no_text','details'=>$j];
  return ['status'=>'success','content'=>$text];
}

// ---------------- Backend caller ----------------
function call_backend($prompt, $timeout=20){
  $payload = json_encode(['action'=>'analyze','prompt'=>$prompt,'recaptcha'=>''], JSON_UNESCAPED_UNICODE);
  $ch = curl_init(BASE_URL);
  curl_setopt_array($ch, [
    CURLOPT_POST => 1,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => $timeout
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$http, $err, $resp];
}

// ---------------- Tests ----------------
function count_token_words($s){ preg_match_all('/[A-Za-z]+|\d+/', $s, $m); return count($m[0] ?? []); }

function run_tests(){
  $tests = [
    [
      'name'    => 'youu clarifier (no auto-correct)',
      'prompt'  => 'Where are youu right now?',
      'assert'  => function($json){
        if (($json['status'] ?? '') !== 'ok') return 'status != ok';
        $defs = $json['ava']['definitions'] ?? [];
        if (!is_array($defs)) return 'definitions not array';
        $hasYouuUnknown = false;
        foreach ($defs as $d){ if (($d['token']??'')==='youu' && strtolower($d['gloss']??'')==='unknown'){ $hasYouuUnknown = true; break; } }
        if (!$hasYouuUnknown) return '"youu" must be gloss=unknown';
        $mode = $json['gala']['meta']['mode'] ?? '';
        if ($mode !== 'clarify' && $mode !== 'echo') return 'meta.mode should be clarify/echo';
        return true;
      }
    ],
    [
      'name'    => 'compute add twenty three and fifty nine',
      'prompt'  => 'Add twenty three and fifty nine',
      'assert'  => function($json){
        if (($json['status'] ?? '') !== 'ok') return 'status != ok';
        $mr = $json['ava']['mr'] ?? [];
        if (($mr['intent'] ?? '') !== 'compute') return 'intent != compute';
        $res = $json['gala']['result'] ?? '';
        if (!preg_match('/\b23\s*\+\s*59\s*=\s*82\b/', $res)) return 'wrong compute result';
        return true;
      }
    ],
    [
      'name'    => 'capital of typo → clarify',
      'prompt'  => 'What is the capital of Francee?',
      'assert'  => function($json){
        if (($json['status'] ?? '') !== 'ok') return 'status != ok';
        $mode = $json['gala']['meta']['mode'] ?? '';
        if ($mode !== 'clarify') return 'meta.mode must be clarify on unknown proper noun';
        return true;
      }
    ],
    [
      'name'    => 'define echoes AVA glosses only',
      'prompt'  => 'define apple',
      'assert'  => function($json){
        if (($json['status'] ?? '') !== 'ok') return 'status != ok';
        $mode = $json['gala']['meta']['mode'] ?? '';
        if ($mode !== 'define') return 'meta.mode != define';
        $res = $json['gala']['result'] ?? '';
        if (!preg_match('/apple/i', $res)) return 'definition missing target token';
        return true;
      }
    ],
  ];

  $results = [];
  foreach ($tests as $t){
    [$http, $err, $body] = call_backend($t['prompt']);
    $ok = ($err==='') && ($http===200);
    $parsed = $ok ? json_decode($body, true) : null;
    $assert = $ok && is_array($parsed) ? $t['assert']($parsed) : 'HTTP/parse error';
    $pass = ($assert === true);
    $results[] = [
      'name'   => $t['name'],
      'prompt' => $t['prompt'],
      'pass'   => $pass,
      'reason' => $pass ? '' : (is_string($assert) ? $assert : 'failed'),
      'http'   => $http,
      'raw'    => $ok ? null : $body,
    ];
  }
  return $results;
}

// ---------------- Diff helpers ----------------
function propose_patch(array $failures, $fileText){
  $hints = [];
  foreach ($failures as $f){ $hints[] = "- Test: {$f['name']} — Reason: {$f['reason']}"; }
  $hintBlock = implode("\n", $hints);

  $sys = "You are CODE SURGEON. Produce a MINIMAL unified diff that fixes the tests.\n".
         "Rules:\n".
         "- Only patch the file: language_api.php\n".
         "- Keep behavior unchanged except to satisfy tests.\n".
         "- No shell calls; keep security/logging.\n".
         "- Output: raw unified diff ONLY, no code fences, no commentary.";

  $user = "Failing tests (name → reason):\n$hintBlock\n\n".
          "Here is the current file content for language_api.php between markers:\n<FILE>\n" . $fileText . "\n</FILE>\n\n".
          "Generate a single unified diff (patch) with correct hunk headers.";

  $resp = gemini_call_25([
    ['role'=>'user','content'=>$sys],
    ['role'=>'user','content'=>$user],
  ], 2000, 0.05);

  if ($resp['status']!=='success') return $resp;
  return ['status'=>'success','diff'=>trim($resp['content'])];
}

function sanitize_diff_filename($diffText){
  if (preg_match('/^\-\-\-\s+a\/(.*?)$/m', $diffText, $m) || preg_match('/^\-\-\-\s+(.*?)$/m', $diffText, $m2)){
    $f = $m[1] ?? $m2[1];
    $f = trim($f);
    $f = preg_replace('/^a\//','',$f);
    $f = basename($f);
    if (!in_array($f, ALLOW_PATCH, true)) return [false, $f];
    return [true, $f];
  }
  return [true, basename(TARGET_FILE)];
}

function apply_unified_diff($originalText, $diffText){
  $lines = preg_split("/\r?\n/", $originalText);
  $out   = $lines;

  if (!preg_match_all('/^@@\s+\-(\d+)(?:,(\d+))?\s+\+(\d+)(?:,(\d+))?\s+@@$/m', $diffText, $matches, PREG_OFFSET_CAPTURE)){
    return [false, null, 'No hunks found'];
  }
  $offsets = [];
  foreach ($matches[0] as $m){ $offsets[] = $m[1]; }
  $offsets[] = strlen($diffText);

  $hunks = [];
  for ($i=0; $i<count($offsets)-1; $i++){
    $start = $offsets[$i]; $end = $offsets[$i+1];
    $prevNL = strrpos(substr($diffText, 0, $start), "\n");
    $chunkStart = ($prevNL===false)?0:$prevNL+1;
    $hunks[] = substr($diffText, $chunkStart, $end - $chunkStart);
  }

  $cursorAdj = 0;
  foreach ($hunks as $hunk){
    if (!preg_match('/^@@\s+\-(\d+)(?:,(\d+))?\s+\+(\d+)(?:,(\d+))?\s+@@\s*\n(.*)$/s', $hunk, $hm)){
      return [false, null, 'Bad hunk header'];
    }
    $oldStart = (int)$hm[1];
    $payload  = $hm[5];
    $hunkLines = preg_split("/\r?\n/", rtrim($payload, "\r\n"));

    $startIdx = $oldStart - 1 + $cursorAdj;
    if ($startIdx < 0 || $startIdx > count($out)) return [false, null, 'Hunk start out of range'];

    $insert = [];
    $probeIdx = $startIdx;

    foreach ($hunkLines as $hl){
      if ($hl==='') continue;
      $tag = $hl[0]; $text = substr($hl,1);
      if ($tag===' '){
        if (!isset($out[$probeIdx]) || $out[$probeIdx] !== $text) return [false, null, 'Context mismatch'];
        $insert[] = $out[$probeIdx]; $probeIdx++;
      } elseif ($tag==='-'){
        if (!isset($out[$probeIdx]) || $out[$probeIdx] !== $text) return [false, null, 'Removal mismatch'];
        $probeIdx++;
      } elseif ($tag==='+'){
        $insert[] = $text;
      } else { return [false, null, 'Unknown tag in hunk']; }
    }

    array_splice($out, $startIdx, $probeIdx - $startIdx, $insert);
    $delta = count($insert) - ($probeIdx - $startIdx);
    $cursorAdj += $delta;
  }

  return [true, implode("\n", $out), null];
}

function write_with_backup($newText){
  if (!is_file(TARGET_FILE)) return [false, 'Target file not found'];
  $orig = file_get_contents(TARGET_FILE);
  $stamp = date('Ymd_His');
  $backup = BACKUP_DIR . '/language_api.php.' . $stamp . '.bak';
  if (@file_put_contents($backup, $orig) === false) return [false, 'Backup write failed'];
  if (@file_put_contents(TARGET_FILE, $newText) === false) return [false, 'Write failed'];
  return [true, $backup];
}

// ---------------- Session Chat Helpers ----------------
function chat_thread_ref(){ if (!isset($_SESSION['guardian_thread'])) $_SESSION['guardian_thread']=[]; return $_SESSION['guardian_thread']; }
function chat_push($role,$content){ if (!isset($_SESSION['guardian_thread'])) $_SESSION['guardian_thread']=[]; $_SESSION['guardian_thread'][]=['role'=>$role,'content'=>$content]; }
function chat_clear(){ $_SESSION['guardian_thread'] = []; }

// A concise system prompt for the supervisor
function supervisor_system_prompt(){
  return implode("\n", [
    'You are SUPERVISOR, a senior code reviewer for language_api.php.',
    'You maintain a running conversation with the user in the SAME SESSION.',
    'Goals:',
    ' - Diagnose why AVA/GALA behavior seems wrong relative to the pipeline rules',
    ' - When the user asks to change behavior, propose the MINIMAL safe edits',
    ' - If asked to propose a patch, output a RAW unified diff ONLY (no fences, no commentary)',
    'Policy:',
    ' - No shell calls; no extra files; only edit language_api.php',
    ' - Preserve logging, headers, security hardening',
    ' - Respect the definition-first policy (no autocorrect of surface tokens)',
  ]);
}

// ---------------- Controller ----------------
$action = $_REQUEST['action'] ?? 'run';

if ($action === 'health'){
  echo json_encode(['status'=>'ok','target_exists'=>is_file(TARGET_FILE)], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'lastlog'){
  $tail = @file_get_contents(LOG_FILE);
  $tail = $tail ? $tail : '';
  echo json_encode(['log_tail'=>$tail], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'chat'){
  $message = trim((string)($_POST['message'] ?? $_GET['message'] ?? ''));
  $reset   = isset($_REQUEST['reset']);
  if ($reset) chat_clear();
  if ($message===''){ echo json_encode(['status'=>'error','error'=>'Empty message']); exit; }

  chat_push('user', $message);
  $thread = chat_thread_ref();

  $msgs = [ ['role'=>'user','content'=>supervisor_system_prompt()] ];
  foreach ($thread as $m){ $msgs[] = ['role'=>$m['role'], 'content'=>$m['content']]; }

  $resp = gemini_call_25($msgs, 1500, 0.1);
  if (($resp['status']??'')!=='success'){
    echo json_encode(['status'=>'error','error'=>'LLM','details'=>$resp]); exit;
  }
  $reply = trim($resp['content']);
  chat_push('assistant', $reply);

  echo json_encode(['status'=>'ok','reply'=>$reply,'thread'=>chat_thread_ref()], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'chat_patch'){
  $message  = trim((string)($_POST['message'] ?? $_GET['message'] ?? ''));
  $fileText = @file_get_contents(TARGET_FILE);
  if ($fileText===false){ echo json_encode(['status'=>'error','error'=>'Cannot read target file']); exit; }

  // Include chat so far for context
  $thread = chat_thread_ref();
  if ($message!=='') chat_push('user', $message);

  $sys = supervisor_system_prompt() . "\nWhen responding to this request, output a RAW unified diff ONLY.";
  $chatBlock = "";
  foreach ($thread as $m){ $chatBlock .= strtoupper($m['role']).": ".$m['content']."\n\n"; }

  $user = "Conversation so far (most recent last):\n" . $chatBlock .
          "Current language_api.php content between markers:\n<FILE>\n" . $fileText . "\n</FILE>\n" .
          "\nGenerate a minimal unified diff to implement the requested correction(s).";

  $resp = gemini_call_25([
    ['role'=>'user','content'=>$sys],
    ['role'=>'user','content'=>$user],
  ], 2000, 0.05);

  if (($resp['status']??'')!=='success'){
    echo json_encode(['status'=>'error','error'=>'LLM','details'=>$resp]); exit;
  }

  $diff = trim($resp['content']);
  if ($diff===''){ echo json_encode(['status'=>'error','error'=>'Empty diff']); exit; }

  if (strlen($diff) > 200_000){ echo json_encode(['status'=>'error','error'=>'Diff too large','bytes'=>strlen($diff)]); exit; }

  [$okFile, $fname] = sanitize_diff_filename($diff);
  if (!$okFile){ echo json_encode(['status'=>'error','error'=>'Diff targets forbidden file','target'=>$fname]); exit; }

  $patchPath = PATCH_DIR . '/patch_' . date('Ymd_His') . '.diff';
  if (@file_put_contents($patchPath, $diff) === false){ echo json_encode(['status'=>'error','error'=>'Cannot write patch']); exit; }

  $_SESSION['guardian_last_patch'] = $patchPath;

  // Try dry-run apply
  [$ok, $newText, $err] = apply_unified_diff($fileText, $diff);
  if (!$ok){ echo json_encode(['status'=>'error','error'=>'Apply failed (dry-run)','reason'=>$err,'diff_path'=>$patchPath,'diff'=>$diff]); exit; }

  echo json_encode(['status'=>'ok','diff_path'=>$patchPath,'bytes'=>strlen($diff),'preview_ok'=>true,'message'=>'Patch proposed. You can now apply it.'], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'apply_last'){
  $patchPath = $_SESSION['guardian_last_patch'] ?? '';
  if (!$patchPath || !is_file($patchPath)){ echo json_encode(['status'=>'error','error'=>'No stored patch']); exit; }

  $fileText = @file_get_contents(TARGET_FILE);
  $diff     = @file_get_contents($patchPath);
  if ($fileText===false || $diff===false){ echo json_encode(['status'=>'error','error'=>'Read failed']); exit; }

  [$ok, $newText, $err] = apply_unified_diff($fileText, $diff);
  if (!$ok){ echo json_encode(['status'=>'error','error'=>'Apply failed (dry-run)','reason'=>$err]); exit; }

  [$wok, $backup] = write_with_backup($newText);
  if (!$wok){ echo json_encode(['status'=>'error','error'=>'Write failed','details'=>$backup]); exit; }

  guardian_log(['PATCH_APPLIED_FROM_CHAT'=>['backup'=>$backup,'patch'=>$patchPath]]);

  // Smoke-test
  $post = run_tests();
  $stillFail = array_values(array_filter($post, fn($r)=>!$r['pass']));
  if ($stillFail){
    $backupText = @file_get_contents($backup);
    if ($backupText!==false) @file_put_contents(TARGET_FILE, $backupText);
    guardian_log(['PATCH_REVERTED'=>'smoke tests still failing (chat apply)']);
    echo json_encode(['status'=>'error','error'=>'Post-patch tests still failing. Reverted.','results_after'=>$post,'backup'=>$backup], JSON_UNESCAPED_UNICODE); exit;
  }

  echo json_encode(['status'=>'ok','message'=>'Patched successfully. All tests now pass.','backup'=>$backup,'results_after'=>$post], JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'run'){
  $apply = !empty($_REQUEST['apply']);
  $results = run_tests();
  $failed  = array_values(array_filter($results, fn($r)=>!$r['pass']));
  $summary = ['total'=>count($results), 'failed'=>count($failed)];

  if (!$failed){ echo json_encode(['status'=>'ok','summary'=>$summary,'results'=>$results,'message'=>'All tests passed. No patch needed.'], JSON_UNESCAPED_UNICODE); exit; }

  $fileText = @file_get_contents(TARGET_FILE);
  if ($fileText===false){ echo json_encode(['status'=>'error','error'=>'Cannot read target file','results'=>$results], JSON_UNESCAPED_UNICODE); exit; }

  $proposal = propose_patch($failed, $fileText);
  if (($proposal['status']??'')!=='success'){
    echo json_encode(['status'=>'error','error'=>'Patch proposal failed','details'=>$proposal,'results'=>$results], JSON_UNESCAPED_UNICODE); exit; }
  $diff = $proposal['diff'];

  if (strlen($diff) > 200_000){ echo json_encode(['status'=>'error','error'=>'Diff too large','bytes'=>strlen($diff),'results'=>$results], JSON_UNESCAPED_UNICODE); exit; }

  [$okFile, $fname] = sanitize_diff_filename($diff);
  if (!$okFile){ echo json_encode(['status'=>'error','error'=>'Diff targets forbidden file','target'=>$fname,'results'=>$results], JSON_UNESCAPED_UNICODE); exit; }

  $patchPath = PATCH_DIR . '/patch_' . date('Ymd_His') . '.diff';
  @file_put_contents($patchPath, $diff);

  [$ok, $newText, $err] = apply_unified_diff($fileText, $diff);
  if (!$ok){ echo json_encode(['status'=>'error','error'=>'Apply failed (dry-run)','reason'=>$err,'diff_path'=>$patchPath,'results'=>$results], JSON_UNESCAPED_UNICODE); exit; }

  if (!$apply){ echo json_encode(['status'=>'ok','summary'=>$summary,'results'=>$results,'dry_run'=>true,'diff_path'=>$patchPath], JSON_UNESCAPED_UNICODE); exit; }

  [$wok, $msg] = write_with_backup($newText);
  if (!$wok){ echo json_encode(['status'=>'error','error'=>'Write failed','details'=>$msg,'diff_path'=>$patchPath], JSON_UNESCAPED_UNICODE); exit; }

  guardian_log(['PATCH_APPLIED'=>['backup'=>$msg,'patch'=>$patchPath]]);

  $post = run_tests();
  $stillFail = array_values(array_filter($post, fn($r)=>!$r['pass']));
  if ($stillFail){
    $backupText = @file_get_contents($msg);
    if ($backupText!==false) @file_put_contents(TARGET_FILE, $backupText);
    guardian_log(['PATCH_REVERTED'=>'smoke tests still failing']);
    echo json_encode(['status'=>'error','error'=>'Post-patch tests still failing. Reverted.','results_before'=>$results,'results_after'=>$post,'backup'=>$msg,'diff_path'=>$patchPath], JSON_UNESCAPED_UNICODE); exit;
  }

  echo json_encode(['status'=>'ok','summary_after'=>['total'=>count($post),'failed'=>0],'message'=>'Patched successfully. All tests now pass.','backup'=>$msg,'diff_path'=>$patchPath], JSON_UNESCAPED_UNICODE); exit;
}

http_response_code(400);
echo json_encode(['status'=>'error','error'=>'Unknown action'], JSON_UNESCAPED_UNICODE);
