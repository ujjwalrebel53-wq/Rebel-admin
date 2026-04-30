<?php
/**
 * fetch_runner.php — VPS-side UIDAI fetch executor
 *
 * AlwaysData (or any host without Playwright) calls this endpoint when
 * the user sends /fetch. This script runs Playwright on the VPS and
 * sends results directly to Telegram.
 *
 * Setup:
 *   1. Place this file in /var/www/html/ on VPS
 *   2. Create /var/www/html/.runner_secret with a random secret string
 *   3. On AlwaysData, create .vps_runner_url with:
 *        http://62.72.30.100/fetch_runner.php
 *   4. On AlwaysData, create .vps_secret with the same secret string
 */

header('Content-Type: application/json');
@set_time_limit(300);

// ─── Secret verification ──────────────────────────────────────────────────────
$secretFile=__DIR__.'/.runner_secret';
$secret=file_exists($secretFile)?trim(file_get_contents($secretFile)):'';
if($secret===''){
    echo json_encode(['ok'=>false,'error'=>'Runner secret not configured. Create .runner_secret file.']);
    exit;
}

$input=json_decode(file_get_contents('php://input'),true);
if(!is_array($input)){echo json_encode(['ok'=>false,'error'=>'Invalid JSON']);exit;}

$reqSecret=trim($input['secret']??'');
if(!hash_equals($secret,$reqSecret)){
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

// ─── Extract params ───────────────────────────────────────────────────────────
$mobile    =trim($input['mobile']??'');
$fullName  =trim($input['full_name']??'');
$chatId    =trim($input['chat_id']??'');
$token     =trim($input['token']??'');
$botId     =trim($input['bot_id']??'');
$tgId      =trim($input['tg_id']??'');
$tgName    =trim($input['tg_name']??'');
$tgUser    =trim($input['tg_username']??'');
$captcha   =trim($input['captcha']??'');
$isResume  =(bool)($input['resume']??false);

if(!$mobile||!$fullName||!$chatId||!$token){
    echo json_encode(['ok'=>false,'error'=>'Missing required params']);exit;
}

define('TG_BASE','https://api.telegram.org/bot');

function tg_send($method,$params,$token){
    $ch=curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>TG_BASE.$token.'/'.$method,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($params),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_TIMEOUT=>30,
    ]);
    $r=curl_exec($ch);curl_close($ch);
    return json_decode($r,true)?:[];
}

function send_photo($chatId,$imagePath,$caption,$token){
    $ch=curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>TG_BASE.$token.'/sendPhoto',
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_POSTFIELDS=>['chat_id'=>$chatId,'caption'=>$caption,'parse_mode'=>'HTML','photo'=>new CURLFile($imagePath,'image/png','cap.png')],
    ]);
    $r=curl_exec($ch);curl_close($ch);
    return json_decode($r,true)?:[];
}

// ─── Python binary detection ───────────────────────────────────────────────────
function getPythonBin():string{
    $env=getenv('REBEL_PYTHON_BIN');
    if($env&&trim($env)!=='')return trim($env);
    $cfg=__DIR__.'/.python_bin';
    if(file_exists($cfg)){$p=trim(file_get_contents($cfg));if($p!=='')return $p;}
    foreach(['/usr/bin/python3','/usr/local/bin/python3','/usr/bin/python'] as $p){
        if(file_exists($p)&&is_executable($p))return $p;
    }
    return 'python3';
}

define('PYTHON_BIN',getPythonBin());

function runPythonScript(string $scrFile,int $timeout=180):void{
    @set_time_limit($timeout+30);
    $dfuncs=array_map('trim',explode(',',ini_get('disable_functions')));
    if(!in_array('exec',$dfuncs)&&function_exists('exec')){
        $tbins=['/usr/bin/timeout','/bin/timeout'];
        $tb='';foreach($tbins as $t){if(file_exists($t)){$tb=$t;break;}}
        if($tb){@exec($tb.' '.escapeshellarg((string)$timeout).' '.PYTHON_BIN.' '.escapeshellarg($scrFile).' 2>/dev/null');}
        else{@exec(PYTHON_BIN.' '.escapeshellarg($scrFile).' 2>/dev/null');}
        return;
    }
    if(!in_array('proc_open',$dfuncs)&&function_exists('proc_open')){
        $desc=[['pipe','r'],['pipe','w'],['pipe','w']];
        $proc=@proc_open(PYTHON_BIN.' '.escapeshellarg($scrFile),$desc,$pipes);
        if($proc){fclose($pipes[0]);$start=time();while(proc_get_status($proc)['running']){if(time()-$start>=$timeout){proc_terminate($proc,9);break;}usleep(250000);}fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);}
    }
}

