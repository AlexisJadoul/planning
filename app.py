import hmac
import json
import os
import secrets
import threading
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo
from flask import Flask, jsonify, redirect, render_template, request, session
from werkzeug.security import check_password_hash, generate_password_hash
from parser import parse_excel

DATA = Path(os.environ.get('DATA_DIR', Path(__file__).parent / 'data'))
DATA.mkdir(parents=True, exist_ok=True)
key_file = DATA / 'session.key'
if not key_file.exists():
    key_file.write_text(secrets.token_hex(32))
    key_file.chmod(0o600)
password = os.environ.get('ADMIN_PASSWORD')
if not password:
    password_file = DATA / 'admin-password.txt'
    if not password_file.exists():
        password_file.write_text(secrets.token_urlsafe(18))
        password_file.chmod(0o600)
    password = password_file.read_text().strip()
password_hash = generate_password_hash(password)
app = Flask(__name__)
app.config.update(SECRET_KEY=key_file.read_text(), MAX_CONTENT_LENGTH=8 * 1024 * 1024,
                  SESSION_COOKIE_HTTPONLY=True, SESSION_COOKIE_SAMESITE='Strict',
                  SESSION_COOKIE_SECURE=os.environ.get('COOKIE_SECURE') == '1',
                  PERMANENT_SESSION_LIFETIME=timedelta(hours=8))
lock = threading.Lock()
state_file = DATA / 'planning.json'
attempts = {}


def state():
    with lock:
        return json.loads(state_file.read_text()) if state_file.exists() else {'weeks': [], 'mode': 'auto', 'selected': None, 'revision': None}


def save(value):
    with lock:
        temp = DATA / 'planning.tmp'
        temp.write_text(json.dumps(value, ensure_ascii=False))
        temp.replace(state_file)


@app.get('/')
def screen():
    return render_template('screen.html')


@app.get('/api/planning')
def planning():
    value = state()
    today = datetime.now(ZoneInfo('Europe/Paris')).date()
    year, week, _ = today.isocalendar()
    selected = value.get('selected') if value.get('mode') == 'manual' else f'{year}-W{week:02}'
    active = next((w for w in value['weeks'] if w['id'] == selected), None)
    response = jsonify(week=active, revision=value.get('revision'), updated=value.get('updated'), today=today.isoformat(), mode=value['mode'])
    response.headers['Cache-Control'] = 'no-store'
    return response


@app.route('/admin', methods=['GET', 'POST'])
def admin():
    session.setdefault('csrf', secrets.token_hex(24))
    error, message = None, None
    if request.method == 'POST':
        if not hmac.compare_digest(session['csrf'], request.form.get('csrf', '')):
            return 'Requête expirée. Rechargez la page.', 400
        action = request.form.get('action')
        if action == 'login':
            import time
            now = time.monotonic()
            ip = request.remote_addr
            recent = [t for t in attempts.get(ip, []) if now - t < 300]
            attempts[ip] = recent
            if len(recent) >= 10:
                error = 'Trop de tentatives. Réessayez dans 5 minutes.'
            elif check_password_hash(password_hash, request.form.get('password', '')):
                session['admin'] = True
                session.permanent = True
                attempts.pop(ip, None)
                return redirect('/admin')
            else:
                recent.append(now)
                error = 'Mot de passe incorrect.'
        elif not session.get('admin'):
            return redirect('/admin')
        elif action == 'logout':
            session.clear()
            return redirect('/admin')
        elif action == 'upload':
            upload = request.files.get('file')
            try:
                if not upload or not upload.filename.lower().endswith('.xlsx'):
                    raise ValueError('Sélectionnez un fichier .xlsx.')
                content = upload.read()
                from zipfile import ZipFile
                from io import BytesIO
                with ZipFile(BytesIO(content)) as archive:
                    if sum(info.file_size for info in archive.infolist()) > 64 * 1024 * 1024:
                        raise ValueError('Classeur décompressé trop volumineux.')
                weeks = parse_excel(content)
                value = state()
                value.update(weeks=weeks, revision=secrets.token_hex(8), updated=datetime.now(ZoneInfo('Europe/Paris')).isoformat())
                if value.get('selected') not in {w['id'] for w in weeks}:
                    value.update(mode='auto', selected=None)
                save(value)
                message = f'{len(weeks)} semaines importées. Les écrans se mettent à jour sous 5 secondes.'
            except Exception:
                error = 'Import refusé : fichier invalide, trop volumineux ou structure incompatible. Le planning précédent est conservé.'
        elif action == 'select':
            value = state()
            selection = request.form.get('week')
            if selection == 'auto' or selection in {w['id'] for w in value['weeks']}:
                value.update(mode='auto' if selection == 'auto' else 'manual', selected=None if selection == 'auto' else selection)
                save(value)
                message = 'Affichage mis à jour.'
    return render_template('admin.html', authenticated=session.get('admin'), csrf=session['csrf'], data=state(), error=error, message=message)


@app.errorhandler(413)
def too_large(error):
    return 'Fichier trop volumineux (maximum 8 Mo).', 413


if __name__ == '__main__':
    from werkzeug.serving import run_simple
    run_simple(os.environ.get('HOST', '0.0.0.0'), int(os.environ.get('PORT', '8000')), app, threaded=True)
