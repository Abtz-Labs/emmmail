default:
    @just --list

# Preset providers: name -> "IMAP_HOST IMAP_PORT SMTP_HOST SMTP_PORT"
presets := '{
    "gmail":   "imap.gmail.com 993 smtp.gmail.com 587",
    "yahoo":   "imap.mail.yahoo.com 993 smtp.mail.yahoo.com 587",
    "outlook": "outlook.office365.com 993 outlook.office365.com 587",
    "migadu":  "imap.migadu.com 993 smtp.migadu.com 587",
    "icloud":  "imap.mail.me.com 993 smtp.mail.me.com 587"
}'

[doc('Start dev server on port 2525 (foreground)')]
start *args:
    #!/usr/bin/env bash
    port="2525"
    mode="foreground"
    for arg in {{args}}; do
        if [ "$arg" = "background" ]; then
            mode="background"
        elif [[ "$arg" =~ ^[0-9]+$ ]]; then
            port="$arg"
        fi
    done
    if [ -f .server.pid ] || [ "$(lsof -ti :$port | head -1)" != "" ]; then
        echo "Server already running."
        exit 0
    fi
    if [ "$mode" = "foreground" ]; then
        echo "Starting on http://localhost:$port (Ctrl+C to stop)"
        php -S localhost:$port index.php
    else
        php -S localhost:$port index.php > /dev/null 2>&1 &
        echo $! > .server.pid
        echo "Started on http://localhost:$port (PID: $(cat .server.pid))"
    fi

[doc('Stop dev server')]
stop:
    @if [ -f .server.pid ]; then \
        kill $(cat .server.pid) 2>/dev/null && echo "Server stopped" || echo "Server not running"; \
        rm -f .server.pid; \
    else \
        echo "Server not running"; \
    fi

[doc('Run all test suites. With PRESET EMAIL [PASSWORD], runs live-server smoke tests.')]
test *args:
    #!/usr/bin/env bash
    count=$(echo {{args}} | awk '{print NF}')
    if [ "$count" -eq 0 ]; then
        php tests/run.php
        exit $?
    fi
    preset=$(echo {{args}} | awk '{print $1}')
    email=$(echo {{args}} | awk '{print $2}')
    export EMMMAIL_SMOKE_EMAIL="$email"
    if [ "$count" -eq 3 ]; then
        export EMMMAIL_SMOKE_PASSWORD=$(echo {{args}} | awk '{print $3}')
    elif [ "$count" -eq 2 ]; then
        read -s -p "Password: " EMMMAIL_SMOKE_PASSWORD
        echo
        export EMMMAIL_SMOKE_PASSWORD
    else
        echo "Usage: just test [PRESET EMAIL [PASSWORD]]"
        echo "  PRESET    - provider name (gmail, yahoo, outlook, migadu, icloud)"
        echo "  EMAIL     - email address"
        echo "  PASSWORD  - account password (optional, prompted if omitted)"
        echo ""
        echo "Without arguments, runs every suite in tests/ (php tests/run.php)."
        echo "Run one suite with: php tests/unit.php | imap.php | smoke.php"
        echo "Live-server smoke tests are skipped unless PRESET and EMAIL are given."
        exit 1
    fi
    spec=$(echo '{{presets}}' | python3 -c "import sys,json;print(json.load(sys.stdin).get('$preset',''))")
    if [ -z "$spec" ]; then
        echo "Unknown preset '$preset'. Valid: gmail, yahoo, outlook, migadu, icloud."
        exit 1
    fi
    read -r EMMMAIL_SMOKE_IMAP_HOST EMMMAIL_SMOKE_IMAP_PORT EMMMAIL_SMOKE_SMTP_HOST EMMMAIL_SMOKE_SMTP_PORT <<< "$spec"
    export EMMMAIL_SMOKE_IMAP_HOST EMMMAIL_SMOKE_IMAP_PORT EMMMAIL_SMOKE_SMTP_HOST EMMMAIL_SMOKE_SMTP_PORT
    php tests/smoke.php
