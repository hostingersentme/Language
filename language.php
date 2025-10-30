<?php
session_start();

// ===== Headers & Error Handling =====
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/imodel_debug.log');

// reCAPTCHA site key (expects recaptcha_config.php returning ['site_key' => '...'])
$recaptcha_cfg = [];
try {
  $recaptcha_cfg = require __DIR__ . '/recaptcha_config.php';
} catch (Throwable $e) {
  // Optional: proceed without it; frontend will guard
}
$site_key = htmlspecialchars($recaptcha_cfg['site_key'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Language Analyzer — AVA (definitions) → GALA (result)</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --bg:#0b1020; --panel:#121936; --muted:#8ea0b9; --text:#e8eefc; --accent:#7c8cff; --ok:#28c76f; --err:#ff5d5d; }
    *{box-sizing:border-box}
    body{margin:0;font-family:Inter,system-ui,Arial,sans-serif;background:linear-gradient(180deg,#0b1020,#0b132b);color:var(--text)}
    .top{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.06);background:rgba(0,0,0,.2);backdrop-filter:blur(6px);position:sticky;top:0;z-index:9}
    .top a{color:var(--muted);text-decoration:none;margin-right:14px}
    .top a:hover{color:#cfe3ff}
    .wrap{max-width:980px;margin:24px auto;padding:0 16px}
    .title{font-weight:700;font-size:24px;letter-spacing:.3px;margin:6px 0 14px}
    .sub{color:var(--muted);margin-bottom:18px}
    .card{background:var(--panel);border:1px solid rgba(255,255,255,.06);border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
    .row{display:grid;grid-template-columns:1.15fr .85fr;gap:14px}
    @media (max-width:900px){.row{grid-template-columns:1fr}}
    .pad{padding:16px}
    textarea{width:100%;min-height:120px;background:#0e1530;color:var(--text);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px;resize:vertical;font-size:14px;line-height:1.45}
    .controls{display:flex;gap:10px;margin-top:10px}
    button{appearance:none;border:0;border-radius:12px;padding:10px 14px;font-weight:600;cursor:pointer}
    .primary{background:var(--accent);color:#0b0f23}
    .ghost{background:transparent;border:1px solid rgba(255,255,255,.12);color:var(--text)}
    .primary[disabled]{opacity:.6;cursor:not-allowed}
    .pill{display:inline-flex;gap:8px;align-items:center;background:rgba(255,255,255,.06);color:#cfe3ff;border-radius:999px;padding:6px 10px;font-size:12px;margin-left:8px}
    .cols{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media (max-width:900px){.cols{grid-template-columns:1fr}}
    .box{min-height:160px;white-space:pre-wrap}
    .hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
    .hdr h3{margin:0;font-size:14px;letter-spacing:.2px;color:#cfe3ff}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:13px;background:#0a0f24;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.06);overflow:auto}
    .muted{color:var(--muted)}
    .ok{color:var(--ok)}
    .err{color:var(--err)}
    details{background:#0e1431;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:8px}
    summary{cursor:pointer;color:#cfe3ff}
    .chatlog{min-height:160px;background:#0a0f24;border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:10px;overflow:auto}
    .chatlog .u{color:#cfe3ff}
    .chatlog .a{color:#28c76f}
  </style>
  <?php if ($site_key): ?>
  <script src="https://www.google.com/recaptcha/api.js?render=<?=$site_key?>" async defer></script>
  <?php endif; ?>
</head>
<body>
  <div class="top">
    <div>
      <a href="https://informationism.org/index.php">Home</a>
      <a href="#" title="Language Analyzer">Analyzer</a>
    </div>
    <div class="pill">AVA → GALA <span class="muted">definition-first</span></div>
  </div>

  <div class="wrap">
    <div class="title">Language Analyzer</div>
    <div class="sub">Two-stage pipeline: <strong>AVA</strong> writes per-word definitions & a structured meaning; <strong>GALA</strong> consumes only those definitions to produce a reply. (No world facts unless explicitly enabled.)</div>

    <div class="row">
      <div class="card pad">
        <label for="userInput" class="muted">Input sentence</label>
        <textarea id="userInput" placeholder="e.g., Give me a trivial question."></textarea>
        <div class="controls">
          <button id="analyzeBtn" class="primary">Analyze</button>
          <button id="clearBtn" class="ghost">Clear</button>
          <label class="pill"><input type="checkbox" id="factsToggle" /> Allow facts</label>
        </div>
        <div id="status" class="muted" style="margin-top:8px"></div>
      </div>

      <div class="card pad">
        <div class="hdr"><h3>Debug</h3><span id="latency" class="muted"></span></div>
        <details>
          <summary>Raw JSON</summary>
          <pre id="raw" class="mono"></pre>
        </details>
        <div style="height:8px"></div>
        <div id="notice" class="muted"></div>
      </div>
    </div>

    <div style="height:16px"></div>

    <div class="cols">
      <div class="card pad">
        <div class="hdr"><h3>AVA • Definitions</h3><button id="copyDef" class="ghost" style="font-size:12px">Copy</button></div>
        <div id="definitions" class="box mono muted">(waiting…)</div>
      </div>
      <div class="card pad">
        <div class="hdr"><h3>GALA • Result</h3><button id="copyRes" class="ghost" style="font-size:12px">Copy</button></div>
        <div id="result" class="box"></div>
      </div>
    </div>

    <div style="height:16px"></div>
    <div class="card pad" id="supervisor">
      <div class="hdr">
        <h3>Supervisor • Gemini 2.5 Pro</h3>
        <span class="muted">session-context chat</span>
      </div>

      <div id="supervisorLog" class="chatlog mono"></div>

      <textarea id="supervisorMsg"
        placeholder="Tell Gemini what was wrong and how to fix it… (e.g., ‘Don’t autocorrect unknown tokens; ensure meta.mode=clarify on unknown proper nouns.’)"></textarea>

      <div class="controls">
        <button id="supervisorSend" class="primary">Send</button>
        <button id="supervisorPropose" class="ghost" title="Ask for a minimal unified diff patch">
          Propose Patch
        </button>
        <button id="supervisorApply" class="ghost" title="Apply last proposed patch">
          Apply Last Patch
        </button>
        <button id="supervisorReset" class="ghost" title="Clear the conversation">
          Reset
        </button>
      </div>

      <div id="supStatus" class="muted" style="margin-top:8px"></div>
    </div>

  </div>

<script>
// ========== Utilities ==========
function escapeHtml(text){
  return String(text)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}

async function parseJsonSafe(response){
  const raw = await response.text();
  try { return JSON.parse(raw); }
  catch(e){ console.error('Non-JSON from server:', raw); throw new Error('Invalid JSON from server.'); }
}

function copyText(id){
  const el = document.getElementById(id);
  const txt = (el.tagName === 'TEXTAREA' ? el.value : el.innerText) || '';
  navigator.clipboard.writeText(txt).then(()=>{ toast('Copied.'); }).catch(()=>{});
}

function toast(msg){
  const s = document.getElementById('status');
  s.innerHTML = escapeHtml(msg);
}

// ========== Local Fallback (no backend) ==========
// Minimal definition-first demo to ensure the UI works even if server fails.
const DICT = {
  'give':'provide to a recipient',
  'me':'the speaker as recipient',
  'a':'one; single instance',
  'an':'one; single instance (before vowel sound)',
  'trivial':'of little difficulty; simple',
  'trivia':'facts of little importance; general knowledge',
  'question':'a request for information',
  'add':'combine quantities to form a sum',
  'and':'join operands or phrases',
  'what':'requests information',
  'is':'copula linking subject and predicate',
  'the':'definite determiner',
  'capital':'principal city of a nation or state',
  'of':'indicates relation or possession'
};

const NUM_WORDS = new Map(Object.entries({
  'zero':0,'one':1,'two':2,'three':3,'four':4,'five':5,'six':6,'seven':7,'eight':8,'nine':9,'ten':10,
  'eleven':11,'twelve':12,'thirteen':13,'fourteen':14,'fifteen':15,'sixteen':16,'seventeen':17,'eighteen':18,'nineteen':19,
  'twenty':20,'thirty':30,'forty':40,'fifty':50,'sixty':60,'seventy':70,'eighty':80,'ninety':90
}));

function wordsToNumber(tokens){
  // simple: supports 0..99
  let sum=0, i=0;
  while(i<tokens.length){
    const w=tokens[i];
    if(NUM_WORDS.has(w)){
      let n = NUM_WORDS.get(w);
      if(i+1<tokens.length && NUM_WORDS.has(tokens[i+1]) && NUM_WORDS.get(tokens[i+1])<10){
        n += NUM_WORDS.get(tokens[++i]);
      }
      sum = sum===0 ? n : sum; // used by calling code
    }
    i++;
  }
  return sum || null;
}

function localAnalyze(text, allowFacts){
  const original = text.trim();
  const tokens = original.toLowerCase().match(/[a-zA-Z]+|\d+/g) || [];
  const defs = tokens.map(t=> `${t}: ${DICT[t]||'[unknown]'}`).join('\n');

  // heuristic intents
  let result = '';

  if(tokens.includes('trivial') && tokens.includes('question')){
    result = 'What is 2 + 2?';
  } else if(tokens.includes('trivia') && tokens.includes('question')){
    result = 'Here is a general-knowledge question: Which ocean is the largest?';
  } else if(tokens[0]==='add'){
    // parse "add twenty three and fifty nine"
    const andIdx = tokens.indexOf('and');
    const left = wordsToNumber(tokens.slice(1, andIdx));
    const right = wordsToNumber(tokens.slice(andIdx+1));
    if(left!=null && right!=null){
      const sum = left + right;
      result = `${left} + ${right} = ${sum}`;
    }
  } else if(tokens.join(' ').includes('capital of')){
    // definition-first: do not answer unless facts allowed
    const maybe = original.match(/capital of\s+([A-Za-z]+)/i);
    const name = maybe ? maybe[1] : '[unknown]';
    if(!allowFacts){
      result = `Recognized request: ask(capital_of("${name}")) — facts disabled.`;
    }
  }

  if(!result){
    result = 'Parsed, but no deterministic definition-first action matched.';
  }

  const mr = { intent: result.startsWith('Recognized') ? 'ask.capital' : 'execute', original };
  return { definitions: defs, result, mr };
}


function formatDefinitions(defs) {
  if (!defs) return '(no definitions)';
  if (Array.isArray(defs)) {
    // AVA (backend) shape: [{token,pos,gloss}, ...]
    return defs.map(d =>
      `${d.token ?? ''}${d.pos ? ` (${d.pos})` : ''} — ${d.gloss ?? ''}`
    ).join('\n');
  }
  if (typeof defs === 'object') {
    // Just in case some other object shape comes back
    return JSON.stringify(defs, null, 2);
  }
  // Local fallback already returns a string
  return String(defs);
}


// ========== UI Logic ==========
const el = {
  input: document.getElementById('userInput'),
  analyze: document.getElementById('analyzeBtn'),
  clear: document.getElementById('clearBtn'),
  defs: document.getElementById('definitions'),
  res: document.getElementById('result'),
  raw: document.getElementById('raw'),
  status: document.getElementById('status'),
  notice: document.getElementById('notice'),
  latency: document.getElementById('latency'),
  facts: document.getElementById('factsToggle'),
  copyDef: document.getElementById('copyDef'),
  copyRes: document.getElementById('copyRes'),
  supLog: document.getElementById('supervisorLog'),
  supMsg: document.getElementById('supervisorMsg'),
  supSend: document.getElementById('supervisorSend'),
  supPropose: document.getElementById('supervisorPropose'),
  supApply: document.getElementById('supervisorApply'),
  supReset: document.getElementById('supervisorReset'),
  supStatus: document.getElementById('supStatus'),
};

el.copyDef.addEventListener('click', ()=>copyText('definitions'));
el.copyRes.addEventListener('click', ()=>copyText('result'));

function supLine(role, text){
  const cls = role === 'user' ? 'u' : 'a';
  const div = document.createElement('div');
  div.innerHTML = `<strong class="${cls}">${role}:</strong> ${escapeHtml(text)}`;
  el.supLog.appendChild(div);
  el.supLog.scrollTop = el.supLog.scrollHeight;
}

el.clear.addEventListener('click', ()=>{
  el.input.value='';
  el.defs.textContent='(waiting…)';
  el.res.textContent='';
  el.raw.textContent='';
  el.status.textContent='';
  el.notice.textContent='';
  el.latency.textContent='';
});

el.analyze.addEventListener('click', async ()=>{
  const txt = el.input.value.trim();
  if(!txt){ toast('Type something to analyze.'); return; }

  el.analyze.disabled = true; el.analyze.textContent='Analyzing…';
  el.status.textContent=''; el.notice.textContent=''; el.raw.textContent='';
  const t0 = performance.now();

  // Prefer backend if available; otherwise use local fallback
  let usedBackend = false, data = null;

  try {
    // Wait for grecaptcha if available
    let token = '';
    <?php if ($site_key): ?>
    await new Promise(r=>{ if(window.grecaptcha && grecaptcha.ready) grecaptcha.ready(r); else r(); });
    token = (window.grecaptcha && grecaptcha.execute) ? await grecaptcha.execute('<?=$site_key?>', {action:'analyze'}) : '';
    <?php endif; ?>

    const resp = await fetch('language_api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ action:'analyze', prompt: txt, allow_facts: !!el.facts.checked, recaptcha: token })
    });

    if(!resp.ok) throw new Error('Server status ' + resp.status);
    data = await parseJsonSafe(resp);

    if(!data || (data.status && data.status!=='ok')){
      throw new Error((data && (data.error||data.message)) || 'Analysis failed');
    }

    usedBackend = true;
  } catch (e){
    console.warn('Backend unavailable, using local fallback. Reason:', e.message);
    data = { status: 'ok',
      ava: { definitions: '', mr: {} },
      gala: { result: '' },
      local: true
    };
    const out = localAnalyze(txt, !!el.facts.checked);
    data.ava.definitions = out.definitions;
    data.ava.mr = out.mr;
    data.gala.result = out.result;
  } finally {
    const ms = Math.max(1, Math.round(performance.now() - t0));
    el.latency.textContent = (usedBackend? 'server':'local') + ' • ' + ms + ' ms';
    el.analyze.disabled = false; el.analyze.textContent='Analyze';
  }

  // Render
  try{
    const defs = data?.ava?.definitions ?? data?.definitions ?? null;
    const res  = data?.gala?.result ?? data?.result ?? '';
    el.defs.textContent = formatDefinitions(defs);
    el.res.textContent  = res || '(no result)';

    const raw = JSON.stringify(data, null, 2);
    el.raw.textContent = raw;

    if(data.local){
      el.notice.innerHTML = '<span class="muted">Local heuristic fallback used (no backend). Supply language_api.php for LLM-powered AVA/GALA.</span>';
    } else {
      el.notice.innerHTML = '<span class="ok">OK</span> backend responded.';
    }
  } catch (e){
    el.notice.innerHTML = '<span class="err">Render error</span> ' + escapeHtml(e.message);
  }
});

el.supSend.addEventListener('click', async ()=>{
  const msg = (el.supMsg.value || '').trim();
  if(!msg){ toast('Type a message for Supervisor.'); return; }
  el.supSend.disabled = true;
  try{
    supLine('user', msg);
    const resp = await fetch('guardian_agent.php?action=chat', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'message=' + encodeURIComponent(msg)
    });
    const data = await parseJsonSafe(resp);
    if(data.status !== 'ok') throw new Error(data.error || 'chat failed');
    supLine('assistant', data.reply);
    el.supStatus.textContent = 'ok • supervisor replied';
    el.supMsg.value = '';
  }catch(e){ el.supStatus.textContent = 'error: ' + e.message; }
  finally{ el.supSend.disabled = false; }
});

el.supPropose.addEventListener('click', async ()=>{
  const msg = (el.supMsg.value || '').trim();
  el.supPropose.disabled = true;
  try{
    if(msg) supLine('user', msg);
    const resp = await fetch('guardian_agent.php?action=chat_patch', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'message=' + encodeURIComponent(msg)
    });
    const data = await parseJsonSafe(resp);
    if(data.status !== 'ok') throw new Error(data.error || 'patch failed');
    el.supStatus.innerHTML = 'Patch proposed and stored. <span class="ok">preview ok</span>. You can now click “Apply Last Patch”.';
  }catch(e){ el.supStatus.textContent = 'error: ' + e.message; }
  finally{ el.supPropose.disabled = false; }
});

el.supApply.addEventListener('click', async ()=>{
  el.supApply.disabled = true;
  try{
    const resp = await fetch('guardian_agent.php?action=apply_last');
    const data = await parseJsonSafe(resp);
    if(data.status !== 'ok') throw new Error(data.error || 'apply failed');
    el.supStatus.innerHTML = '<span class="ok">Patched & tests pass.</span>';
  }catch(e){ el.supStatus.textContent = 'error: ' + e.message; }
  finally{ el.supApply.disabled = false; }
});

el.supReset.addEventListener('click', async ()=>{
  el.supLog.innerHTML = '';
  el.supStatus.textContent = 'cleared.';
  await fetch('guardian_agent.php?action=chat&reset=1&message=' + encodeURIComponent('[reset]'), { method: 'POST' });
});
</script>
</body>
</html>
