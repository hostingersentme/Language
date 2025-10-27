<?php
session_start();

// =========================
// language_api.php — AVA ➜ GALA backend
// Definition-first language analyzer
// =========================

// ---------- Headers & hardening ----------
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/language_debug.log');

// Buffer all output to prevent stray bytes from breaking JSON
ob_start();

register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    error_log("FATAL: {$e['message']} in {$e['file']}:{$e['line']}");
    @ob_end_clean();
    echo json_encode(['status'=>'error','error'=>'Server error. See logs.'], JSON_UNESCAPED_UNICODE);
  }
});

// ---------- Utilities ----------
function lang_log($msg){
  $msg = is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE);
  $date = date('Y-m-d H:i:s');
  file_put_contents(__DIR__ . '/language_debug.log', "[$date] $msg\n", FILE_APPEND);
}

function send_json($arr, $code = 200){
  http_response_code($code);
  $stray = ob_get_contents();
  if ($stray !== false && $stray !== '') {
    $hex = bin2hex($stray);
    lang_log('Stray output suppressed len=' . strlen($stray) . ' hex=' . substr($hex,0,120));
  }
  @ob_end_clean();
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function require_silent_file($path, $label){
  ob_start();
  $ret = require $path;           // this preserves 'return [...]' from config files
  $buf = ob_get_clean();
  if ($buf !== '' && $buf !== false) {
    lang_log("INCLUDE[$label] emitted len=" . strlen($buf) . " hex=" . bin2hex(substr($buf, 0, 120)));
  }
  return $ret;
}

function strip_code_fences($s){
  $s = trim($s);
  if (preg_match('/^```[a-zA-Z]*\n([\s\S]*?)\n```$/', $s, $m)) return trim($m[1]);
  return $s;
}

function extract_first_json($s){
  $s = trim($s);
  $start = strpos($s, '{');
  if ($start === false) return $s;
  $depth = 0; $in = false; $esc = false; $out = '';
  for ($i=$start, $n=strlen($s); $i<$n; $i++){
    $ch = $s[$i];
    if ($in){
      if ($esc){ $esc=false; $out.=$ch; continue; }
      if ($ch === '\\'){ $esc=true; $out.=$ch; continue; }
      if ($ch === '"'){ $in=false; $out.=$ch; continue; }
      $out.=$ch; continue;
    }
    if ($ch === '"'){ $in=true;  $out.=$ch; continue; }
    if ($ch === '{'){ $depth++;  $out.=$ch; continue; }
    if ($ch === '}'){ $depth--;  $out.=$ch; if ($depth===0) break; continue; }
    $out.=$ch;
  }
  return $out ?: $s;
}

// Tokenize words (letters/digits), keep order & offsets. Duplicates preserved.
if (!function_exists('tokenize_words_with_offsets')) {
  function tokenize_words_with_offsets(string $s): array {
    $tokens = [];
    if (preg_match_all('/[A-Za-z]+|\d+/u', $s, $m, PREG_OFFSET_CAPTURE)) {
      foreach ($m[0] as $i => $pair) {
        $tok   = $pair[0];
        $start = $pair[1];
        $tokens[] = ['i'=>$i, 'token'=>$tok, 'start'=>$start, 'end'=>$start + strlen($tok)];
      }
    }
    return $tokens;
  }
}

// (Optional - only if your repair code calls tiny_gloss and you haven't added it yet)
if (!function_exists('tiny_gloss')) {
  function tiny_gloss(string $tok): string {
    static $dict = [
      'what'=>'Used to ask for information',
      'is'=>'Be; exist; indicate identity',
      'the'=>'Definite article',
      'a'=>'Indefinite article (one)',
      'an'=>'Indefinite article (one)',
      'of'=>'Indicates relation/possession',
      'in'=>'Expresses location or time',
      'like'=>'Similar to; resembling',
      'capital'=>'Most important city of a country',
    ];
    $k = strtolower($tok);
    return $dict[$k] ?? 'unknown';
  }
}

// ---------- Load config ----------
$config = ['site_key'=>'','secret_key'=>''];
try { $config = require_silent_file(__DIR__ . '/recaptcha_config.php', 'recaptcha_config.php'); }
catch (Throwable $e) { $api_ok = false; }

// Load Gemini key (supports both styles: variable or return array)
$geminiApiKey = '';

ob_start();
$ret = @include __DIR__ . '/api_key.php';
$buf = ob_get_clean();
if ($buf !== '' && $buf !== false) {
  lang_log('INCLUDE[api_key.php] emitted len=' . strlen($buf) . ' hex=' . bin2hex(substr($buf, 0, 120)));
}

// If file returned an array, read from it
if (!$geminiApiKey && is_array($ret)) {
  $geminiApiKey = $ret['gemini'] ?? $ret['geminiApiKey'] ?? '';
}

// Optional env fallback
if (!$geminiApiKey && getenv('GEMINI_API_KEY')) {
  $geminiApiKey = getenv('GEMINI_API_KEY');
}

if (empty($geminiApiKey)) {
  lang_log('Gemini API key missing. Falling back to local analyzer on failure.');
}

// ---------- reCAPTCHA v3 ----------
function verify_recaptcha($token, $action, $threshold = 0.5){
  global $secret_key;
  if (!$secret_key) return true; // allow when not configured
  if (!$token) return false;
  $url = 'https://www.google.com/recaptcha/api/siteverify';
  $data = http_build_query(['secret'=>$secret_key,'response'=>$token]);
  $ctx = stream_context_create(['http'=>[
    'method'=>'POST','header'=>"Content-type: application/x-www-form-urlencoded\r\n",'content'=>$data,'timeout'=>10
  ]]);
  $res = @file_get_contents($url, false, $ctx);
  if ($res === false) { lang_log('reCAPTCHA: contact failed'); return false; }
  $j = json_decode($res, true);
  if (!$j || empty($j['success'])) { lang_log('reCAPTCHA: unsuccessful ' . $res); return false; }
  if (isset($j['action']) && $j['action'] !== $action) { lang_log('reCAPTCHA: action mismatch ' . $j['action']); return false; }
  $score = $j['score'] ?? 0.0;
  return $score >= $threshold;
}

// ---------- Rate limiting ----------
$rate_limits = [ 'analyze' => ['limit'=>120, 'window'=>3600] ];
if (!isset($_SESSION['rate_limiting'])) {
  $_SESSION['rate_limiting'] = [];
}
function check_rate_limit($key, $limit, $window){
  if (!isset($_SESSION['rate_limiting'][$key])) {
    $_SESSION['rate_limiting'][$key] = ['count'=>0,'start'=>time()];
  }
  $slot =& $_SESSION['rate_limiting'][$key];
  $now = time();
  if ($now - $slot['start'] > $window) { $slot['count']=0; $slot['start']=$now; }
  if ($slot['count'] >= $limit) return false;
  $slot['count']++;
  return true;
}

// ---------- Gemini API ----------
function gemini_call($model, $messages, $max_tokens=1500, $temperature=0.2){
  global $geminiApiKey;
  if (!$geminiApiKey) return ['status'=>'error','error'=>'no_api_key'];
  $valid = ['gemini-2.0-flash-lite','gemini-2.0-flash','gemini-1.5-pro-latest','gemini-2.5-pro-preview-05-06'];
  if (!in_array($model, $valid, true)) $model = 'gemini-2.0-flash-lite';
  $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($geminiApiKey);

  $contents = [];
  foreach ($messages as $m){
    $role = ($m['role']==='assistant') ? 'model' : 'user';
    $parts = isset($m['parts']) ? $m['parts'] : [['text'=>$m['content']]];
    $contents[] = ['role'=>$role,'parts'=>$parts];
  }
  $payload = [
    'contents'=>$contents,
    'generationConfig'=>[
      'temperature'=>$temperature,
      'maxOutputTokens'=>$max_tokens,
      'stopSequences'=>[]
    ]
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST=>1,
    CURLOPT_RETURNTRANSFER=>1,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode($payload)
  ]);
  $response = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($err) { return ['status'=>'error','error'=>'curl','details'=>$err]; }
  if ($http !== 200) { return ['status'=>'error','error'=>'http','details'=>$response,'code'=>$http]; }
  $j = json_decode($response, true);
  if (!$j) { return ['status'=>'error','error'=>'json','details'=>$response]; }
  $text = $j['candidates'][0]['content']['parts'][0]['text'] ?? '';
  return $text ? ['status'=>'success','content'=>$text] : ['status'=>'error','error'=>'no_text','details'=>$j];
}