// ─── Session / result files ───────────────────────────────────────────────────
$safeUid=preg_replace('/\W/','_',$tgId?:$chatId);
$sessFile=sys_get_temp_dir().'/uidaifetch_sess_'.$safeUid.'.json';
$resFile =sys_get_temp_dir().'/uidaifetch_res_'.$safeUid.'.json';
if(!$isResume&&file_exists($resFile))@unlink($resFile);

// ─── Build vars ───────────────────────────────────────────────────────────────
$vars=['mobile'=>$mobile,'full_name'=>$fullName,'tg_name'=>$tgName,'tg_id'=>$tgId,'tg_username'=>$tgUser,'query'=>$mobile.' '.$fullName];

$from=0;
if($isResume&&$captcha!==''){
    $sess=file_exists($sessFile)?json_decode(file_get_contents($sessFile),true):[];
    $from=(int)($sess['resume_from']??0);
    $vars['captcha']=$captcha;
    foreach($sess['vars']??[] as $k=>$v)if(!isset($vars[$k]))$vars[$k]=$v;
}

// ─── Browser steps ────────────────────────────────────────────────────────────
$steps=[
    ['type'=>'open','value'=>'https://myaadhaar.uidai.gov.in/retrieve-eid-uid','stop_on_error'=>true],
    ['type'=>'wait_load','value'=>'networkidle','timeout'=>'25'],
    ['type'=>'wait_element','selector'=>'input[type="radio"][value="M"],#mobileRadio,input[name*="searchBy"]','timeout'=>'15','stop_on_error'=>false],
    ['type'=>'js_eval','value'=>'(()=>{var r=document.querySelector(\'input[type="radio"][value="M"],input[type="radio"][id*="mobile"],input[name*="searchBy"][value="M"]\');if(r){r.click();return "clicked";}return "not_found";})()','var_name'=>'radio_clicked'],
    ['type'=>'wait_element','selector'=>'input[formcontrolname="fullName"],input[placeholder*="Full Name"],input[placeholder*="full name"],#fullName,input[name*="fullName"]','timeout'=>'15','stop_on_error'=>true],
    ['type'=>'fill','selector'=>'input[formcontrolname="fullName"],input[placeholder*="Full Name"],input[placeholder*="full name"],#fullName,input[name*="fullName"]','value'=>'{full_name}'],
    ['type'=>'fill','selector'=>'input[formcontrolname="mobileNo"],input[formcontrolname="mobile"],input[placeholder*="Mobile"],input[placeholder*="mobile"],#mobileNo,input[name*="mobile"]','value'=>'{mobile}'],
    ['type'=>'screenshot','caption'=>'Form filled','send_ss'=>false,'crop_x'=>'','crop_y'=>'','crop_w'=>'','crop_h'=>''],
    ['type'=>'ask_captcha','caption'=>'🔐 <b>UIDAI Captcha</b>\n\nType the security code shown in the screenshot and reply:','crop_x'=>'','crop_y'=>'','crop_w'=>'','crop_h'=>'','var_name'=>'captcha'],
    ['type'=>'fill','selector'=>'input[formcontrolname="captchaText"],input[placeholder*="aptcha"],input[placeholder*="ecurity"],input[name*="captcha"],#captchaText','value'=>'{captcha}'],
    ['type'=>'click','selector'=>'button[type="submit"],.send-otp-btn,.btn-send-otp,.submit-btn','stop_on_error'=>false],
    ['type'=>'wait_load','value'=>'networkidle','timeout'=>'20'],
    ['type'=>'screenshot','caption'=>'📋 UIDAI Result','send_ss'=>true,'crop_x'=>'','crop_y'=>'','crop_w'=>'','crop_h'=>''],
    ['type'=>'get_text','selector'=>'.success-message,.error-message,.alert,.result-msg,mat-card p,.otp-sent-msg,.ng-star-inserted h3,h3,.info-box,p.text-center','var_name'=>'result'],
];

