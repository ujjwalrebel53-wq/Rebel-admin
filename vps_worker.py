#!/usr/bin/env python3
"""
vps_worker.py — VPS-side Telegram polling worker for UIDAI /fetch

Setup:
  1. Copy this file to VPS: /var/www/html/vps_worker.py
  2. Create /var/www/html/.worker_bot_token with your bot token
  3. Create /var/www/html/.worker_channel_id with your group chat ID
  4. Run as service: python3 /var/www/html/vps_worker.py

Or install as systemd service (recommended):
  cp /var/www/html/vps_worker.py /usr/local/bin/
  # Create service file as shown in setup instructions
"""

import sys, os, json, time, base64, tempfile, requests, logging

# Add system playwright path
for sp in ['/usr/local/lib/python3.12/dist-packages','/usr/local/lib/python3/dist-packages']:
    if sp not in sys.path and os.path.isdir(sp):
        sys.path.insert(0, sp)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s %(levelname)s %(message)s',
    handlers=[logging.StreamHandler(), logging.FileHandler('/tmp/vps_worker.log')]
)
log = logging.getLogger('vps_worker')

# ── Config ────────────────────────────────────────────────────────────────────
BASE_DIR = os.path.dirname(os.path.abspath(__file__))

def read_file(name):
    p = os.path.join(BASE_DIR, name)
    return open(p).read().strip() if os.path.exists(p) else ''

BOT_TOKEN    = read_file('.worker_bot_token') or os.environ.get('WORKER_BOT_TOKEN','')
CHANNEL_ID   = read_file('.worker_channel_id') or os.environ.get('WORKER_CHANNEL_ID','')
TG_API       = f'https://api.telegram.org/bot{BOT_TOKEN}'
OFFSET_FILE  = '/tmp/vps_worker_offset.txt'

if not BOT_TOKEN or not CHANNEL_ID:
    log.error('Missing .worker_bot_token or .worker_channel_id file!')
    sys.exit(1)

log.info(f'Worker started. Watching channel: {CHANNEL_ID}')

# ── Telegram helpers ──────────────────────────────────────────────────────────
def tg(method, params=None, files=None):
    try:
        url = f'{TG_API}/{method}'
        if files:
            r = requests.post(url, data=params, files=files, timeout=30)
        else:
            r = requests.post(url, json=params, timeout=30)
        return r.json()
    except Exception as e:
        log.error(f'tg error: {e}')
        return {}

def send_msg(chat_id, text):
    return tg('sendMessage', {'chat_id': chat_id, 'text': text, 'parse_mode': 'HTML'})

def send_photo(chat_id, image_b64, caption=''):
    try:
        img_data = base64.b64decode(image_b64)
        tmp = tempfile.mktemp(suffix='.png')
        with open(tmp, 'wb') as f: f.write(img_data)
        with open(tmp, 'rb') as f:
            r = tg('sendPhoto', {'chat_id': chat_id, 'caption': caption, 'parse_mode': 'HTML'},
                   files={'photo': ('cap.png', f, 'image/png')})
        os.unlink(tmp)
        return r
    except Exception as e:
        log.error(f'send_photo error: {e}')
        return {}