// ---------- Local fallback (deterministic) ----------
function local_analyze($prompt){
  $toks = []; preg_match_all('/[A-Za-z]+|\d+/', strtolower($prompt), $mm); $toks = $mm[0] ?? [];
  $dict = [
    'give'=>'provide to a recipient','me'=>'the speaker as recipient','a'=>'one instance','an'=>'one instance','trivial'=>'of little difficulty; simple','trivia'=>'facts of little importance; general knowledge','question'=>'a request for information','add'=>'combine quantities to form a sum','and'=>'join operands or phrases','what'=>'requests information','is'=>'copula linking subject and predicate','the'=>'definite determiner','capital'=>'principal city of a nation or state','of'=>'indicates relation or possession'
  ];
  $defs = [];
  foreach ($toks as $t){ $defs[] = ['token'=>$t,'gloss'=>($dict[$t] ?? '[unknown]')]; }

  $mr = ['intent'=>'unknown','slots'=>new stdClass()];
  $result = '';

  if (in_array('trivial',$toks) && in_array('question',$toks)){
    $mr = ['intent'=>'generate_easy_question','slots'=>['difficulty'=>'low']];
    $result = 'What is 2 + 2?';
  } elseif (in_array('trivia',$toks) && in_array('question',$toks)){
    $mr = ['intent'=>'ask_fact','slots'=>['domain'=>'factoids']];
    $result = 'Here is a general-knowledge question: Which ocean is the largest?';
  } elseif (!empty($toks) && $toks[0]==='add'){
    // Add twenty three and fifty nine (0..99)
    $nums = [
      'zero'=>0,'one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,
      'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,
      'twenty'=>20,'thirty'=>30,'forty'=>30+10,'fifty'=>50,'sixty'=>60,'seventy'=>70,'eighty'=>80,'ninety'=>90
    ];
    $and = array_search('and',$toks,true);
    $l = array_slice($toks,1, $and>0? $and-1: null);
    $r = $and!==false? array_slice($toks,$and+1): [];
    $w2n = function($arr) use($nums){ $n=0; foreach($arr as $w){ if(isset($nums[$w])){ if($nums[$w]>=20 && $n%10===0){ $n += $nums[$w]; } else { $n += $nums[$w]; } } } return $n?:null; };
    $left=$w2n($l); $right=$w2n($r);
    if($left!==null && $right!==null){ $mr=['intent'=>'compute','slots'=>['op'=>'+','operands'=>[$left,$right]]]; $result = $left.' + '.$right.' = '.($left+$right); }
  } elseif (preg_match('/capital of\s+([A-Za-z]+)/i',$prompt,$m)){
    $mr = ['intent'=>'ask_fact','slots'=>['kind'=>'capital_of','target'=>$m[1]]];
    $result = 'Recognized request: ask(capital_of("'.$m[1].'")) — factual data unavailable in local mode.';
  }

  if (!$result) $result = 'Parsed, but no deterministic definition-first action matched.';

  return [
    'ava'=>['definitions'=>$defs,'mr'=>$mr],
    'gala'=>['result'=>$result],
    'used'=>'local'
  ];
}