// ─── Build Python script inline (same as index.php buildBrowserScript) ────────
$stJ=json_encode($steps,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$vJ =json_encode($vars, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$sf =addslashes($sessFile);
$rf =addslashes($resFile);
$pyBin=PYTHON_BIN;

$script=<<<PY
import sys,json,os,base64,time,random,re,tempfile
_home=os.path.expanduser('~')
for _sp in [
    '/usr/local/lib/python3.12/dist-packages',
    '/usr/local/lib/python3/dist-packages',
    os.path.join(_home,'.local','lib','python'+'.'.join(map(str,sys.version_info[:2])),'site-packages'),
    os.path.join(_home,'.local','lib','python'+str(sys.version_info[0]),'site-packages'),
]:
    if _sp not in sys.path and os.path.isdir(_sp): sys.path.insert(0,_sp)
SF='{$sf}'; RF='{$rf}'
R={'steps':[],'status':'done','vars':{}}
V={$vJ}
FROM={$from}
if os.path.exists(SF):
    try: V.update(json.load(open(SF)).get('vars',{}))
    except: pass
def av(t):
    t=str(t)
    for k,v in V.items(): t=t.replace('{'+k+'}',str(v))
    return t
_UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
_ARGS=['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage','--disable-blink-features=AutomationControlled','--window-size=1920,1080','--disable-gpu','--lang=en-IN']
P=None;B=None;PW=False;_p=None;_CTX=None
try:
    from playwright.sync_api import sync_playwright
    _p=sync_playwright().__enter__();PW=True
except: pass
if PW:
    for _la in [{}]:
        try: B=_p.chromium.launch(headless=True,args=_ARGS);break
        except Exception as _e: pass
    if not B: PW=False
if PW:
    _CTX=B.new_context(user_agent=_UA,viewport={'width':1920,'height':1080},locale='en-IN',timezone_id='Asia/Kolkata')
    _CTX.add_init_script("Object.defineProperty(navigator,'webdriver',{get:()=>undefined})")
    P=_CTX.new_page()
if not PW:
    R['status']='error';R['error']='Playwright not installed. Run: pip3 install playwright && playwright install chromium'
    open(RF,'w').write(json.dumps(R));sys.exit(1)
def _goto(url):
    try: P.goto(url,wait_until='commit',timeout=60000)
    except: pass
    try: P.wait_for_load_state('domcontentloaded',timeout=30000)
    except: pass
    time.sleep(3)
def _ss(crop=None):
    f=tempfile.mktemp(suffix='.png')
    if crop and all(crop): P.screenshot(path=f,clip={'x':float(crop[0]),'y':float(crop[1]),'width':float(crop[2]),'height':float(crop[3])})
    else: P.screenshot(path=f,full_page=False)
    d=base64.b64encode(open(f,'rb').read()).decode();os.unlink(f);return d
steps={$stJ}
for i,st in enumerate(steps):
    if i<FROM: continue
    t=st.get('type','open')
    try:
        if t=='open': _goto(av(st.get('value','')))
        elif t=='wait_load':
            state=av(st.get('value','networkidle'));to=int(float(st.get('timeout',15))*1000)
            try: P.wait_for_load_state(state,timeout=to)
            except: pass
        elif t=='wait_element':
            P.wait_for_selector(av(st.get('selector','')),timeout=int(float(st.get('timeout',10))*1000))
        elif t=='fill': P.fill(av(st.get('selector','')),av(st.get('value','')))
        elif t=='click':
            try: P.locator(av(st.get('selector',''))).first.click()
            except: pass
        elif t=='js_eval':
            val=P.evaluate(av(st.get('value','')));V[st.get('var_name','r')]=str(val) if val else ''
            R['steps'].append({'i':i,'type':t,'status':'ok','value':str(val)});continue
        elif t=='get_text':
            try: txt=P.locator(av(st.get('selector',''))).first.inner_text();V[st.get('var_name','result')]=txt
            except: pass
            R['steps'].append({'i':i,'type':t,'status':'ok','value':V.get(st.get('var_name','result'),'')});continue
        elif t=='screenshot':
            crop=[st.get('crop_x'),st.get('crop_y'),st.get('crop_w'),st.get('crop_h')]
            b64=_ss(crop)
            R['steps'].append({'i':i,'type':t,'status':'ok','image':b64,'send':bool(st.get('send_ss')),'caption':av(st.get('caption',''))});continue
        elif t=='ask_captcha':
            crop=[st.get('crop_x'),st.get('crop_y'),st.get('crop_w'),st.get('crop_h')]
            b64=_ss(crop)
            sd={'url':P.url,'vars':V,'resume_from':i+1,'captcha_var':'captcha'}
            if _CTX: sd['storage']=_CTX.storage_state()
            open(SF,'w').write(json.dumps(sd))
            R['status']='captcha_needed';R['captcha_image']=b64
            R['resume_from']=i+1;R['captcha_var']='captcha'
            R['captcha_prompt']=av(st.get('caption','🔐 Reply with captcha:'))
            R['steps'].append({'i':i,'type':t,'status':'paused'});break
        R['steps'].append({'i':i,'type':t,'status':'ok'})
    except Exception as e:
        R['steps'].append({'i':i,'type':t,'status':'error','error':str(e)})
        if st.get('stop_on_error'): R['status']='error';R['error']=str(e);break
R['vars']=V
try:
    if _CTX: _CTX.close()
    if B: B.close()
    if _p:
        try: _p.__exit__(None,None,None)
        except:
            try: _p.stop()
            except: pass
except: pass
open(RF,'w').write(json.dumps(R))
PY;

// ─── Run script ───────────────────────────────────────────────────────────────
$scrFile=sys_get_temp_dir().'/uidaifetch_sc_'.$safeUid.'.py';
file_put_contents($scrFile,$script);
runPythonScript($scrFile,180);
@unlink($scrFile);

// ─── Handle result ────────────────────────────────────────────────────────────
$res=file_exists($resFile)?json_decode(file_get_contents($resFile),true):null;

if(!$res){
    tg_send('sendMessage',['chat_id'=>$chatId,'text'=>"❌ <b>UIDAI Fetch Failed!</b>\n\nBrowser script produced no output.\n\nFix: <code>pip3 install playwright && playwright install chromium</code>",'parse_mode'=>'HTML'],$token);
    echo json_encode(['ok'=>false,'error'=>'no result']);exit;
}

if(($res['status']??'')==='error'){
    $err=trim($res['error']??'Unknown error');
    tg_send('sendMessage',['chat_id'=>$chatId,'text'=>"❌ <b>Error:</b>\n<code>".htmlspecialchars(mb_substr($err,0,400),ENT_QUOTES,'UTF-8')."</code>\n\n<b>Try again:</b> <code>/fetch {$mobile} {$fullName}</code>",'parse_mode'=>'HTML'],$token);
    echo json_encode(['ok'=>false,'error'=>$err]);exit;
}

if(($res['status']??'')==='captcha_needed'){
    $b64=$res['captcha_image']??'';
    $prompt=$res['captcha_prompt']??'🔐 Type the captcha shown in the screenshot and reply:';
    // Save session on VPS
    $sessData=['resume_from'=>$res['resume_from'],'vars'=>$res['vars']??[]];
    file_put_contents($sessFile,json_encode($sessData,JSON_UNESCAPED_UNICODE),LOCK_EX);
    // Tell AlwaysData to set active_page for this user
    // (We do it via a special Telegram message — AlwaysData index.php will catch the reply)
    // Actually we need AlwaysData to know about the session — signal via Telegram
    if($b64){
        $tmp=tempnam(sys_get_temp_dir(),'ucap_').'.png';
        file_put_contents($tmp,base64_decode($b64));
        send_photo($chatId,$tmp,$prompt,$token);
        @unlink($tmp);
    } else {
        tg_send('sendMessage',['chat_id'=>$chatId,'text'=>$prompt,'parse_mode'=>'HTML'],$token);
    }
    // Signal AlwaysData to set active_page via inline callback (simpler: just send a special message)
    // The index.php on AlwaysData will handle the reply when user types captcha
    // We need to tell AlwaysData this user is in captcha state
    // Use a hidden Telegram callback to set state
    echo json_encode(['ok'=>true,'status'=>'captcha_sent']);exit;
}

// ─── Send screenshots ─────────────────────────────────────────────────────────
$screenshotSent=false;
foreach($res['steps']??[] as $step){
    if(($step['type']??'')==='screenshot'&&!empty($step['send'])&&!empty($step['image'])){
        $tmp=tempnam(sys_get_temp_dir(),'uss_').'.png';
        file_put_contents($tmp,base64_decode($step['image']));
        send_photo($chatId,$tmp,$step['caption']??'UIDAI Result',$token);
        @unlink($tmp);
        $screenshotSent=true;
    }
}

// ─── Send text result ─────────────────────────────────────────────────────────
$allVars=array_merge($vars,$res['vars']??[]);
$result=trim($allVars['result']??'');
$resultLower=strtolower($result);
$failed=str_contains($resultLower,'invalid')||str_contains($resultLower,'not found')||str_contains($resultLower,'no record')||str_contains($resultLower,'wrong captcha')||str_contains($resultLower,'incorrect');

if($result!==''){
    $emoji=$failed?'⚠️':'✅';
    tg_send('sendMessage',['chat_id'=>$chatId,'text'=>"{$emoji} <b>UIDAI Result:</b>\n\n".htmlspecialchars($result,ENT_NOQUOTES,'UTF-8').($failed?"\n\n<i>Try again: <code>/fetch {$mobile} {$fullName}</code></i>":''),'parse_mode'=>'HTML'],$token);
} elseif(!$screenshotSent){
    tg_send('sendMessage',['chat_id'=>$chatId,'text'=>"⚠️ <b>No result received.</b>\n\nName or mobile may not match UIDAI records.\n\n<b>Try again:</b> <code>/fetch {$mobile} {$fullName}</code>",'parse_mode'=>'HTML'],$token);
}

@unlink($resFile);
echo json_encode(['ok'=>true,'status'=>'done']);