# ── UIDAI Browser automation ──────────────────────────────────────────────────
def run_uidai_fetch(task):
    mobile    = task.get('mobile','')
    full_name = task.get('full_name','')
    chat_id   = task.get('chat_id','')
    token     = task.get('token','')
    tg_id     = task.get('tg_id','')
    captcha   = task.get('captcha','')
    is_resume = task.get('action') == 'fetch_captcha'

    safe_uid  = ''.join(c if c.isalnum() else '_' for c in str(tg_id or chat_id))
    sess_file = f'/tmp/uidaifetch_sess_{safe_uid}.json'
    res_file  = f'/tmp/uidaifetch_res_{safe_uid}.json'

    log.info(f'Processing fetch: mobile={mobile} name={full_name} resume={is_resume}')

    try:
        from playwright.sync_api import sync_playwright

        p = sync_playwright().start()
        browser = p.chromium.launch(
            headless=True,
            args=['--no-sandbox','--disable-setuid-sandbox','--disable-dev-shm-usage',
                  '--disable-gpu','--disable-blink-features=AutomationControlled',
                  '--window-size=1920,1080','--lang=en-IN']
        )

        # Session restore or new context
        from_step = 0
        vars_data = {'mobile': mobile, 'full_name': full_name, 'captcha': captcha,
                     'tg_id': tg_id, 'tg_name': task.get('tg_name',''),
                     'tg_username': task.get('tg_username','')}

        storage_state = None
        if is_resume and os.path.exists(sess_file):
            try:
                sess = json.load(open(sess_file))
                from_step = sess.get('resume_from', 0)
                storage_state = sess.get('storage')
                vars_data.update(sess.get('vars', {}))
                vars_data['captcha'] = captcha
                log.info(f'Resuming from step {from_step}')
            except: pass

        ctx_opts = dict(
            user_agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
            viewport={'width': 1920, 'height': 1080},
            locale='en-IN',
            timezone_id='Asia/Kolkata'
        )
        if storage_state:
            ctx_opts['storage_state'] = storage_state

        ctx  = browser.new_context(**ctx_opts)
        ctx.add_init_script("Object.defineProperty(navigator,'webdriver',{get:()=>undefined})")
        page = ctx.new_page()

        def av(t):
            for k, v in vars_data.items():
                t = t.replace('{'+k+'}', str(v))
            return t

        def screenshot(crop=None):
            tmp = tempfile.mktemp(suffix='.png')
            page.screenshot(path=tmp, full_page=False)
            data = base64.b64encode(open(tmp,'rb').read()).decode()
            os.unlink(tmp)
            return data

        steps = [
            {'type':'open','value':'https://myaadhaar.uidai.gov.in/retrieve-eid-uid'},
            {'type':'wait','value':'5'},
            {'type':'js_click_mobile_radio'},
            {'type':'fill_name'},
            {'type':'fill_mobile'},
            {'type':'ask_captcha'},
            {'type':'fill_captcha'},
            {'type':'submit'},
            {'type':'wait','value':'5'},
            {'type':'result_screenshot'},
            {'type':'get_result'},
        ]

        captcha_sent = False
        result_text  = ''

        for i, step in enumerate(steps):
            if i < from_step: continue
            stype = step['type']
            log.info(f'Step {i}: {stype}')

            try:
                if stype == 'open':
                    try:
                        page.goto(av(step['value']), wait_until='commit', timeout=60000)
                        time.sleep(5)
                    except: time.sleep(3)

                elif stype == 'wait':
                    time.sleep(float(step.get('value','2')))

                elif stype == 'js_click_mobile_radio':
                    try:
                        page.evaluate("""
                            var r = document.querySelector('input[type="radio"][value="M"],input[type="radio"][id*="mobile"]');
                            if(r) r.click();
                        """)
                        time.sleep(1)
                    except: pass

                elif stype == 'fill_name':
                    sel = 'input[formcontrolname="fullName"],input[placeholder*="Full Name"],input[placeholder*="full name"],#fullName'
                    try:
                        page.wait_for_selector(sel, timeout=15000)
                        page.fill(sel, full_name)
                        time.sleep(0.5)
                    except Exception as e:
                        log.error(f'fill_name failed: {e}')
                        send_msg(chat_id, f'❌ Could not find name field on UIDAI page.\n\n<b>Try again:</b> <code>/fetch {mobile} {full_name}</code>')
                        break

                elif stype == 'fill_mobile':
                    sel = 'input[formcontrolname="mobileNo"],input[formcontrolname="mobile"],input[placeholder*="Mobile"],input[placeholder*="mobile"],#mobileNo'
                    try:
                        page.fill(sel, mobile)
                        time.sleep(0.5)
                    except Exception as e:
                        log.error(f'fill_mobile failed: {e}')

                elif stype == 'ask_captcha':
                    # Take screenshot and send to user
                    b64 = screenshot()
                    # Save session
                    sess_data = {
                        'resume_from': i + 1,
                        'vars': vars_data,
                        'storage': ctx.storage_state()
                    }
                    with open(sess_file, 'w') as f: json.dump(sess_data, f)
                    # Send captcha to user
                    send_photo(chat_id, b64, '🔐 <b>UIDAI Captcha</b>\n\nType the security code shown in the screenshot and reply:')
                    captcha_sent = True
                    log.info('Captcha sent to user, waiting for reply...')
                    break

                elif stype == 'fill_captcha':
                    sel = 'input[formcontrolname="captchaText"],input[placeholder*="aptcha"],input[placeholder*="ecurity"],input[name*="captcha"],#captchaText'
                    try:
                        page.fill(sel, captcha)
                        time.sleep(0.5)
                    except Exception as e:
                        log.error(f'fill_captcha failed: {e}')

                elif stype == 'submit':
                    sel = 'button[type="submit"],.send-otp-btn,.btn-send-otp,.submit-btn'
                    try:
                        page.locator(sel).first.click()
                        time.sleep(3)
                    except Exception as e:
                        log.error(f'submit failed: {e}')

                elif stype == 'result_screenshot':
                    b64 = screenshot()
                    send_photo(chat_id, b64, '📋 UIDAI Result')

                elif stype == 'get_result':
                    sel = '.success-message,.error-message,.alert,.result-msg,mat-card p,.otp-sent-msg,h3,.info-box'
                    try:
                        result_text = page.locator(sel).first.inner_text()
                    except: pass

            except Exception as e:
                log.error(f'Step {i} {stype} error: {e}')

        ctx.close()
        browser.close()
        try: p.stop()
        except: pass

        if not captcha_sent and result_text:
            rl = result_text.lower()
            failed = any(x in rl for x in ['invalid','not found','no record','wrong captcha','incorrect'])
            emoji = '⚠️' if failed else '✅'
            send_msg(chat_id, f'{emoji} <b>UIDAI Result:</b>\n\n{result_text}' +
                     (f'\n\n<i>Try again: <code>/fetch {mobile} {full_name}</code></i>' if failed else ''))
        elif not captcha_sent:
            send_msg(chat_id, f'⚠️ <b>No result received.</b>\n\nName or mobile may not match UIDAI records.\n\n<b>Try again:</b> <code>/fetch {mobile} {full_name}</code>')

    except ImportError:
        send_msg(chat_id, '❌ Playwright not installed!\n\nRun: <code>pip3 install playwright && playwright install chromium</code>')
    except Exception as e:
        log.error(f'run_uidai_fetch error: {e}')
        send_msg(chat_id, f'❌ <b>Error:</b>\n<code>{str(e)[:300]}</code>\n\n<b>Try again:</b> <code>/fetch {mobile} {full_name}</code>')