// ---------- Request parsing ----------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
  lang_log('JSON decode error: ' . json_last_error_msg() . ' RAW: ' . substr($raw,0,500));
  send_json(['status'=>'error','error'=>'Invalid JSON input.'], 400);
}
$action = $data['action'] ?? '';
if ($action !== 'analyze') { send_json(['status'=>'error','error'=>'Unknown action.'], 400); }

if (!check_rate_limit('analyze', $rate_limits['analyze']['limit'], $rate_limits['analyze']['window'])){
  send_json(['status'=>'error','error'=>'Rate limit exceeded. Try again later.'], 429);
}

$prompt = trim((string)($data['prompt'] ?? ''));
$recaptcha = $data['recaptcha'] ?? '';

if (!verify_recaptcha($recaptcha, 'analyze', 0.3)){
  send_json(['status'=>'error','error'=>'reCAPTCHA verification failed.'], 403);
}
if ($prompt === ''){ send_json(['status'=>'error','error'=>'Empty prompt.'], 400); }

// Build exact surface tokens (keeps duplicates like "the the")
$tokens = tokenize_words_with_offsets($prompt);

// ---------- AVA stage ----------
$ava_sys = <<<TXT
You are AVA, a definition-first analyzer. Given an input sentence, produce STRICT JSON only with fields:
{
  "definitions": [ {"token": string, "pos": string, "gloss": string} ... ],
  "mr": {"intent": string, "slots": object}
}
Rules:
- Define each content token briefly (dictionary gloss). Keep 3–10 words per gloss.
- Build a small meaning representation (mr): intent ∈ {generate_easy_question, ask_fact, compute, define, compare, unknown}; slots contains parameters (e.g., {difficulty:"low"}, {operands:[23,59], op:"+"}, {target:"Francee", kind:"capital_of"}).
- NO preface, NO code fences, JSON ONLY.
- Do not answer any factual question.
- Strict surface-form policy:
   • Never spell-correct, normalize, or substitute tokens (e.g., "Francee" ≠ "France").
   • If a token looks like a named entity but you cannot know its identity without external facts,
     set pos to NNP (or X) and gloss EXACTLY "proper noun (unknown)" or "unknown".
   • Only label a token as a specific entity type (country, city, person, etc.) if it can be known
     from the token alone without correction. Otherwise keep it unknown.
    • Define words without reference to context unless it makes it obvious which definition should apply to words that have multiple definitions.
Examples:
  Input token "Francee" → {"token":"Francee","pos":"NNP","gloss":"proper noun (unknown)"}.
  Input "capital of X" → mr may be {"intent":"ask_fact","slots":{"kind":"capital_of","target":"X"}}.
  Do NOT convert "Francee"→"France".
TXT;

$ava_messages = [
  ['role'=>'user','content'=>$ava_sys],
  ['role'=>'user','content'=>'INPUT: ' . $prompt]
];

$ava_resp = gemini_call('gemini-2.0-flash-lite', $ava_messages, 600, 0.1);
if ($ava_resp['status'] !== 'success'){
  lang_log(['AVA_fail'=>$ava_resp]);
  $out = local_analyze($prompt);
  $out['status'] = 'ok';
  send_json($out);
}

$ava_text = extract_first_json(strip_code_fences($ava_resp['content']));
$ava_json = json_decode($ava_text, true);
if (!$ava_json || !isset($ava_json['definitions'],$ava_json['mr'])){
  lang_log(['AVA_bad_json'=>$ava_text]);
  $out = local_analyze($prompt);
  $out['status'] = 'ok';
  send_json($out);
}

// --- VALIDATE AVA definitions against our token list ---
$defs = $ava_json['definitions'] ?? [];
if (!is_array($defs)) $defs = [];   // <- prevent count(null) fatals

$needRepair = false;
$tokCount   = count($tokens);
$defCount   = count($defs);

if ($defCount !== $tokCount) {
  $needRepair = true;
} else {
  for ($i = 0; $i < $tokCount; $i++) {
    $d = $defs[$i] ?? null;
    if (
      !is_array($d) ||
      !array_key_exists('i', $d) ||
      !isset($d['token']) ||
      (int)$d['i'] !== $tokens[$i]['i'] ||
      (string)$d['token'] !== (string)$tokens[$i]['token']
    ) { $needRepair = true; break; }
  }
}

if ($needRepair) {
  // Index any defs we *did* get by token so we can reuse pos/gloss where possible
  $byToken = [];
  foreach ($defs as $d) {
    if (is_array($d) && isset($d['token'])) {
      $k = (string)$d['token'];
      if (!isset($byToken[$k])) $byToken[$k] = [];
      $byToken[$k][] = $d;   // <- correct push
    }
  }

  $fixed = [];
  foreach ($tokens as $t) {
    $tok   = $t['token'];
    $reuse = (!empty($byToken[$tok])) ? array_shift($byToken[$tok]) : null;

    // Simple POS fallback heuristics
    $lower = strtolower($tok);
    $pos =
      (is_array($reuse) && !empty($reuse['pos'])) ? $reuse['pos'] :
      (in_array($lower, ['the','a','an']) ? 'DT' :
      ($lower === 'is' ? 'VBZ' :
      ((strlen($tok) && ctype_upper($tok[0])) ? 'NNP' : 'NN')));

    $fixed[] = [
      'i'     => $t['i'],
      'token' => $tok,
      'pos'   => $pos,
      'gloss' => (is_array($reuse) && !empty($reuse['gloss'])) ? $reuse['gloss'] : tiny_gloss($tok),
    ];
  }

  $ava_json['definitions'] = $fixed;
  lang_log(['AVA_defs_repaired' => ['expect' => $tokCount, 'got' => $defCount]]);
}

// ---------- GALA stage ----------
$gala_sys = <<<TXT
You are GALA. You receive AVA's JSON (definitions + mr). Use ONLY that to produce a result.
- If mr.intent == "generate_easy_question": return a single easy question (e.g., simple arithmetic).
- If mr.intent == "compute": compute deterministically from mr.slots (no external facts).
- If mr.intent == "ask_fact": answer succinctly when possible, or explain briefly what information is missing.
- Otherwise: return a brief deterministic reply keyed to the MR.
Output STRICT JSON only: {"result": string}
No extra text.
TXT;

$gala_messages = [
  ['role'=>'user','content'=>$gala_sys],
  ['role'=>'user','content'=>'AVA_JSON: ' . json_encode($ava_json, JSON_UNESCAPED_UNICODE)]
];

$gala_resp = gemini_call('gemini-2.0-flash-lite', $gala_messages, 400, 0.2);
if ($gala_resp['status'] !== 'success'){
  lang_log(['GALA_fail'=>$gala_resp]);
  // Fall back to local using AVA MR heuristics
  $out = local_analyze($prompt);
  $out['status'] = 'ok';
  send_json($out);
}
$gala_text = extract_first_json(strip_code_fences($gala_resp['content']));
$gala_json = json_decode($gala_text, true);
if (!$gala_json || !isset($gala_json['result'])){
  lang_log(['GALA_bad_json'=>$gala_text]);
  $out = local_analyze($prompt);
  $out['status'] = 'ok';
  send_json($out);
}

send_json([
  'status' => 'ok',
  'ava'    => [
    'definitions' => $ava_json['definitions'],
    'mr'          => $ava_json['mr'],
  ],
  'gala'   => [ 'result' => $gala_json['result'] ],
  'used'   => 'gemini'
]);