# ── Main polling loop ─────────────────────────────────────────────────────────
def get_offset():
    try: return int(open(OFFSET_FILE).read().strip())
    except: return 0

def save_offset(offset):
    with open(OFFSET_FILE,'w') as f: f.write(str(offset))

def main():
    offset = get_offset()
    log.info('Polling started...')
    while True:
        try:
            r = tg('getUpdates', {'offset': offset, 'timeout': 30, 'allowed_updates': ['message']})
            updates = r.get('result', [])
            for upd in updates:
                offset = upd['update_id'] + 1
                save_offset(offset)
                msg = upd.get('message', {})
                text = msg.get('text','')
                chat_id_upd = str(msg.get('chat',{}).get('id',''))

                # Only process messages from our worker channel
                if chat_id_upd != str(CHANNEL_ID): continue
                if not text.startswith('__TASK__'): continue

                try:
                    task = json.loads(text[8:])  # Remove __TASK__ prefix
                    log.info(f"New task: action={task.get('action')} mobile={task.get('mobile')}")
                    run_uidai_fetch(task)
                except Exception as e:
                    log.error(f'Task parse/run error: {e}')

        except KeyboardInterrupt:
            log.info('Worker stopped.')
            break
        except Exception as e:
            log.error(f'Polling error: {e}')
            time.sleep(5)

if __name__ == '__main__':
    main()
